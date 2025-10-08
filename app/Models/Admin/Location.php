<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'address',
        'phone',
        'email',
        'company_id',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
