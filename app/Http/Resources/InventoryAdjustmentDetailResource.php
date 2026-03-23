<?php

namespace App\Http\Resources;

use App\Utils\AppUploadUtil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['product.mainImage', 'unit', 'location', 'createdByUser']);
        $content = is_array($this->content) ? $this->content : [];
        $evidenceFiles = $this->formatEvidenceFiles($content);

        $content['evidence_files'] = $evidenceFiles;

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'is_main' => $this->location->is_main,
            ] : null),

            'created_by' => $this->created_by,
            'created_by_user' => $this->whenLoaded('createdByUser', $this->createdByUser ? [
                'id' => $this->createdByUser->id,
                'name' => $this->createdByUser->name,
            ] : null),

            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'main_image' => $this->product->mainImage ? [
                    'id' => $this->product->mainImage->id,
                    'uri' => $this->product->mainImage->uri,
                    'url' => $this->product->mainImage->url,
                    'path' => $this->product->mainImage->path,
                ] : null,
            ]),

            'direction' => $this->direction,
            'quantity' => (float) $this->quantity,

            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', $this->unit ? [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'name' => $this->unit->name,
            ] : null),

            // Snapshots
            'previous_stock' => (float) $this->previous_stock,
            'new_stock' => (float) $this->new_stock,
            'adjusted_quantity' => (float) $this->adjusted_quantity,
            'impact' => $this->impact,

            'reason_code' => $this->reason_code->value,
            'reason_code_label' => $this->reason_code->label(),
            'reason_code_icon' => $this->reason_code->icon(),

            'notes' => $this->notes,
            'content' => $content,
            'evidence_files' => $evidenceFiles,
            'applied_at' => $this->applied_at?->toISOString(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @return array<int, array<string, mixed>>
     */
    private function formatEvidenceFiles(array $content): array
    {
        $rawFiles = data_get($content, 'evidence_files', []);
        if (!is_array($rawFiles)) {
            return [];
        }

        $formatted = [];
        foreach ($rawFiles as $file) {
            if (!is_array($file)) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $name = (string) ($file['name'] ?? '');
            $uri = (string) ($file['uri'] ?? '');
            $url = (string) ($file['url'] ?? '');

            if ($path !== '' && $uri === '' && $name !== '') {
                $basePath = str_replace('/' . $name, '', $path);
                $formattedFile = AppUploadUtil::formatFile($basePath, $name);
                if ($formattedFile) {
                    $uri = (string) ($formattedFile['uri'] ?? '');
                }
            }

            if ($url === '' && $path !== '') {
                $url = url('/storage/' . ltrim($path, '/'));
            }

            $formatted[] = [
                'name' => $name,
                'path' => $path,
                'uri' => $uri,
                'url' => $url,
                'type' => $file['mime_type'] ?? $file['type'] ?? null,
                'size' => $file['size'] ?? null,
            ];
        }

        return $formatted;
    }
}
