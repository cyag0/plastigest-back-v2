<?php

namespace App\Services;

use App\Models\Operations\Formula;
use App\Models\Operations\FormulaItem;
use App\Models\Operations\FormulaOutput;
use App\Models\Product;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Servicio para gestión de Fórmulas (procesos de producción).
 *
 * Una fórmula declara qué se consume (`items`) y qué se obtiene (`outputs`) al
 * correr el proceso una vez. Ambas listas son cantidades esperadas: lo que de
 * verdad entró y salió se captura en la orden de producción.
 */
class FormulaService
{
    /** Relaciones que necesita el frontend para pintar la fórmula completa. */
    public const RELATIONS = [
        'items.product',
        'items.unit',
        'outputs.product',
        'outputs.unit',
    ];

    public function create(array $data): Formula
    {
        return DB::transaction(function () use ($data) {
            $company = CurrentCompany::get();
            $user = Auth::user();

            $formula = Formula::create([
                'company_id' => $company?->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => 1,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $this->syncItems($formula, $data['items'] ?? []);
            $this->syncOutputs($formula, $this->resolveOutputs($data));

            return $formula->fresh(self::RELATIONS);
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
                'updated_by' => Auth::id(),
            ])->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($formula, $data['items']);
            }

            // Sólo se tocan las salidas si vienen en la petición: así una app
            // vieja que no las manda no borra las que ya están guardadas.
            $outputs = $this->resolveOutputs($data);
            if ($outputs !== null) {
                $this->syncOutputs($formula, $outputs);
            }

            return $formula->fresh(self::RELATIONS);
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
                ->where('name', 'like', $formula->name . '%')
                ->max('version');

            $nextVersion = ($maxVersion ?? 0) + 1;

            $clone = Formula::create([
                'company_id' => $formula->company_id,
                'name' => $newName ?? $formula->name . ' (v' . $nextVersion . ')',
                'description' => $formula->description,
                'version' => $nextVersion,
                'is_active' => true,
                'notes' => $formula->notes,
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

            foreach ($formula->outputs as $output) {
                FormulaOutput::create([
                    'formula_id' => $clone->id,
                    'product_id' => $output->product_id,
                    'unit_id' => $output->unit_id,
                    'expected_quantity' => $output->expected_quantity,
                    'is_primary' => $output->is_primary,
                    'sort_order' => $output->sort_order,
                    'notes' => $output->notes,
                ]);
            }

            return $clone->fresh(self::RELATIONS);
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

    /**
     * Salidas a guardar, o null si la petición no trae ninguna referencia.
     *
     * Acepta el formato viejo (`product_id` + `expected_output_quantity`, un
     * solo producto) para que las versiones de la app anteriores a la fórmula
     * multi-salida sigan funcionando: se guarda como la salida principal.
     */
    private function resolveOutputs(array $data): ?array
    {
        if (array_key_exists('outputs', $data)) {
            return $data['outputs'] ?? [];
        }

        if (empty($data['product_id'])) {
            return null;
        }

        return [[
            'product_id' => (int) $data['product_id'],
            'unit_id' => $data['unit_id'] ?? null,
            'expected_quantity' => $data['expected_output_quantity'] ?? 1,
            'is_primary' => true,
        ]];
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

    /**
     * Reemplaza las salidas. Si ninguna viene marcada como principal, la
     * primera lo es: la fórmula siempre tiene un resultado de referencia.
     */
    private function syncOutputs(Formula $formula, array $outputs): void
    {
        $formula->outputs()->delete();

        $rows = array_values(array_filter(
            $outputs,
            fn($row) => !empty($row['product_id']) && isset($row['expected_quantity'])
        ));

        $hasPrimary = false;
        foreach ($rows as $row) {
            if (!empty($row['is_primary'])) {
                $hasPrimary = true;
                break;
            }
        }

        foreach ($rows as $i => $row) {
            FormulaOutput::create([
                'formula_id' => $formula->id,
                'product_id' => (int) $row['product_id'],
                'unit_id' => (int) ($row['unit_id'] ?: $this->defaultUnitId((int) $row['product_id'])),
                'expected_quantity' => (float) $row['expected_quantity'],
                'is_primary' => $hasPrimary ? !empty($row['is_primary']) : $i === 0,
                'sort_order' => $i,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    /**
     * Unidad base del producto, para cuando la petición no manda una.
     */
    private function defaultUnitId(int $productId): int
    {
        return (int) Product::whereKey($productId)->value('unit_id');
    }
}
