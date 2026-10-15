<?php

namespace App\Models;

use App\Models\Admin\Company;
use App\Models\Admin\Location;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'event_type',
        'permission_name',
        'channel_db',
        'channel_email',
        'channel_push',
        'is_active',
        'allowed_user_ids',
    ];

    protected $casts = [
        'channel_db'       => 'boolean',
        'channel_email'    => 'boolean',
        'channel_push'     => 'boolean',
        'is_active'        => 'boolean',
        'allowed_user_ids' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * La preferencia que aplica a un aviso: la de la sucursal donde ocurrió
     * si la sucursal tiene una, si no la de la empresa. null = ninguna (se
     * usan los valores por defecto).
     */
    public static function effective(int $companyId, string $eventType, ?int $locationId = null): ?self
    {
        $rows = static::where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->where(fn($q) => $q->whereNull('location_id')
                ->when($locationId, fn($w) => $w->orWhere('location_id', $locationId)))
            ->get();

        return ($locationId ? $rows->firstWhere('location_id', $locationId) : null)
            ?? $rows->firstWhere('location_id', null);
    }

    /**
     * Default configuration for every event type.
     * Used when the company has not customized their preferences.
     */
    public static function getDefaults(): array
    {
        return [
            // Correo sólo para lo poco frecuente e importante: con correo en
            // todo, cada tarea asignada o cada producto bajo de cada sucursal
            // mandaba un email. La campana y el push siguen activos; cada
            // empresa puede encender el correo en Preferencias.
            'low_stock' => [
                'permission_name' => 'inventory_manage',
                'channel_db'      => true,
                'channel_email'   => false,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'inventory_adjustment' => [
                'permission_name' => 'inventory_manage',
                'channel_db'      => true,
                'channel_email'   => false,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'inventory_count_discrepancy' => [
                'permission_name' => 'inventory_manage',
                'channel_db'      => true,
                'channel_email'   => true,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'purchase_update' => [
                'permission_name' => 'purchases_manage',
                'channel_db'      => true,
                'channel_email'   => false,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'task_event' => [
                'permission_name' => '',  // task_event always goes to a specific userId
                'channel_db'      => true,
                'channel_email'   => false,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'transfer_update' => [
                'permission_name' => '',  // va a quien pidió / envió la transferencia
                'channel_db'      => true,
                'channel_email'   => false,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'reminder' => [
                'permission_name' => '',  // va al usuario asignado al recordatorio
                'channel_db'      => true,
                'channel_email'   => false,
                'channel_push'    => true,
                'is_active'       => true,
            ],
        ];
    }
}
