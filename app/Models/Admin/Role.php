<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @mixin IdeHelperRole
 */
class Role extends Model
{
    //
    protected $fillable = [
        'name',
        'description',
        'is_system',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'rol_permission', 'role_id', 'permission_id');
    }

    public function workers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class, 'worker_roles');
    }
}
