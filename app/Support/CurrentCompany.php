<?php

namespace App\Support;

use App\Models\Admin\Company;

class CurrentCompany
{
    private static ?Company $company = null;

    /**
     * Establece la empresa actual
     */
    public static function set(?Company $company): void
    {
        self::$company = $company;
    }

    /**
     * Obtiene la empresa actual
     */
    public static function get(): ?Company
    {
        return self::$company;
    }

    /**
     * Obtiene el ID de la empresa actual
     */
    public static function id(): ?int
    {
        return self::$company?->id;
    }

    /**
     * Verifica si hay una empresa establecida
     */
    public static function exists(): bool
    {
        return self::$company !== null;
    }

    /**
     * Limpia la empresa actual
     */
    public static function clear(): void
    {
        self::$company = null;
    }

    /**
     * Obtiene el nombre de la empresa actual
     */
    public static function name(): ?string
    {
        return self::$company?->name;
    }
}
