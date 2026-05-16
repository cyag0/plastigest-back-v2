<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Admin\LocationResource;
use App\Http\Resources\Admin\UserResource;

class CashClosingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'company_id'       => $this->company_id,
            'location_id'      => $this->location_id,
            'user_id'          => $this->user_id,
            'closing_date'     => $this->closing_date?->format('Y-m-d'),
            'opening_balance'  => $this->opening_balance,
            'total_income'     => $this->total_income,
            'total_expense'    => $this->total_expense,
            'expected_balance' => $this->expected_balance,
            'physical_count'   => $this->physical_count,
            'difference'       => $this->difference,
            'total_cash'       => $this->total_cash,
            'total_card'       => $this->total_card,
            'total_transfer'   => $this->total_transfer,
            'total_other'      => $this->total_other,
            'movements_count'  => $this->movements_count,
            'notes'            => $this->notes,
            'status'           => $this->status,
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),

            'location' => new LocationResource($this->whenLoaded('location')),
            'user'     => new UserResource($this->whenLoaded('user')),
        ];
    }
}
