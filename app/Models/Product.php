<?php

namespace App\Models;

use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperProduct
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'code',
        'purchase_price',
        'sale_price',
        'company_id',
        'category_id',
        'unit_id',
        'supplier_id',
        'inventory_type_id',
        'for_sale',
        'requires_packaging',
        'packaging_source_product_id',
        'packaging_source_quantity',
        'packaging_source_unit_id',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'for_sale' => 'boolean',
        'requires_packaging' => 'boolean',
        'packaging_source_quantity' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tipo del enum anterior (raw_material | processed | commercial), calculado
     * a partir del tipo de inventario. La columna products.product_type ya no
     * existe; se sigue exponiendo para las versiones de la app instaladas que
     * todavía lo leen.
     */
    public function getProductTypeAttribute(): string
    {
        $code = $this->inventoryType?->code;

        return InventoryType::CODE_TO_LEGACY_TYPE[$code] ?? 'commercial';
    }

    /**
     * Get the company that owns the product.
     */
    public function company()
    {
        return $this->belongsTo(\App\Models\Admin\Company::class);
    }

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the inventory type that owns the product.
     */
    public function inventoryType()
    {
        return $this->belongsTo(InventoryType::class);
    }

    /**
     * Get the unit that owns the product.
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Get the supplier that owns the product.
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Ingredientes que componen este producto (para productos procesados)
     */
    public function productIngredients()
    {
        return $this->hasMany(ProductIngredient::class, 'product_id');
    }

    /**
     * Productos (materias primas) que son ingredientes de este producto
     */
    public function ingredients()
    {
        return $this->belongsToMany(Product::class, 'product_ingredients', 'product_id', 'ingredient_id')
            ->withPivot(['quantity', 'notes'])
            ->withTimestamps();
    }

    /**
     * Productos que usan este producto como ingrediente
     */
    public function usedInProducts()
    {
        return $this->belongsToMany(Product::class, 'product_ingredients', 'ingredient_id', 'product_id')
            ->withPivot(['quantity', 'notes'])
            ->withTimestamps();
    }

    /**
     * Material de empaque que consume cada unidad de este producto al empacarse
     * (frasco, tapa, etiqueta). Se descuenta en el paso de empaque de producción.
     */
    public function packagingMaterials()
    {
        return $this->hasMany(ProductPackagingMaterial::class, 'product_id')->orderBy('sort_order');
    }

    /**
     * Granel del que se empaca este producto: la salida de la fórmula que se
     * consume al empacar ("Dulce de coco granel" → "Dulce de coco 250 g").
     */
    public function packagingSourceProduct()
    {
        return $this->belongsTo(Product::class, 'packaging_source_product_id');
    }

    /**
     * Unidad en la que se expresa `packaging_source_quantity`.
     */
    public function packagingSourceUnit()
    {
        return $this->belongsTo(Unit::class, 'packaging_source_unit_id');
    }

    /**
     * Productos terminados que se empacan a partir de este granel.
     */
    public function packagedProducts()
    {
        return $this->hasMany(Product::class, 'packaging_source_product_id');
    }

    /**
     * Get the locations that have this product with pivot 'active'.
     */
    public function locations()
    {
        return $this->belongsToMany(Location::class, 'product_location')
            ->withPivot(['current_stock', 'reserved_stock', 'minimum_stock', 'active', 'maximum_stock'])
            ->withTimestamps();
    }

    /**
     * Get active locations for this product.
     */
    public function activeLocations()
    {
        return $this->belongsToMany(\App\Models\Admin\Location::class, 'product_location')
            ->wherePivot('active', true)
            ->withPivot(['current_stock', 'reserved_stock', 'minimum_stock', 'active'])
            ->withTimestamps();
    }

    /**
     * Activate product in all locations for a company.
     */
    public function activateInAllLocations($companyId, $active = true)
    {
        $locations = \App\Models\Admin\Location::where('company_id', $companyId)->get();

        foreach ($locations as $location) {
            $this->locations()->syncWithoutDetaching([
                $location->id => [
                    'active' => $active,
                    'current_stock' => 0,
                    'minimum_stock' => 0,
                ]
            ]);
        }
    }

    /**
     * Activate product in specific location.
     */
    public function activateInLocation($locationId, $active = true)
    {
        $this->locations()->syncWithoutDetaching([
            $locationId => [
                'active' => $active,
                'current_stock' => 0,
                'minimum_stock' => 0,
            ]
        ]);
    }

    /**
     * Get all images for the product.
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * Get the main image of the product.
     */
    public function mainImage()
    {
        return $this->hasOne(ProductImage::class)->where('image_type', 'main');
    }

    /**
     * Get gallery images of the product.
     */
    public function galleryImages()
    {
        return $this->hasMany(ProductImage::class)
            ->where('image_type', 'gallery')
            ->where('show_in_catalog', true)
            ->orderBy('sort_order');
    }

    /**
     * Get public images of the product.
     */
    public function publicImages()
    {
        return $this->hasMany(ProductImage::class)
            ->where('is_public', true)
            ->orderBy('sort_order');
    }

    /**
     * Get packages of the product.
     */
    public function packages()
    {
        return $this->hasMany(ProductPackage::class)->orderBy('sort_order');
    }

    /**
     * Get active packages of the product.
     */
    public function activePackages()
    {
        return $this->hasMany(ProductPackage::class)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Get default package of the product.
     */
    public function defaultPackage()
    {
        return $this->hasOne(ProductPackage::class)
            ->where('is_default', true)
            ->where('is_active', true);
    }
}
