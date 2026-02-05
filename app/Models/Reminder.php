<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Reminder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'location_id',
        'user_id',
        'title',
        'description',
        'type',
        'reminder_date',
        'reminder_time',
        'status',
        'completed_at',
        'is_recurring',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_end_date',
        'notify_enabled',
        'notify_days_before',
        'last_notified_at',
        'supplier_id',
        'product_id',
        'amount',
    ];

    protected $casts = [
        'reminder_date' => 'date',
        'reminder_time' => 'datetime',
        'completed_at' => 'datetime',
        'recurrence_end_date' => 'date',
        'last_notified_at' => 'datetime',
        'is_recurring' => 'boolean',
        'notify_enabled' => 'boolean',
        'amount' => 'decimal:2',
    ];

    protected $appends = ['type_label', 'status_label', 'is_overdue', 'days_until_due'];

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function location()
    {
        return $this->belongsTo(Admin\Location::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Accessors
    public function getTypeLabelAttribute()
    {
        $types = [
            'payment' => 'Pago a Proveedor',
            'renewal' => 'Renovación de Servicio',
            'expiration' => 'Vencimiento de Producto',
            'other' => 'Otro',
        ];
        return $types[$this->type] ?? $this->type;
    }

    public function getStatusLabelAttribute()
    {
        $statuses = [
            'pending' => 'Pendiente',
            'completed' => 'Completado',
            'overdue' => 'Vencido',
        ];
        return $statuses[$this->status] ?? $this->status;
    }

    public function getIsOverdueAttribute()
    {
        if ($this->status === 'completed') {
            return false;
        }
        return Carbon::parse($this->reminder_date)->isPast();
    }

    public function getDaysUntilDueAttribute()
    {
        if ($this->status === 'completed') {
            return null;
        }
        return Carbon::now()->diffInDays(Carbon::parse($this->reminder_date), false);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
            ->whereDate('reminder_date', '<', now());
    }

    public function scopeUpcoming($query, $days = 7)
    {
        return $query->where('status', 'pending')
            ->whereDate('reminder_date', '>=', now())
            ->whereDate('reminder_date', '<=', now()->addDays($days));
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Methods
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Si es recurrente, crear el siguiente recordatorio
        if ($this->is_recurring) {
            $this->createNextRecurrence();
        }
    }

    public function createNextRecurrence()
    {
        if (!$this->is_recurring || !$this->recurrence_type) {
            return null;
        }

        $nextDate = Carbon::parse($this->reminder_date);

        switch ($this->recurrence_type) {
            case 'daily':
                $nextDate->addDays($this->recurrence_interval);
                break;
            case 'weekly':
                $nextDate->addWeeks($this->recurrence_interval);
                break;
            case 'monthly':
                $nextDate->addMonths($this->recurrence_interval);
                break;
            case 'yearly':
                $nextDate->addYears($this->recurrence_interval);
                break;
        }

        // No crear si supera la fecha de fin
        if ($this->recurrence_end_date && $nextDate->isAfter($this->recurrence_end_date)) {
            return null;
        }

        return self::create([
            'company_id' => $this->company_id,
            'location_id' => $this->location_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'reminder_date' => $nextDate,
            'reminder_time' => $this->reminder_time,
            'status' => 'pending',
            'is_recurring' => true,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_end_date' => $this->recurrence_end_date,
            'notify_enabled' => $this->notify_enabled,
            'notify_days_before' => $this->notify_days_before,
            'supplier_id' => $this->supplier_id,
            'product_id' => $this->product_id,
            'amount' => $this->amount,
        ]);
    }

    public static function getTypes()
    {
        return [
            'payment' => 'Pago a Proveedor',
            'renewal' => 'Renovación de Servicio',
            'expiration' => 'Vencimiento de Producto',
            'other' => 'Otro',
        ];
    }

    public static function getRecurrenceTypes()
    {
        return [
            'daily' => 'Diario',
            'weekly' => 'Semanal',
            'monthly' => 'Mensual',
            'yearly' => 'Anual',
        ];
    }
}
