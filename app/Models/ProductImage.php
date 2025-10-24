<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'image_path',
        'original_name',
        'alt_text',
        'image_type',
        'sort_order',
        'size',
        'file_size',
        'mime_type',
        'is_public',
        'show_in_catalog',
        'metadata'
    ];


    protected $casts = [
        'is_public' => 'boolean',
        'show_in_catalog' => 'boolean',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'file_size' => 'integer',
    ];

    // Relaciones
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Accessors
    public function getFullUrlAttribute(): string
    {
        return Storage::url($this->image_path);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    // Scopes
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeMainImage($query)
    {
        return $query->where('image_type', 'main');
    }

    public function scopeGallery($query)
    {
        return $query->where('image_type', 'gallery')->orderBy('sort_order');
    }

    public function scopeForCatalog($query)
    {
        return $query->where('show_in_catalog', true);
    }
}
