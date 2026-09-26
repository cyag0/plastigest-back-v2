<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperCompany
 */
class Company extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    /** Las factories viven en Database\Factories, sin la subcarpeta Admin. */
    protected static function newFactory()
    {
        return \Database\Factories\CompanyFactory::new();
    }

    protected $fillable = [
        'name',
        'business_name',
        'rfc',
        'address',
        'phone',
        'email',
        'is_active',
    ];

    /**
     * Relación con usuarios a través de user_company pivot
     */
    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_company',
            'company_id',
            'user_id'
        )->withTimestamps();
    }
}
