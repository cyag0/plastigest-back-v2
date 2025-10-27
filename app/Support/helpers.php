<?php

use App\Support\CurrentCompany;
use App\Support\CurrentLocation;
use App\Models\Admin\Company;
use App\Models\Admin\Location;

if (!function_exists('current_company')) {
    /**
     * Obtiene la empresa actual
     */
    function current_company(): ?Company
    {
        return CurrentCompany::get();
    }
}

if (!function_exists('current_company_id')) {
    /**
     * Obtiene el ID de la empresa actual
     */
    function current_company_id(): ?int
    {
        return CurrentCompany::id();
    }
}

if (!function_exists('current_location')) {
    /**
     * Obtiene la sucursal actual
     */
    function current_location(): ?Location
    {
        return CurrentLocation::get();
    }
}

if (!function_exists('current_location_id')) {
    /**
     * Obtiene el ID de la sucursal actual
     */
    function current_location_id(): ?int
    {
        return CurrentLocation::id();
    }
}

if (!function_exists('set_current_company')) {
    /**
     * Establece la empresa actual
     */
    function set_current_company(?Company $company): void
    {
        CurrentCompany::set($company);
    }
}

if (!function_exists('set_current_location')) {
    /**
     * Establece la sucursal actual
     */
    function set_current_location(?Location $location): void
    {
        CurrentLocation::set($location);
    }
}

if (!function_exists('clear_current_company')) {
    /**
     * Limpia la empresa actual
     */
    function clear_current_company(): void
    {
        CurrentCompany::clear();
    }
}

if (!function_exists('clear_current_location')) {
    /**
     * Limpia la sucursal actual
     */
    function clear_current_location(): void
    {
        CurrentLocation::clear();
    }
}
