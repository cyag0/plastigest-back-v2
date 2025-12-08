<?php

namespace App\Enums;

enum Resources: string
{
    case USERS = 'users';
    case ROLES = 'roles';
    case PERMISSIONS = 'permissions';
    case COMPANIES = 'companies';
    case LOCATIONS = 'locations';
    case CUSTOMERS = 'customers';
    case SUPPLIERS = 'suppliers';
    case UNITS = 'units';
    case CATEGORIES = 'categories';
    case PRODUCTS = 'products';
    case INVENTORY = 'inventory';
    case MOVEMENTS = 'movements';
    case SALES = 'sales';
    case PURCHASES = 'purchases';
    case REPORTS = 'reports';
    case DASHBOARD = 'dashboard';
    case SETTINGS = 'settings';
    case WORKERS = 'workers';

    /**
     * Obtener el label legible del recurso
     */
    public function label(): string
    {
        return match ($this) {
            self::USERS => 'Usuarios',
            self::ROLES => 'Roles',
            self::PERMISSIONS => 'Permisos',
            self::COMPANIES => 'Empresas',
            self::LOCATIONS => 'Ubicaciones',
            self::CUSTOMERS => 'Clientes',
            self::SUPPLIERS => 'Proveedores',
            self::UNITS => 'Unidades',
            self::CATEGORIES => 'Categorías',
            self::PRODUCTS => 'Productos',
            self::INVENTORY => 'Inventario',
            self::MOVEMENTS => 'Movimientos',
            self::SALES => 'Ventas',
            self::PURCHASES => 'Compras',
            self::REPORTS => 'Reportes',
            self::DASHBOARD => 'Panel Principal',
            self::SETTINGS => 'Configuración',
            self::WORKERS => 'Trabajadores',
        };
    }

    /**
     * Obtener la descripción del recurso
     */
    public function description(): string
    {
        return match ($this) {
            self::USERS => 'Gestión de usuarios del sistema',
            self::ROLES => 'Administración de roles y permisos',
            self::PERMISSIONS => 'Control de permisos específicos',
            self::COMPANIES => 'Gestión de empresas',
            self::LOCATIONS => 'Administración de ubicaciones',
            self::CUSTOMERS => 'Gestión de clientes',
            self::SUPPLIERS => 'Administración de proveedores',
            self::UNITS => 'Unidades de medida',
            self::CATEGORIES => 'Categorías de productos',
            self::PRODUCTS => 'Catálogo de productos',
            self::INVENTORY => 'Control de inventario',
            self::MOVEMENTS => 'Movimientos de inventario',
            self::SALES => 'Gestión de ventas',
            self::PURCHASES => 'Administración de compras',
            self::REPORTS => 'Generación de reportes',
            self::DASHBOARD => 'Tablero de control principal',
            self::SETTINGS => 'Configuraciones del sistema',
            self::WORKERS => 'Gestión de trabajadores de la empresa',
        };
    }

    /**
     * Obtener el icono sugerido para el recurso
     */
    public function icon(): string
    {
        return match ($this) {
            self::USERS => 'account-group',
            self::ROLES => 'shield-account',
            self::PERMISSIONS => 'lock',
            self::COMPANIES => 'office-building',
            self::LOCATIONS => 'map-marker',
            self::CUSTOMERS => 'account-heart',
            self::SUPPLIERS => 'truck-delivery',
            self::UNITS => 'scale-balance',
            self::CATEGORIES => 'tag-multiple',
            self::PRODUCTS => 'package-variant',
            self::INVENTORY => 'warehouse',
            self::MOVEMENTS => 'swap-horizontal',
            self::SALES => 'cash-register',
            self::PURCHASES => 'shopping',
            self::REPORTS => 'chart-line',
            self::DASHBOARD => 'view-dashboard',
            self::SETTINGS => 'cog',
            self::WORKERS => 'account-hard-hat',
        };
    }

    /**
     * Obtener todos los recursos como array
     */
    public static function all(): array
    {
        return array_map(function ($case) {
            return [
                'key' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'icon' => $case->icon(),
            ];
        }, self::cases());
    }

    /**
     * Obtener recursos principales para formularios de roles
     */
    public static function mainResources(): array
    {
        $mainResources = [
            self::USERS,
            self::ROLES,
            self::CUSTOMERS,
            self::SUPPLIERS,
            self::PRODUCTS,
            self::CATEGORIES,
            self::INVENTORY,
            self::SALES,
            self::PURCHASES,
            self::REPORTS,
        ];

        return array_map(function ($resource) {
            return [
                'key' => $resource->value,
                'label' => $resource->label(),
                'description' => $resource->description(),
                'icon' => $resource->icon(),
            ];
        }, $mainResources);
    }

    /**
     * Crear nombre de permiso para un recurso y acción
     */
    public function permission(string $action): string
    {
        return $this->value . '_' . $action;
    }

    /**
     * Obtener permisos CRUD para un recurso
     */
    public function crudPermissions(): array
    {
        return [
            $this->permission('create') => "Crear {$this->label()}",
            $this->permission('read') => "Ver {$this->label()}",
            $this->permission('update') => "Actualizar {$this->label()}",
            $this->permission('delete') => "Eliminar {$this->label()}",
            $this->permission('list') => "Listar {$this->label()}",
        ];
    }

    /**
     * Generar todos los permisos CRUD para todos los recursos
     */
    public static function generateAllCrudPermissions(): array
    {
        $permissions = [];

        foreach (self::cases() as $resource) {
            $permissions = array_merge($permissions, $resource->crudPermissions());
        }

        // Agregar permisos especiales
        $specialPermissions = [
            'dashboard_view' => 'Ver Dashboard',
            'reports_export' => 'Exportar Reportes',
            'settings_manage' => 'Gestionar Configuración',
            'system_admin' => 'Administrador del Sistema',
        ];

        return array_merge($permissions, $specialPermissions);
    }
}
