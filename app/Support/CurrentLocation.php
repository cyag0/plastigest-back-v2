<?php

namespace App\Support;

use App\Models\Admin\Location;

class CurrentLocation
{
    private static ?Location $location = null;

    /**
     * Establece la sucursal actual
     */
    public static function set(?Location $location): void
    {
        self::$location = $location;
    }

    /**
     * Obtiene la sucursal actual
     */
    public static function get(): ?Location
    {
        return self::$location;
    }

    /**
     * Obtiene el ID de la sucursal actual
     */
    public static function id(): ?int
    {
        return self::$location?->id;
    }

    /**
     * Verifica si hay una sucursal establecida
     */
    public static function exists(): bool
    {
        return self::$location !== null;
    }

    /**
     * Limpia la sucursal actual
     */
    public static function clear(): void
    {
        self::$location = null;
    }

    /**
     * Obtiene el nombre de la sucursal actual
     */
    public static function name(): ?string
    {
        return self::$location?->name;
    }

    /**
     * Obtiene la dirección de la sucursal actual
     */
    public static function address(): ?string
    {
        return self::$location?->address;
    }
}
