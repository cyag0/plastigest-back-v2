<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

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
        'is_active',
        'company_id',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'rol_permission', 'role_id', 'permission_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ─── Activo = da sus permisos ───────────────────────────────────────────

    /**
     * ¿El rol da sus permisos a quienes lo tienen? Uno desactivado no da nada,
     * igual que si se hubiera borrado (antes solo dejaba de salir en el
     * selector de roles y seguía dando todo). El rol del sistema (Super
     * Admin) siempre cuenta, aunque estuviera desactivado: si no, nadie
     * podría volver a entrar a activarlo.
     *
     * Todo lo que decide "quién tiene el permiso X" (CurrentWorker, avisos,
     * tareas, destinatarios) usa esta regla; en SQL, grantsAccessCondition().
     */
    public function grantsAccess(): bool
    {
        return (bool) $this->is_system || (bool) $this->is_active;
    }

    /**
     * La regla de grantsAccess() para una consulta que tiene la tabla roles
     * (o un alias): ->where(Role::grantsAccessCondition()).
     */
    public static function grantsAccessCondition(string $table = 'roles'): \Closure
    {
        return fn($query) => $query->where("{$table}.is_active", true)->orWhere("{$table}.is_system", true);
    }

    /** Solo los roles que dan sus permisos (grantsAccess()). */
    public function scopeGrantingAccess(Builder $query): void
    {
        $query->where(self::grantsAccessCondition($this->getTable()));
    }

    // ─── Quién lo tiene ─────────────────────────────────────────────────────

    /**
     * Personas que tienen el rol, cada una una vez: directo (users_roles) o
     * en alguna sucursal (user_location_roles). Subconsulta para un select de
     * roles (la lista de roles la pide en la misma consulta).
     */
    public static function usersCountSubquery(string $table = 'roles'): \Illuminate\Database\Query\Builder
    {
        return DB::table('users')
            ->selectRaw('count(*)')
            ->where(fn($query) => $query
                ->whereExists(fn($sub) => $sub->selectRaw('1')->from('users_roles')
                    ->whereColumn('users_roles.user_id', 'users.id')
                    ->whereColumn('users_roles.role_id', "{$table}.id"))
                ->orWhereExists(fn($sub) => $sub->selectRaw('1')->from('user_location_roles')
                    ->whereColumn('user_location_roles.user_id', 'users.id')
                    ->whereColumn('user_location_roles.role_id', "{$table}.id")));
    }

    /**
     * Ids de las personas que tienen el rol (directo o en alguna sucursal),
     * sin repetir.
     *
     * @return int[]
     */
    public function holderIds(): array
    {
        return DB::table('user_location_roles')->where('role_id', $this->getKey())->pluck('user_id')
            ->merge(DB::table('users_roles')->where('role_id', $this->getKey())->pluck('user_id'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
