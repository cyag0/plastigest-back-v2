<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'device_type',
        'device_name',
        'app_version',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /**
     * Usuario dueño del token
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Actualizar el último uso del token
     */
    public function updateLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Desactivar el token
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Scope para tokens activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para un usuario específico
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
