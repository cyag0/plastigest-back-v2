<?php

namespace App\Http\Controllers;

use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\User;
use App\Models\Admin\Role;
use App\Http\Resources\CashClosingResource;
use App\Mail\CashClosingMail;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Support\CurrentWorker;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class CashClosingController extends CrudController
{
    protected string $resource = CashClosingResource::class;
    protected string $model = CashClosing::class;
    protected ?string $permissionPrefix = 'cash_movements';

    protected function indexRelations(): array
    {
        return ['location', 'user'];
    }

    protected function getShowRelations(): array
    {
        return ['location', 'user'];
    }

    protected function applyBasicFilters($query, array $params): void
    {
        if (!empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('closing_date', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }
    }

    protected function handleQuery($query, array $params): void
    {
        $company = CurrentCompany::get();
        if ($company) {
            $query->where('company_id', $company->id);
        }

        if (!empty($params['location_id'])) {
            $query->where('location_id', $params['location_id']);
        }

        if (!empty($params['date_from'])) {
            $query->whereDate('closing_date', '>=', $params['date_from']);
        }

        if (!empty($params['date_to'])) {
            $query->whereDate('closing_date', '<=', $params['date_to']);
        }

        $query->orderBy('closing_date', 'desc');
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'closing_date'    => 'required|date',
            'opening_balance' => 'required|numeric',
            'total_income'    => 'required|numeric',
            'total_expense'   => 'required|numeric',
            'expected_balance'=> 'required|numeric',
            'physical_count'  => 'nullable|numeric',
            'difference'      => 'nullable|numeric',
            'total_cash'      => 'required|numeric',
            'total_card'      => 'required|numeric',
            'total_transfer'  => 'required|numeric',
            'total_other'     => 'required|numeric',
            'movements_count' => 'required|integer',
            'notes'           => 'nullable|string|max:1000',
            'status'          => 'nullable|string|max:20',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'closing_date'    => 'sometimes|date',
            'opening_balance' => 'sometimes|numeric',
            'total_income'    => 'sometimes|numeric',
            'total_expense'   => 'sometimes|numeric',
            'expected_balance'=> 'sometimes|numeric',
            'physical_count'  => 'nullable|numeric',
            'difference'      => 'nullable|numeric',
            'total_cash'      => 'sometimes|numeric',
            'total_card'      => 'sometimes|numeric',
            'total_transfer'  => 'sometimes|numeric',
            'total_other'     => 'sometimes|numeric',
            'movements_count' => 'sometimes|integer',
            'notes'           => 'nullable|string|max:1000',
            'status'          => 'nullable|string|max:20',
        ]);
    }

    protected function processStoreData(array $data, Request $request): array
    {
        $company  = CurrentCompany::get();
        $location = CurrentLocation::get();

        $data['company_id']  = $company?->id;
        $data['location_id'] = $location?->id;
        $data['user_id']     = Auth::id();
        $data['status']      = $data['status'] ?? 'closed';

        return $data;
    }

    /**
     * Al crear un corte se envía automáticamente el reporte por correo a los
     * usuarios de la sucursal con permiso para cerrar caja. No interrumpe la
     * creación del cierre si el envío falla.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        try {
            $this->emailClosingReport($model);
        } catch (\Throwable $e) {
            Log::error('Error al enviar correo de corte de caja: ' . $e->getMessage());
        }
    }

    /**
     * Generar el PDF del corte de caja (incluye los movimientos del día).
     */
    protected function buildPdf(CashClosing $closing)
    {
        $closing->loadMissing(['location', 'user', 'company']);

        $movements = CashMovement::with('user')
            ->where('company_id', $closing->company_id)
            ->where('location_id', $closing->location_id)
            ->whereDate('movement_date', $closing->closing_date)
            ->orderBy('created_at')
            ->get();

        return Pdf::loadView('pdf.cash-closing', [
            'closing'   => $closing,
            'movements' => $movements,
            'company'   => $closing->company,
        ])->setPaper('letter', 'portrait');
    }

    /**
     * Correos de los usuarios de la sucursal con permiso de cerrar caja
     * (cash_movements_create). Si no hay ninguno, recae en quien lo creó.
     *
     * @return string[]
     */
    protected function resolveClosingRecipients(CashClosing $closing): array
    {
        $emails = [];

        if ($closing->location_id) {
            // Roles asignados a usuarios en esta sucursal.
            $locationRoleIds = DB::table('user_location_roles')
                ->where('location_id', $closing->location_id)
                ->whereNotNull('role_id')
                ->pluck('role_id')
                ->unique();

            // De esos roles, los que pueden cerrar caja. Se usa la relación de
            // Eloquent para no depender del nombre de la tabla pivote.
            $allowedRoleIds = Role::whereIn('id', $locationRoleIds)
                ->whereHas('permissions', fn ($q) => $q->where('name', 'cash_movements_create'))
                ->pluck('id');

            // Usuarios de la sucursal con alguno de esos roles.
            $userIds = DB::table('user_location_roles')
                ->where('location_id', $closing->location_id)
                ->whereIn('role_id', $allowedRoleIds)
                ->pluck('user_id')
                ->unique();

            $emails = User::whereIn('id', $userIds)
                ->whereNotNull('email')
                ->pluck('email')
                ->toArray();
        }

        // Respaldo: el usuario que registró el corte.
        if (empty($emails) && $closing->user_id) {
            $creatorEmail = User::where('id', $closing->user_id)->value('email');
            if ($creatorEmail) {
                $emails[] = $creatorEmail;
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * Construye el PDF y lo envía por correo a los destinatarios resueltos.
     */
    protected function emailClosingReport(CashClosing $closing): void
    {
        $recipients = $this->resolveClosingRecipients($closing);

        if (empty($recipients)) {
            Log::warning("Corte de caja {$closing->id}: sin destinatarios de correo.");
            return;
        }

        $pdfContent = $this->buildPdf($closing)->output();

        Mail::to($recipients)->send(new CashClosingMail($closing, $pdfContent));
    }

    /**
     * Generar URL firmada para el PDF del corte (válida 1 hora).
     */
    public function generatePdfUrl($id)
    {
        if (!CurrentWorker::hasPermission('cash_movements_read')) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $company = CurrentCompany::get();

        $closing = CashClosing::where('company_id', $company?->id)->findOrFail($id);

        $signedUrl = URL::temporarySignedRoute(
            'cash-closings.pdf',
            now()->addHour(),
            [
                'id'         => $closing->id,
                'company_id' => $closing->company_id,
            ]
        );

        return response()->json([
            'url'        => $signedUrl,
            'expires_at' => now()->addHour()->toISOString(),
        ]);
    }

    /**
     * Generar PDF del corte. Ruta pública-firmada (no requiere auth:sanctum).
     */
    public function generatePdf(Request $request, $id)
    {
        $companyId = $request->query('company_id');

        $closing = CashClosing::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $pdf = $this->buildPdf($closing);

        $date = \Carbon\Carbon::parse($closing->closing_date)->format('Y-m-d');

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="corte-caja-' . $date . '.pdf"',
        ]);
    }
}
