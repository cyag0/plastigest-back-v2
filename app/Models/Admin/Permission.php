<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    //
    protected $fillable = [
        'name',
        'description',
        'resource',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'rol_permission', 'permission_id', 'role_id');
    }
}
