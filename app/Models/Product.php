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
        'product_type',
        'is_active',
        'for_sale',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
        'for_sale' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Constantes para product_type
    const PRODUCT_TYPE_RAW_MATERIAL = 'raw_material';
    const PRODUCT_TYPE_PROCESSED = 'processed';
    const PRODUCT_TYPE_COMMERCIAL = 'commercial';

    const PRODUCT_TYPES = [
        self::PRODUCT_TYPE_RAW_MATERIAL => 'Materia Prima',
        self::PRODUCT_TYPE_PROCESSED => 'Producto Procesado',
        self::PRODUCT_TYPE_COMMERCIAL => 'Producto Comercial',
    ];

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
     * Get available units for this product (base unit + derived units)
     */
    public function availableUnits()
    {
        return Unit::where(function ($query) {
            $query->where('id', $this->unit_id)
                  ->orWhere('base_unit_id', $this->unit_id);
        })->get();
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
     * Get the locations that have this product with pivot 'active'.
     */
    public function locations()
    {
        return $this->belongsToMany(Location::class, 'product_location')
            ->withPivot(['current_stock', 'minimum_stock', 'active', 'maximum_stock'])
            ->withTimestamps();
    }

    /**
     * Get active locations for this product.
     */
    public function activeLocations()
    {
        return $this->belongsToMany(\App\Models\Admin\Location::class, 'product_location')
            ->wherePivot('active', true)
            ->withPivot(['current_stock', 'minimum_stock', 'active'])
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
}
