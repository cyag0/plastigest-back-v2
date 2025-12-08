<?php

namespace App\Models;

use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'location_id',
        'title',
        'description',
        'type',
        'status',
        'priority',
        'assigned_to',
        'assigned_users',
        'assigned_by',
        'completed_by',
        'due_date',
        'started_at',
        'completed_at',
        'related_type',
        'related_id',
        'is_recurring',
        'recurrence_frequency',
        'recurrence_day',
        'recurrence_time',
        'last_generated_at',
        'next_occurrence',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'assigned_users' => 'array',
        'due_date' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_generated_at' => 'datetime',
        'next_occurrence' => 'datetime',
        'is_recurring' => 'boolean',
    ];

    protected $appends = ['is_overdue'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeAssignedToAny($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('assigned_to', $userId)
                ->orWhereJsonContains('assigned_users', $userId);
        });
    }

    public function scopeForLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', today());
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date && $this->due_date->isPast() && !in_array($this->status, ['completed', 'cancelled']);
    }

    public function isAssignedToUser(int $userId): bool
    {
        if ($this->assigned_to === $userId) {
            return true;
        }

        if (!$this->assigned_users) {
            return false;
        }

        return in_array($userId, $this->assigned_users);
    }

    public function start(User $user): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return true;
    }

    public function complete(User $user): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);

        if ($this->is_recurring) {
            $this->generateNextOccurrence();
        }

        return true;
    }

    public function cancel(): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->update(['status' => 'cancelled']);
        return true;
    }

    public function generateNextOccurrence(): void
    {
        if (!$this->is_recurring || !$this->recurrence_frequency) {
            return;
        }

        $nextDate = match ($this->recurrence_frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'biweekly' => now()->addWeeks(2),
            'monthly' => now()->addMonth(),
            default => null,
        };

        if ($nextDate && $this->recurrence_time) {
            [$hour, $minute] = explode(':', $this->recurrence_time);
            $nextDate->setTime((int)$hour, (int)$minute);
        }

        $newTask = $this->replicate();
        $newTask->status = 'pending';
        $newTask->started_at = null;
        $newTask->completed_at = null;
        $newTask->completed_by = null;
        $newTask->due_date = $nextDate;
        $newTask->save();

        $this->update([
            'last_generated_at' => now(),
            'next_occurrence' => $nextDate,
        ]);
    }
}
