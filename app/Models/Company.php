<?php

namespace App\Models;

use App\Models\Admin\Worker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperCompany
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_name',
        'rfc',
        'email',
        'phone',
        'address',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relación con workers
     */
    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * Relación con unidades
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * Relación directa con usuarios a través de user_company pivot
     */
    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_company',
            'company_id',
            'user_id'
        )->withTimestamps();
    }
}