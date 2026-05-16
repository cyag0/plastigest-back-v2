<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
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

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Default configuration for every event type.
     * Used when the company has not customized their preferences.
     */
    public static function getDefaults(): array
    {
        return [
            'low_stock' => [
                'permission_name' => 'inventory_manage',
                'channel_db'      => true,
                'channel_email'   => true,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'inventory_adjustment' => [
                'permission_name' => 'inventory_manage',
                'channel_db'      => true,
                'channel_email'   => true,
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
                'channel_email'   => true,
                'channel_push'    => true,
                'is_active'       => true,
            ],
            'task_event' => [
                'permission_name' => '',  // task_event always goes to a specific userId
                'channel_db'      => true,
                'channel_email'   => true,
                'channel_push'    => true,
                'is_active'       => true,
            ],
        ];
    }
}
