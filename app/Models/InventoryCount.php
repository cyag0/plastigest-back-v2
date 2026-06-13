<?php

namespace App\Models;

use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'count_date',
        'location_id',
        'status',
        'user_id',
        'notes',
        'content',
    ];

    protected $casts = [
        'count_date' => 'date',
        'content' => 'array',
    ];

    /**
     * Relación con la ubicación
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Relación con el usuario que creó el conteo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con los detalles del conteo
     */
    public function details(): HasMany
    {
        return $this->hasMany(InventoryCountDetail::class);
    }

    /**
     * Ajustes de inventario generados al completar este conteo
     * (razón "Diferencia de Conteo"). El conteo es informativo; estos
     * ajustes son el registro auditable que afectó el stock.
     */
    public function adjustmentDetails(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentDetail::class, 'reference_id')
            ->where('reference_type', 'inventory_count');
    }

    /**
     * Scope para filtrar por ubicación
     */
    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Scope para filtrar por estado
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
