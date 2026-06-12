<?php

namespace App\Services;

use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Servicio para gestión de Fórmulas (recetas).
 * Una fórmula es un template de cantidades esperadas por unidad de producto.
 */
class FormulaService
{
    public function create(array $data): Formula
    {
        return DB::transaction(function () use ($data) {
            $company = CurrentCompany::get();
            $user = Auth::user();

            $formula = Formula::create([
                'company_id' => $company?->id,
                'product_id' => (int) $data['product_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => 1,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'expected_output_quantity' => isset($data['expected_output_quantity']) ? (float) $data['expected_output_quantity'] : null,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $this->syncItems($formula, $data['items'] ?? []);

            return $formula->fresh('items.product', 'items.unit');
        });
    }

    public function update(Formula $formula, array $data): Formula
    {
        return DB::transaction(function () use ($formula, $data) {
            $formula->fill([
                'name' => $data['name'] ?? $formula->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $formula->description,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $formula->is_active,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $formula->notes,
                'expected_output_quantity' => array_key_exists('expected_output_quantity', $data)
                    ? (isset($data['expected_output_quantity']) ? (float) $data['expected_output_quantity'] : null)
                    : $formula->expected_output_quantity,
                'updated_by' => Auth::id(),
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($formula, $data['items']);
            }

            return $formula->fresh('items.product', 'items.unit');
        });
    }

    /**
     * Clona una fórmula incrementando la versión.
     */
    public function clone(Formula $formula, ?string $newName = null): Formula
    {
        return DB::transaction(function () use ($formula, $newName) {
            $maxVersion = Formula::query()
                ->where('company_id', $formula->company_id)
                ->where('product_id', $formula->product_id)
                ->max('version');

            $clone = Formula::create([
                'company_id' => $formula->company_id,
                'product_id' => $formula->product_id,
                'name' => $newName ?? $formula->name . ' (v' . (($maxVersion ?? 0) + 1) . ')',
                'description' => $formula->description,
                'version' => ($maxVersion ?? 0) + 1,
                'is_active' => true,
                'notes' => $formula->notes,
                'expected_output_quantity' => $formula->expected_output_quantity,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            foreach ($formula->items as $item) {
                FormulaItem::create([
                    'formula_id' => $clone->id,
                    'product_id' => $item->product_id,
                    'unit_id' => $item->unit_id,
                    'expected_quantity' => $item->expected_quantity,
                    'sort_order' => $item->sort_order,
                    'notes' => $item->notes,
                ]);
            }

            return $clone->fresh('items.product', 'items.unit');
        });
    }

    public function deactivate(Formula $formula): Formula
    {
        $formula->is_active = false;
        $formula->updated_by = Auth::id();
        $formula->save();
        return $formula;
    }

    /**
     * Valida si la fórmula puede eliminarse (no debe estar en uso).
     */
    public function canDelete(Formula $formula): array
    {
        $inUse = DB::table('production_orders')
            ->where('formula_id', $formula->id)
            ->exists();

        if ($inUse) {
            return [
                'can_delete' => false,
                'message' => 'No se puede eliminar: la fórmula está en uso en órdenes de producción.',
            ];
        }

        return ['can_delete' => true, 'message' => ''];
    }

    private function syncItems(Formula $formula, array $items): void
    {
        $formula->items()->delete();
        foreach (array_values($items) as $i => $row) {
            if (empty($row['product_id']) || !isset($row['expected_quantity'])) {
                continue;
            }
            FormulaItem::create([
                'formula_id' => $formula->id,
                'product_id' => (int) $row['product_id'],
                'unit_id' => (int) ($row['unit_id'] ?? 0),
                'expected_quantity' => (float) $row['expected_quantity'],
                'sort_order' => $i,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }
}
