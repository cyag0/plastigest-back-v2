<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Tipo de inventario de un producto.
 *
 * Sustituye al enum `products.product_type`. Además de clasificar, define qué se
 * puede hacer con los productos del tipo: comprarse, venderse, producirse o
 * consumirse en una fórmula.
 *
 * @mixin IdeHelperInventoryType
 */
class InventoryType extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'code',
        'slug',
        'name',
        'description',
        'icon',
        'color',
        'is_purchasable',
        'is_sellable',
        'is_producible',
        'is_consumable',
        'is_stockable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_purchasable' => 'boolean',
        'is_sellable' => 'boolean',
        'is_producible' => 'boolean',
        'is_consumable' => 'boolean',
        'is_stockable' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Códigos de los tipos del sistema
    const RAW_MATERIAL = 'raw_material';
    const PACKAGING_MATERIAL = 'packaging_material';
    const SEMI_FINISHED = 'semi_finished';
    const FINISHED_PRODUCT = 'finished_product';
    const MERCHANDISE = 'merchandise';
    const SUPPLY = 'supply';

    /**
     * Equivalencias del enum anterior `products.product_type`.
     * Se conserva para que las versiones viejas de la app sigan funcionando.
     */
    const LEGACY_TYPE_MAP = [
        'raw_material' => self::RAW_MATERIAL,
        'processed' => self::FINISHED_PRODUCT,
        'commercial' => self::MERCHANDISE,
    ];

    /**
     * Vuelta al enum anterior. `products.product_type` sigue vivo (lo leen el
     * POS, compras y los reportes), así que al guardar un producto se rellena a
     * partir de su tipo de inventario.
     *
     * Los tipos nuevos caen en el valor viejo más cercano: el material de
     * empaque es materia prima porque se compra y se consume, y el granel es
     * procesado porque sale de una fórmula.
     */
    const CODE_TO_LEGACY_TYPE = [
        self::RAW_MATERIAL => 'raw_material',
        self::PACKAGING_MATERIAL => 'raw_material',
        self::SEMI_FINISHED => 'processed',
        self::FINISHED_PRODUCT => 'processed',
        self::MERCHANDISE => 'commercial',
        self::SUPPLY => 'commercial',
    ];

    /**
     * Banderas de comportamiento que se pueden usar como filtro de productos.
     */
    const BEHAVIOR_FLAGS = [
        'is_purchasable',
        'is_sellable',
        'is_producible',
        'is_consumable',
        'is_stockable',
    ];

    protected static function booted(): void
    {
        // El slug sale del nombre y es lo que viaja en la URL de la app.
        static::saving(function (InventoryType $type) {
            if (empty($type->slug) || $type->isDirty('name')) {
                $type->slug = $type->generateSlug();
            }
        });
    }

    /**
     * Slug del nombre, único dentro de la empresa (o entre los del sistema).
     */
    public function generateSlug(): string
    {
        $base = Str::slug($this->name ?: $this->code) ?: 'tipo';
        $slug = $base;
        $suffix = 2;

        while (
            static::where('slug', $slug)
                ->when($this->exists, fn($q) => $q->where('id', '!=', $this->id))
                ->when($this->company_id, fn($q) => $q->where('company_id', $this->company_id))
                ->when(!$this->company_id, fn($q) => $q->whereNull('company_id'))
                ->exists()
        ) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Admin\Company::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Tipos del sistema (company_id null) más los propios de la empresa.
     */
    public function scopeForCompany($query, $companyId = null)
    {
        return $query->where(function ($q) use ($companyId) {
            $q->whereNull('company_id');

            if ($companyId) {
                $q->orWhere('company_id', $companyId);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Id del tipo por código, priorizando el propio de la empresa sobre el del sistema.
     */
    public static function idForCode(string $code, ?int $companyId = null): ?int
    {
        return static::query()
            ->forCompany($companyId)
            ->where('code', $code)
            ->orderByRaw('company_id IS NULL')
            ->value('id');
    }

    /**
     * Traduce un valor del enum anterior a un id de tipo de inventario.
     */
    public static function idForLegacyType(string $legacyType, ?int $companyId = null): ?int
    {
        $code = self::LEGACY_TYPE_MAP[$legacyType] ?? $legacyType;

        return static::idForCode($code, $companyId);
    }

    /**
     * Valor del enum anterior que le corresponde a un tipo. Los tipos propios
     * de una empresa caen en 'commercial', que es el default histórico.
     */
    public static function legacyTypeForId(?int $inventoryTypeId): ?string
    {
        if (!$inventoryTypeId) {
            return null;
        }

        $code = static::whereKey($inventoryTypeId)->value('code');

        return $code ? (self::CODE_TO_LEGACY_TYPE[$code] ?? 'commercial') : null;
    }
}
