<?php

namespace App\Http\Controllers;

use App\Models\Admin\Company;
use App\Models\CashRegisterSession;
use App\Models\InventoryTransfer;
use App\Models\Operations\ProductionOrder;
use App\Models\Sale;
use App\Models\User;
use App\Reports\ProductionSheet;
use App\Services\CashRegisterSessionService;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use App\Support\ThermalPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * Documentos para imprimir:
 *  - Ticket de venta (rollo de 58/80 mm).
 *  - Corte de turno de caja (rollo de 58/80 mm).
 *  - Remisión de transferencia (carta), la hoja que viaja con la mercancía.
 *  - Hoja de producción (carta), la que se lleva a la planta.
 *
 * Como los demás PDF, la app pide primero una URL firmada (con el token) y
 * después la abre sin token: el company_id va dentro de la firma y se
 * vuelve a comprobar al generar el documento.
 */
class PrintController extends Controller
{
    private const URL_TTL_MINUTES = 30;

    public function __construct(private readonly CashRegisterSessionService $sessions) {}

    // ─── Ticket de venta ─────────────────────────────────────────────────────

    public function saleTicketUrl(Request $request, int $id): JsonResponse
    {
        $sale = Sale::where('company_id', CurrentCompany::id())->find($id);
        if (!$sale) {
            return $this->notFound('Venta no encontrada.');
        }

        // Quien cobró la venta puede reimprimir su ticket aunque no tenga
        // permiso de consultar ventas; fuera de eso hace falta sales_read.
        $own = $sale->user_id === Auth::id() && CurrentWorker::hasPermission('sales_pos');
        if (!$own && !CurrentWorker::hasPermission('sales_read')) {
            return $this->forbidden();
        }
        if (!CurrentWorker::canAccessAllLocations() && $sale->location_id !== CurrentLocation::id()) {
            return $this->notFound('Venta no encontrada.');
        }

        return $this->signed('print.sale-ticket', ['sale' => $sale->id], $request);
    }

    public function saleTicket(Request $request, int $sale)
    {
        $model = Sale::with(['details.product', 'details.unit', 'details.package', 'payments', 'user', 'customer', 'cashRegister', 'location'])
            ->where('company_id', $this->signedCompany($request))
            ->findOrFail($sale);

        return ThermalPdf::response('pdf.ticket-sale', [
            'sale' => $model,
            'company' => Company::find($model->company_id),
            'location' => $model->location,
        ], 'ticket-' . ($model->sale_number ?? $model->id) . '.pdf', (int) $request->query('width', 80));
    }

    // ─── Corte de turno ──────────────────────────────────────────────────────

    public function shiftTicketUrl(Request $request, int $id): JsonResponse
    {
        $session = CashRegisterSession::where('company_id', CurrentCompany::id())->find($id);
        if (!$session) {
            return $this->notFound('Turno no encontrado.');
        }

        // Las mismas reglas que para ver el turno: el propio, o quien
        // consulta turnos. El de otra persona, además, solo de la sucursal
        // actual salvo con acceso a todas: antes un supervisor imprimía el
        // corte de cualquier sucursal mandando su id.
        if ($session->user_id !== Auth::id()) {
            if (!CurrentWorker::hasPermission('cash_register_sessions_read')) {
                return $this->forbidden();
            }
            if (!CurrentWorker::canAccessAllLocations() && $session->location_id !== CurrentLocation::id()) {
                return $this->notFound('Turno no encontrado.');
            }
        }

        return $this->signed('print.shift-ticket', ['session' => $session->id], $request);
    }

    public function shiftTicket(Request $request, int $session)
    {
        $model = CashRegisterSession::with(['cashRegister', 'location', 'user', 'closedBy'])
            ->where('company_id', $this->signedCompany($request))
            ->findOrFail($session);

        // Abierto: totales en vivo (corte parcial). Cerrado: los congelados.
        $summary = $model->status === 'open' ? $this->sessions->summary($model) : ($model->totals ?? []);

        return ThermalPdf::response('pdf.ticket-shift', [
            'session' => $model,
            'summary' => $summary,
            'company' => Company::find($model->company_id),
            'location' => $model->location,
        ], 'corte-turno-' . $model->id . '.pdf', (int) $request->query('width', 80));
    }

    // ─── Remisión de transferencia ───────────────────────────────────────────

    public function transferPdfUrl(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('transfers_read')) {
            return $this->forbidden();
        }

        $transfer = InventoryTransfer::where('company_id', CurrentCompany::id())->find($id);
        // Como al ver la transferencia: sin acceso a todas las sucursales,
        // solo las que salen de o llegan a una de las suyas.
        if (!$transfer || !(CurrentWorker::canAccessAllLocations()
            || CurrentWorker::canAccessLocation((int) $transfer->from_location_id)
            || CurrentWorker::canAccessLocation((int) $transfer->to_location_id))) {
            return $this->notFound('Transferencia no encontrada.');
        }

        return $this->signed('print.transfer', ['transfer' => $transfer->id], $request);
    }

    public function transferPdf(Request $request, int $transfer)
    {
        $model = InventoryTransfer::with(['fromLocation', 'toLocation', 'requestedByUser', 'details.product.unit', 'details.unit', 'details.package'])
            ->where('company_id', $this->signedCompany($request))
            ->findOrFail($transfer);

        // Quién y cuándo aprobó, envió y recibió vive en el flujo (content).
        $content = $model->content ?? [];
        $steps = [
            'approved_at' => data_get($content, 'step_1.approved_at'),
            'shipped_at' => data_get($content, 'step_2.shipped_at'),
            'received_at' => data_get($content, 'step_3.received_at'),
            'shipping_notes' => data_get($content, 'step_2.shipping_notes'),
            'receiving_notes' => data_get($content, 'step_3.receiving_notes'),
        ];
        $ids = array_filter([
            'approved_by' => data_get($content, 'step_1.approved_by'),
            'shipped_by' => data_get($content, 'step_2.shipped_by'),
            'received_by' => data_get($content, 'step_3.received_by'),
        ]);
        $names = User::whereIn('id', array_values($ids))->pluck('name', 'id');
        $people = array_map(fn($id) => $names[$id] ?? null, $ids);

        $pdf = Pdf::loadView('pdf.transfer', [
            'transfer' => $model,
            'company' => Company::find($model->company_id),
            'steps' => $steps,
            'people' => $people,
        ])->setPaper('letter', 'portrait')->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="remision-' . $model->transfer_number . '.pdf"',
        ]);
    }

    // ─── Hoja de producción ──────────────────────────────────────────────────

    public function productionSheetUrl(Request $request, int $id): JsonResponse
    {
        if (!CurrentWorker::hasPermission('production_orders_read')) {
            return $this->forbidden();
        }

        $order = ProductionOrder::where('company_id', CurrentCompany::id())->find($id);
        // Como al ver la orden: sin acceso a todas las sucursales, solo las suyas.
        if (!$order || (!CurrentWorker::canAccessAllLocations() && $order->location_id !== CurrentLocation::id())) {
            return $this->notFound('Orden de producción no encontrada.');
        }

        return $this->signed('print.production-sheet', ['order' => $order->id], $request);
    }

    public function productionSheet(Request $request, int $order)
    {
        $sheet = ProductionSheet::load($this->signedCompany($request), $order);

        $pdf = Pdf::loadView('pdf.production-sheet', $sheet->data())
            ->setPaper('letter', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $sheet->fileName() . '.pdf"',
        ]);
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────────

    private function signed(string $route, array $params, Request $request): JsonResponse
    {
        $params['company_id'] = CurrentCompany::id();
        if ($request->filled('width')) {
            $params['width'] = ThermalPdf::width((int) $request->input('width'));
        }

        return response()->json([
            'url' => URL::temporarySignedRoute($route, now()->addMinutes(self::URL_TTL_MINUTES), $params),
            'expires_at' => now()->addMinutes(self::URL_TTL_MINUTES)->toISOString(),
        ]);
    }

    private function signedCompany(Request $request): int
    {
        $companyId = (int) $request->query('company_id');
        abort_if(!$companyId, 404);

        return $companyId;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }
}
