<?php

namespace App\Models;

use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un renglón de la bitácora. Se escribe con App\Support\Activity\ActivityLogger.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'location_id',
        'user_id',
        'user_name',
        'module',
        'event',
        'subject_type',
        'subject_id',
        'subject_label',
        'description',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
