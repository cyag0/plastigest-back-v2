<?php

namespace App\Enums;

enum Actions: string
{
    case CREATE = 'create';
    case READ = 'read';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case LIST = 'list';
    case EXPORT = 'export';
    case IMPORT = 'import';
    case VIEW = 'view';
    case MANAGE = 'manage';
    case ADMIN = 'admin';

    /**
     * Obtener el label legible de la acción
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATE => 'Crear',
            self::READ => 'Leer',
            self::UPDATE => 'Actualizar',
            self::DELETE => 'Eliminar',
            self::LIST => 'Listar',
            self::EXPORT => 'Exportar',
            self::IMPORT => 'Importar',
            self::VIEW => 'Ver',
            self::MANAGE => 'Gestionar',
            self::ADMIN => 'Administrar',
        };
    }

    /**
     * Obtener la descripción de la acción
     */
    public function description(): string
    {
        return match ($this) {
            self::CREATE => 'Crear nuevos registros',
            self::READ => 'Ver información existente',
            self::UPDATE => 'Modificar registros existentes',
            self::DELETE => 'Eliminar registros',
            self::LIST => 'Listar y buscar registros',
            self::EXPORT => 'Exportar datos',
            self::IMPORT => 'Importar datos',
            self::VIEW => 'Ver información general',
            self::MANAGE => 'Gestionar configuraciones',
            self::ADMIN => 'Administrar completamente',
        };
    }

    /**
     * Obtener acciones CRUD básicas
     */
    public static function crud(): array
    {
        return [
            self::CREATE,
            self::READ,
            self::UPDATE,
            self::DELETE,
            self::LIST,
        ];
    }

    /**
     * Obtener todas las acciones como array
     */
    public static function all(): array
    {
        return array_map(function ($case) {
            return [
                'key' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
            ];
        }, self::cases());
    }
}
