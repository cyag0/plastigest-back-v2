<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackage extends Model
{
    protected $fillable = [
        'product_id',
        'company_id',
        'package_name',
        'barcode',
        'quantity_per_package',
        'purchase_price',
        'sale_price',
        'content',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'quantity_per_package' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'content' => 'array', // JSON a array
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = [
        'display_name',
    ];

    /**
     * Relación con el producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relación con la compañía
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Admin\Company::class);
    }

    /**
     * Accessor para nombre de visualización
     */
    public function getDisplayNameAttribute(): string
    {
        return "{$this->package_name} ({$this->quantity_per_package} uds)";
    }

    /**
     * Scope para empaques activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para buscar por código de barras
     */
    public function scopeByBarcode($query, string $barcode)
    {
        return $query->where('barcode', $barcode);
    }
}
