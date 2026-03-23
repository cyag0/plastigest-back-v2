<?php

namespace App\Http\Resources;

use App\Constants\Files;
use App\Models\InventoryAdjustmentDetail;
use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = $this->content ?? [
            'current_step' => 1,
        ];

        if (!is_array($content)) {
            $content = [
                'current_step' => 1,
            ];
        }

        $step2EvidenceNames = data_get($content, 'step_2.evidence', []);
        $step3EvidenceNames = data_get($content, 'step_3.evidence', []);

        $content['step_2']['evidence_files'] = $this->formatEvidenceFiles(
            is_array($step2EvidenceNames) ? $step2EvidenceNames : [],
            'step_2'
        );

        $content['step_3']['evidence_files'] = $this->formatEvidenceFiles(
            is_array($step3EvidenceNames) ? $step3EvidenceNames : [],
            'step_3'
        );

        $generatedAdjustments = $this->resolveGeneratedAdjustments($content);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'transfer_number' => $this->transfer_number,

            // Ubicaciones
            'from_location_id' => $this->from_location_id,
            'from_location' => [
                'id' => $this->fromLocation->id,
                'name' => $this->fromLocation->name,
                'is_main' => $this->fromLocation->is_main,
            ],
            'to_location_id' => $this->to_location_id,
            'to_location' => [
                'id' => $this->toLocation->id,
                'name' => $this->toLocation->name,
                'is_main' => $this->toLocation->is_main,
            ],

            // Estado
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'current_step' => (int) (($content['current_step'] ?? null) ?? 1),
            'content' => $content,

            // Usuarios
            'requested_by' => $this->requested_by,
            'requested_by_user' => $this->requestedByUser ? [
                'id' => $this->requestedByUser->id,
                'name' => $this->requestedByUser->name,
            ] : null,

            'approved_by' => data_get($this->content, 'step_1.approved_by'),
            'approved_by_user' => null,

            'shipped_by' => data_get($this->content, 'step_2.shipped_by'),
            'shipped_by_user' => null,

            'received_by' => data_get($this->content, 'step_3.received_by'),
            'received_by_user' => null,

            // Fechas
            'requested_at' => data_get($this->content, 'step_1.created_at') ?? $this->created_at?->toISOString(),
            'approved_at' => data_get($this->content, 'step_1.approved_at'),
            'shipped_at' => data_get($this->content, 'step_2.shipped_at'),
            'received_at' => data_get($this->content, 'step_3.received_at'),
            'cancelled_at' => null,

            // Totales y notas
            'total_cost' => (float) $this->total_cost,
            'notes' => $this->notes,
            'rejection_reason' => data_get($this->content, 'step_1.reason'),

            // Diferencias
            'total_differences' => $this->total_differences,
            'has_differences' => $this->has_differences,

            // Detalles (solo si están cargados)
            'details' => InventoryTransferDetailResource::collection($this->whenLoaded('details')),
            'generated_adjustments' => $generatedAdjustments,

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @return array<int, array<string, mixed>>
     */
    protected function resolveGeneratedAdjustments(array $content): array
    {
        $adjustments = data_get($content, 'step_3.adjustments', []);
        if (!is_array($adjustments) || empty($adjustments)) {
            return [];
        }

        $ids = collect($adjustments)
            ->pluck('id')
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        if (empty($ids)) {
            return [];
        }

        $records = InventoryAdjustmentDetail::query()
            ->with(['product', 'unit'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($adjustments as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0 || !$records->has($id)) {
                continue;
            }

            /** @var InventoryAdjustmentDetail $row */
            $row = $records->get($id);
            $result[] = [
                'id' => $row->id,
                'reason' => $row->reason_code?->value,
                'reason_label' => $row->reason_code?->label(),
                'product_id' => $row->product_id,
                'product_name' => $row->product?->name,
                'quantity' => (float) $row->quantity,
                'unit' => $row->unit ? [
                    'id' => $row->unit->id,
                    'name' => $row->unit->name,
                    'abbreviation' => $row->unit->abbreviation,
                ] : null,
                'notes' => $row->notes,
                'content' => $row->content,
                'applied_at' => $row->applied_at?->toISOString(),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, string> $names
     * @return array<int, array<string, mixed>>
     */
    protected function formatEvidenceFiles(array $names, string $step): array
    {
        $basePath = rtrim(Files::TRANSFER_EVIDENCE_PATH, '/') . '/transfer_' . $this->id . '/' . $step;
        $formatted = [];

        foreach ($names as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $file = AppUploadUtil::formatFile($basePath, $name);
            if ($file) {
                $formatted[] = $file;
            }
        }

        return $formatted;
    }
}
