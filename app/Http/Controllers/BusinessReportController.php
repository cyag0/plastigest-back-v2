<?php

namespace App\Http\Controllers;

use App\Models\Admin\Location;
use App\Reports\AdjustmentsReport;
use App\Reports\BusinessReport;
use App\Reports\CashDifferencesReport;
use App\Reports\InventoryValuationReport;
use App\Reports\ReportPeriod;
use App\Reports\ReportScope;
use App\Reports\SalesPeriodReport;
use App\Reports\Spreadsheet;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Reportes del dueño en PDF y Excel: ventas del periodo, inventario
 * valorizado, mermas y ajustes, y diferencias de caja por cajero.
 *
 * Como los demás documentos, la app pide primero una URL firmada (con el
 * token) y después la abre sin token. La empresa, la sucursal permitida y el
 * periodo van dentro de la firma: no se pueden alterar al descargar.
 */
class BusinessReportController extends Controller
{
    private const URL_TTL_MINUTES = 30;

    public const REPORTS = [
        'sales' => [
            'title' => 'Ventas del periodo',
            'description' => 'Totales contra el periodo anterior, por sucursal, día y hora; productos, categorías, métodos de pago, cajeros, cancelaciones y descuentos.',
            'icon' => 'chart-box-outline',
            'input' => 'period',
        ],
        'inventory-valuation' => [
            'title' => 'Inventario valorizado',
            'description' => 'Existencia por producto con su costo y valor por sucursal; agotados y bajo mínimo resaltados. Se puede pedir al cierre de una fecha.',
            'icon' => 'warehouse',
            'input' => 'as_of',
        ],
        'adjustments' => [
            'title' => 'Mermas y ajustes',
            'description' => 'Pérdidas por motivo (merma, daño, caducidad, robo, conteo), por producto, sucursal y usuario, con su costo.',
            'icon' => 'trash-can-outline',
            'input' => 'period',
        ],
        'cash-differences' => [
            'title' => 'Diferencias de caja',
            'description' => 'Turnos cerrados con lo esperado, lo contado y la diferencia; quién sobra y a quién le falta seguido.',
            'icon' => 'cash-check',
            'input' => 'period',
        ],
    ];

    private const FORMATS = ['pdf', 'xlsx'];

    public function options(): JsonResponse
    {
        if (!CurrentWorker::hasPermission('reports_view')) {
            return $this->forbidden();
        }

        $canChoose = CurrentWorker::canAccessAllLocations();
        $current = CurrentLocation::get();

        return response()->json([
            'reports' => collect(self::REPORTS)->map(fn($r, $key) => ['key' => $key] + $r)->values(),
            'can_choose_location' => $canChoose,
            'current_location' => $current ? ['id' => $current->id, 'name' => $current->name] : null,
            'locations' => $canChoose
                ? Location::where('company_id', CurrentCompany::id())->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function url(Request $request, string $report): JsonResponse
    {
        if (!CurrentWorker::hasPermission('reports_view')) {
            return $this->forbidden();
        }
        abort_unless(isset(self::REPORTS[$report]), 404);

        $validated = $request->validate([
            'format' => 'nullable|in:' . implode(',', self::FORMATS),
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'as_of' => 'nullable|date_format:Y-m-d',
            'location_id' => 'nullable',
        ]);

        $params = [
            'report' => $report,
            'format' => $validated['format'] ?? 'pdf',
            'company_id' => CurrentCompany::id(),
            'location_id' => $this->locationScope($request->input('location_id')) ?? 'all',
        ];

        if (self::REPORTS[$report]['input'] === 'as_of') {
            if (!empty($validated['as_of'])) {
                $params['as_of'] = $validated['as_of'];
            }
        } else {
            // Se valida aquí (rango invertido, más de un año) para responder
            // 422 a la app en lugar de un error al abrir el enlace.
            $period = ReportPeriod::fromInput($validated['date_from'] ?? null, $validated['date_to'] ?? null);
            $params['date_from'] = $period->fromDate();
            $params['date_to'] = $period->toDate();
        }

        return response()->json([
            'url' => URL::temporarySignedRoute('business-reports.download', now()->addMinutes(self::URL_TTL_MINUTES), $params),
            'expires_at' => now()->addMinutes(self::URL_TTL_MINUTES)->toISOString(),
        ]);
    }

    public function download(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        $companyId = (int) $request->query('company_id');
        abort_if(!$companyId, 404);

        $location = $request->query('location_id');
        $scope = ReportScope::for($companyId, $location === 'all' || !$location ? null : (int) $location);
        $document = $this->make($report, $scope, $request);

        if ($request->query('format') === 'xlsx') {
            return Spreadsheet::download($document->fileName() . '.xlsx', $document->sheets());
        }

        $pdf = Pdf::loadView($document->view(), $document->data() + [
            'company' => $scope->company,
            'document' => $document,
        ])->setPaper('letter', $document->paper())->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $document->fileName() . '.pdf"',
        ]);
    }

    private function make(string $report, ReportScope $scope, Request $request): BusinessReport
    {
        $period = fn() => ReportPeriod::fromInput($request->query('date_from'), $request->query('date_to'));

        return match ($report) {
            'sales' => new SalesPeriodReport($scope, $period()),
            'inventory-valuation' => new InventoryValuationReport($scope, $request->query('as_of')),
            'adjustments' => new AdjustmentsReport($scope, $period()),
            'cash-differences' => new CashDifferencesReport($scope, $period()),
        };
    }

    /**
     * Sucursal del reporte: quien no ve todas las sucursales solo saca la
     * suya, diga lo que diga el filtro; quien sí, elige una o todas.
     */
    private function locationScope(mixed $requested): ?int
    {
        if (!CurrentWorker::canAccessAllLocations()) {
            return CurrentLocation::id();
        }
        if ($requested === null || $requested === '' || $requested === 'all') {
            return null;
        }

        $id = (int) $requested;
        abort_unless(Location::where('company_id', CurrentCompany::id())->whereKey($id)->exists(), 404);

        return $id;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
    }
}
