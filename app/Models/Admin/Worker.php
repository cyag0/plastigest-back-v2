<?php

namespace App\Models\Admin;

use App\Models\User;
use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @mixin IdeHelperWorker
 */
class Worker extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
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

    // Relaciones
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relaciones many-to-many
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'worker_roles');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'worker_companies');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'worker_locations');
    }
}
