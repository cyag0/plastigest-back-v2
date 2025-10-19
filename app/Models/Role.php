<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
        'active',
        'company_id'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the company that owns the role.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The workers that belong to this role.
     */
    public function workers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class, 'worker_roles');
    }
}
