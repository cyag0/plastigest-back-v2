<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Slug del tipo de inventario, derivado del nombre en español.
 *
 * Es lo que va en la URL de la app: /inventory/products/type/materia-prima.
 * El `code` se queda como identificador interno (lo usa la lógica de negocio);
 * el slug es la cara pública y legible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_types', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->after('code');
        });

        foreach (DB::table('inventory_types')->get(['id', 'company_id', 'name', 'code']) as $type) {
            DB::table('inventory_types')
                ->where('id', $type->id)
                ->update(['slug' => $this->uniqueSlug($type->name ?: $type->code, $type->company_id, $type->id)]);
        }

        Schema::table('inventory_types', function (Blueprint $table) {
            $table->unique(['company_id', 'slug'], 'unique_inventory_type_company_slug');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_types', function (Blueprint $table) {
            $table->dropUnique('unique_inventory_type_company_slug');
            $table->dropColumn('slug');
        });
    }

    /**
     * Slug del nombre, con sufijo numérico si ya lo ocupa otro tipo de la empresa.
     */
    private function uniqueSlug(string $name, ?int $companyId, int $ignoreId): string
    {
        $base = Str::slug($name) ?: 'tipo';
        $slug = $base;
        $suffix = 2;

        while (
            DB::table('inventory_types')
                ->where('slug', $slug)
                ->where('id', '!=', $ignoreId)
                ->when($companyId, fn($q) => $q->where('company_id', $companyId))
                ->when(!$companyId, fn($q) => $q->whereNull('company_id'))
                ->exists()
        ) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
};
