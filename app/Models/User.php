<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Admin\Location;
use App\Models\Admin\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Sucursales asignadas al usuario con su rol por sucursal
     */
    public function locationRoles()
    {
        return $this->belongsToMany(
            Location::class,
            'user_location_roles',
            'user_id',
            'location_id'
        )->withPivot('role_id')->withTimestamps();
    }

    /**
     * Roles asignados directamente al usuario (tabla pivot users_roles)
     */
    public function roles()
    {
        return $this->belongsToMany(
            Role::class,
            'users_roles',
            'user_id',
            'role_id'
        )->withTimestamps();
    }

    /**
     * Relación directa con empresas a través de user_company pivot
     */
    public function companies()
    {
        return $this->belongsToMany(
            Company::class,
            'user_company',
            'user_id',
            'company_id'
        )->withTimestamps();
    }

    /**
     * Tareas asignadas al usuario
     */
    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * Tareas creadas por el usuario
     */
    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'assigned_by');
    }

    /**
     * Tareas completadas por el usuario
     */
    public function completedTasks()
    {
        return $this->hasMany(Task::class, 'completed_by');
    }
}
