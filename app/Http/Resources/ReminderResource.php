<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'type_label' => $this->type_label,
            'reminder_date' => $this->reminder_date?->toDateString(),
            'reminder_time' => $this->reminder_time?->format('H:i'),
            'status' => $this->status,
            'status_label' => $this->status_label,
            'completed_at' => $this->completed_at,
            'is_recurring' => $this->is_recurring,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_end_date' => $this->recurrence_end_date?->toDateString(),
            'notify_enabled' => $this->notify_enabled,
            'notify_days_before' => $this->notify_days_before,
            'last_notified_at' => $this->last_notified_at,
            'supplier_id' => $this->supplier_id,
            'product_id' => $this->product_id,
            'amount' => $this->amount,
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            
            // Relations
            'company' => $this->whenLoaded('company'),
            'location' => $this->whenLoaded('location'),
            'user' => $this->whenLoaded('user'),
            'supplier' => $this->whenLoaded('supplier'),
            'product' => $this->whenLoaded('product'),
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
