<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Admin\CompanyResource;
use App\Http\Resources\Admin\LocationResource;
use App\Http\Resources\Admin\UserResource;

class SalesReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'location_id' => $this->location_id,
            'user_id' => $this->user_id,
            'report_date' => $this->report_date?->format('Y-m-d'),
            'total_sales' => $this->total_sales,
            'total_cash' => $this->total_cash,
            'total_card' => $this->total_card,
            'total_transfer' => $this->total_transfer,
            'transactions_count' => $this->transactions_count,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Relationships
            'company' => new CompanyResource($this->whenLoaded('company')),
            'location' => new LocationResource($this->whenLoaded('location')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
