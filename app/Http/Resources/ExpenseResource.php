<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
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
            'category' => $this->category,
            'category_label' => $this->category_label,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method_label,
            'paid_from_cash' => (bool) ($this->paid_from_cash ?? true),
            // El turno de la caja de donde salió el efectivo (al ver o guardar el gasto).
            'cash_session' => $this->whenLoaded('cashSession', fn() => $this->cashSession ? [
                'id' => $this->cashSession->id,
                'status' => $this->cashSession->status,
                'cash_register_name' => $this->cashSession->cashRegister?->name,
                'user_name' => $this->cashSession->user?->name,
            ] : null),
            'description' => $this->description,
            'expense_date' => $this->expense_date->format('Y-m-d'),
            'receipt_image' => $this->receipt_image ? asset('storage/' . $this->receipt_image) : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Relaciones
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                ];
            }),
            'company' => $this->whenLoaded('company', function () {
                return [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                ];
            }),
        ];
    }
}
