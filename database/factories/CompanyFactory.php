<?php

namespace Database\Factories;

use App\Models\Admin\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name'          => fake()->company(),
            'business_name' => fake()->company() . ' S.A. de C.V.',
            'rfc'           => strtoupper(fake()->bothify('???######???')),
            'address'       => fake()->address(),
            'phone'         => fake()->phoneNumber(),
            'email'         => fake()->companyEmail(),
            'is_active'     => true,
        ];
    }
}
