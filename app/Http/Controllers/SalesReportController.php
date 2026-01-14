<?php

namespace App\Http\Controllers;

use App\Models\SalesReport;
use App\Http\Resources\SalesReportResource;
use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Movement;

class SalesReportController extends CrudController
{
    protected string $resource = SalesReportResource::class;
    protected string $model = SalesReport::class;

    protected function indexRelations(): array
    {
        return ['company', 'location', 'user'];
    }

    protected function getShowRelations(): array
    {
        return ['company', 'location', 'user'];
    }

    protected function handleQuery($query, array $params)
    {
        // Apply company filter
        $company = CurrentCompany::get();
        if ($company) {
            $query->where('company_id', $company->id);
        }

        // Filter by location_id
        if (isset($params['location_id'])) {
            $query->where('location_id', $params['location_id']);
        }

        // Filter by date range
        if (isset($params['date_from'])) {
            $query->whereDate('report_date', '>=', $params['date_from']);
        }

        if (isset($params['date_to'])) {
            $query->whereDate('report_date', '<=', $params['date_to']);
        }

        // Filter by specific date
        if (isset($params['report_date'])) {
            $query->whereDate('report_date', $params['report_date']);
        }
    }

    protected function validateStoreData(Request $request): array
    {
        return $request->validate([
            'location_id' => 'required|exists:locations,id',
            'report_date' => 'required|date',
            'total_sales' => 'required|numeric|min:0',
            'total_cash' => 'nullable|numeric|min:0',
            'total_card' => 'nullable|numeric|min:0',
            'total_transfer' => 'nullable|numeric|min:0',
            'transactions_count' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    protected function validateUpdateData(Request $request, Model $model): array
    {
        return $request->validate([
            'location_id' => 'sometimes|exists:locations,id',
            'report_date' => 'sometimes|date',
            'total_sales' => 'sometimes|numeric|min:0',
            'total_cash' => 'nullable|numeric|min:0',
            'total_card' => 'nullable|numeric|min:0',
            'total_transfer' => 'nullable|numeric|min:0',
            'transactions_count' => 'sometimes|integer|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    protected function process($callback, array $data, $method): Model
    {
        // Add company_id from current context
        $company = CurrentCompany::get();
        if ($company && !isset($data['company_id'])) {
            $data['company_id'] = $company->id;
        }

        // Add user_id for create operations
        if ($method === 'create' && !isset($data['user_id'])) {
            $data['user_id'] = Auth::id();
        }

        // Remove user_id from update operations
        if ($method === 'update') {
            unset($data['user_id']);
        }

        // Ensure numeric fields are set
        $data['total_cash'] = $data['total_cash'] ?? 0;
        $data['total_card'] = $data['total_card'] ?? 0;
        $data['total_transfer'] = $data['total_transfer'] ?? 0;

        return $callback($data);
    }

    /**
     * Generar URL firmada para el PDF
     */
    public function generatePdfUrl($id)
    {
        try {
            // Verificar que el reporte existe
            $salesReport = SalesReport::findOrFail($id);

            // Generar URL firmada que expira en 1 hora
            $signedUrl = URL::temporarySignedRoute(
                'sales-reports.pdf',
                now()->addHour(),
                ['id' => $id]
            );

            return response()->json([
                'url' => $signedUrl,
                'expires_at' => now()->addHour()->toISOString(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar URL del PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generar PDF del reporte de ventas
     */
    public function generatePdf($id)
    {
        try {
            // Obtener el reporte con relaciones
            $salesReport = SalesReport::with([
                'location',
                'user',
                'company'
            ])->findOrFail($id);

            // Obtener la ubicación actual para filtrar ventas
            $currentLocation = CurrentLocation::get();
            $locationId = $currentLocation ? $currentLocation->id : $salesReport->location_id;

            // Obtener todas las ventas del día en la ubicación
            $sales = Sale::with([
                'details.product.unit',
                'user'
            ])
                ->where('location_origin_id', $locationId)
                ->whereDate('created_at', $salesReport->report_date)
                ->orderBy('created_at', 'asc')
                ->get();

            // Agrupar productos vendidos
            $productsSold = [];
            foreach ($sales as $sale) {
                foreach ($sale->details as $detail) {
                    $productKey = $detail->product_id;
                    if (!isset($productsSold[$productKey])) {
                        $productsSold[$productKey] = [
                            'product' => $detail->product,
                            'quantity' => 0,
                            'total' => 0,
                            'transactions' => 0
                        ];
                    }
                    $productsSold[$productKey]['quantity'] += $detail->quantity;
                    $productsSold[$productKey]['total'] += $detail->total_cost ?? 0;
                    $productsSold[$productKey]['transactions']++;
                }
            }

            // Obtener la compañía
            $company = $salesReport->company;

            // Generar el PDF
            $pdf = Pdf::loadView('pdf.sales-report', [
                'salesReport' => $salesReport,
                'company' => $company,
                'sales' => $sales,
                'productsSold' => $productsSold,
            ]);

            // Configurar el PDF
            $pdf->setPaper('letter', 'portrait');

            // Retornar el PDF como stream con headers correctos
            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="reporte-ventas-' . $salesReport->id . '-' . now()->format('Y-m-d') . '.pdf"',
            ]);
        } catch (\Exception $e) {
            \Log::error('Error al generar PDF de reporte de ventas: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            // Retornar respuesta JSON con error para debugging
            return response()->json([
                'message' => 'Error al generar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }
}
