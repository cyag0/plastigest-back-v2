<?php

namespace App\Models\Admin;

use App\Models\Admin\Location;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @mixin IdeHelperWorker
 */
class Worker extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'role_id',
        'position',
        'department',
        'hire_date',
        'salary',
        'is_active',
    ];

    protected $dates = [
        'hire_date',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Relación con la empresa
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el rol
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Relación con sucursales (locations)
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(
            Location::class,
            'location_worker',
            'worker_id',
            'location_id'
        )->withTimestamps();
    }

    /**
     * Scope para filtrar workers activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para filtrar por empresa
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope para filtrar por usuario
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
