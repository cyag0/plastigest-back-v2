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
