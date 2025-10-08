<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'employee_number',
        'position',
        'department',
        'hire_date',
        'salary',
        'is_active',
    ];

    protected $dates = [
        'hire_date',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relaciones
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
