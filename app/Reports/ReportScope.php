<?php

namespace App\Reports;

use App\Models\Admin\Company;
use App\Models\Admin\Location;

/**
 * De quién es el reporte: la empresa y una sucursal, o todas las sucursales
 * de la empresa (location = null).
 */
final class ReportScope
{
    public function __construct(
        public readonly Company $company,
        public readonly ?Location $location = null,
    ) {}

    public static function for(int $companyId, ?int $locationId): self
    {
        $company = Company::findOrFail($companyId);
        $location = $locationId
            ? Location::where('company_id', $companyId)->findOrFail($locationId)
            : null;

        return new self($company, $location);
    }

    public function companyId(): int
    {
        return (int) $this->company->id;
    }

    public function locationId(): ?int
    {
        return $this->location?->id;
    }

    public function isAllLocations(): bool
    {
        return $this->location === null;
    }

    public function locationLabel(): string
    {
        return $this->location?->name ?? 'Todas las sucursales';
    }
}
