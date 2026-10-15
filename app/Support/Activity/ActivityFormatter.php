<?php

namespace App\Support\Activity;

use App\Models\Admin\Customer;
use App\Models\Admin\Location;
use App\Models\Category;
use App\Models\InventoryType;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Convierte valores y nombres de campo a texto para la bitácora.
 */
class ActivityFormatter
{
    /** Campos que nunca se cuentan en una edición (internos o sin valor para el usuario). */
    public const IGNORED = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at',
        'company_id', 'content', 'metadata', 'settings', 'totals', 'sort_order', 'avatar',
        'opened_at', 'closed_at', 'closed_by', 'applied_at', 'last_notified_at', 'is_system',
    ];

    /** Llaves foráneas que se muestran por nombre. */
    private const RELATED = [
        'category_id' => Category::class,
        'supplier_id' => Supplier::class,
        'customer_id' => Customer::class,
        'unit_id' => Unit::class,
        'location_id' => Location::class,
        'from_location_id' => Location::class,
        'to_location_id' => Location::class,
        'inventory_type_id' => InventoryType::class,
        'assigned_to' => User::class,
    ];

    public static function value(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    public static function money(mixed $value): string
    {
        $n = (float) $value;

        return ($n < 0 ? '−' : '') . '$' . number_format(abs($n), 2);
    }

    public static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    }

    public static function fieldLabel(string $field): string
    {
        $key = "validation.attributes.{$field}";
        $label = trans($key);

        return $label !== $key ? $label : str_replace('_', ' ', preg_replace('/_id$/', '', $field));
    }

    public static function isMoneyField(string $field): bool
    {
        return (bool) preg_match('/(price|amount|total|subtotal|cost|cash|difference|discount)$/', $field);
    }

    /** Valor legible de un campo (para "antes → después"). */
    public static function display(string $field, mixed $value): string
    {
        $value = self::value($value);

        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value) || (str_starts_with($field, 'is_') && in_array($value, [0, 1, '0', '1'], true))) {
            return $value ? 'Sí' : 'No';
        }
        if ($value instanceof CarbonInterface) {
            return $value->format('d/m/Y');
        }
        if (isset(self::RELATED[$field]) && is_numeric($value)) {
            $model = self::RELATED[$field]::find($value);

            return $model?->name ?? "#{$value}";
        }
        if (self::isMoneyField($field) && is_numeric($value)) {
            return self::money($value);
        }
        if (is_numeric($value)) {
            return self::quantity($value);
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return mb_strimwidth((string) $value, 0, 80, '…');
    }
}
