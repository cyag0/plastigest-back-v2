<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'is_active',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
    /*     public function unit()
    {
        return $this->belongsTo(Unit::class);
    } */
}
