<?php

namespace App\Policies;

use App\Models\Admin\Location;
use App\Models\User;

class LocationPolicy
{
    /**
     * Determine if the user can view the location
     */
    public function view(User $user, Location $location): bool
    {
        // Check if user belongs to the same company
        return $user->workers()
            ->where('company_id', $location->company_id)
            ->exists();
    }

    /**
     * Determine if the user can update the location
     */
    public function update(User $user, Location $location): bool
    {
        // Check if user is an admin/manager for this company
        return $user->workers()
            ->where('company_id', $location->company_id)
            ->whereHas('role', function ($query) {
                $query->whereIn('name', ['Admin', 'Manager', 'Administrador', 'Gerente']);
            })
            ->exists();
    }

    /**
     * Determine if the user can delete the location
     */
    public function delete(User $user, Location $location): bool
    {
        // Only admins can delete locations
        return $user->workers()
            ->where('company_id', $location->company_id)
            ->whereHas('role', function ($query) {
                $query->whereIn('name', ['Admin', 'Administrador']);
            })
            ->exists();
    }
}
