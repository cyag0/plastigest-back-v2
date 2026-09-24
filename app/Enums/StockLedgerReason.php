<?php

namespace App\Enums;

/**
 * Por qué se movió el stock.
 *
 * Cada flujo que toca inventario declara su razón al pedir el cambio, y es lo
 * que permite leer la trazabilidad de un producto sin adivinar a partir del
 * documento de origen. La lista es cerrada a propósito: si aparece un flujo
 * nuevo, se agrega aquí y no como texto libre.
 */
enum StockLedgerReason: string
{
    case PURCHASE = 'purchase';
    case PURCHASE_RETURN = 'purchase_return';
    case SALE = 'sale';
    case SALE_RETURN = 'sale_return';
    case PRODUCTION_CONSUMPTION = 'production_consumption';
    case PRODUCTION_OUTPUT = 'production_output';
    case PACKAGING_CONSUMPTION = 'packaging_consumption';
    case PACKAGING_OUTPUT = 'packaging_output';
    case TRANSFER_OUT = 'transfer_out';
    case TRANSFER_IN = 'transfer_in';
    case ADJUSTMENT = 'adjustment';
    case COUNT = 'count';
    case INITIAL = 'initial';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Compra',
            self::PURCHASE_RETURN => 'Devolución a proveedor',
            self::SALE => 'Venta',
            self::SALE_RETURN => 'Devolución de venta',
            self::PRODUCTION_CONSUMPTION => 'Consumo de producción',
            self::PRODUCTION_OUTPUT => 'Producción terminada',
            self::PACKAGING_CONSUMPTION => 'Consumo de empaque',
            self::PACKAGING_OUTPUT => 'Producto empacado',
            self::TRANSFER_OUT => 'Traspaso enviado',
            self::TRANSFER_IN => 'Traspaso recibido',
            self::ADJUSTMENT => 'Ajuste de inventario',
            self::COUNT => 'Conteo físico',
            self::INITIAL => 'Existencia inicial',
            self::OTHER => 'Otro',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PURCHASE, self::SALE_RETURN => 'cart-arrow-down',
            self::PURCHASE_RETURN => 'cart-arrow-up',
            self::SALE => 'cash-register',
            self::PRODUCTION_CONSUMPTION, self::PACKAGING_CONSUMPTION => 'factory',
            self::PRODUCTION_OUTPUT => 'package-variant-closed',
            self::PACKAGING_OUTPUT => 'package-variant',
            self::TRANSFER_OUT => 'truck-delivery',
            self::TRANSFER_IN => 'truck-check',
            self::ADJUSTMENT => 'tune-variant',
            self::COUNT => 'clipboard-list',
            self::INITIAL => 'flag-outline',
            self::OTHER => 'swap-vertical',
        };
    }
}
