<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperCompany
 */
class Company extends Model
{
    protected $fillable = [
        'name',
        'business_name',
        'rfc',
        'address',
        'phone',
        'email',
        'is_active',
    ];
}
