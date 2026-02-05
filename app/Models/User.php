<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Admin\Worker;
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
     * Relación con workers (un usuario puede ser worker en varias empresas)
     */
    public function workers()
    {
        return $this->hasMany(Worker::class);
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
     * Obtener el worker activo para una empresa específica
     */
    public function getWorkerForCompany(int $companyId): ?Worker
    {
        return $this->workers()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();
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
