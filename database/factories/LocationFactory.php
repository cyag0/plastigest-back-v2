<?php

namespace Database\Factories;

use App\Models\Admin\Company;
use App\Models\Admin\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name'       => fake()->city() . ' - ' . fake()->streetName(),
            'description' => fake()->sentence(),
            'is_active'  => true,
            'address'    => fake()->address(),
            'phone'      => fake()->phoneNumber(),
            'email'      => fake()->companyEmail(),
            'company_id' => Company::factory(),
        ];
    }
}
