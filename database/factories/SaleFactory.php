<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Admin\Company;
use App\Models\Admin\Location;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 5000);
        $tax      = round($subtotal * 0.16, 2);
        $total    = $subtotal + $tax;

        return [
            'company_id'     => Company::factory(),
            'location_id'    => Location::factory(),
            'user_id'        => User::factory(),
            'sale_date'      => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'status'         => SaleStatus::CLOSED,
            'subtotal'       => $subtotal,
            'tax'            => $tax,
            'discount'       => 0,
            'total'          => $total,
            'payment_method' => fake()->randomElement(['cash', 'card', 'transfer']),
            'payment_status' => 'pending',
            'paid_amount'    => 0,
            'content'        => [
                'customer_name'  => fake()->name(),
                'customer_phone' => fake()->phoneNumber(),
                'customer_email' => fake()->safeEmail(),
            ],
        ];
    }

    public function closed(): static
    {
        return $this->state(['status' => SaleStatus::CLOSED]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => SaleStatus::CANCELLED]);
    }
}
