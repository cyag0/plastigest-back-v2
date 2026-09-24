<?php

namespace App\Support;

use App\Enums\StockLedgerReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * El "por qué" de un cambio de stock.
 *
 * MovementService sabe mover existencias pero no de dónde viene la orden, y sin
 * eso el registro de trazabilidad no dice nada útil. Los flujos arman este
 * contexto y lo pasan junto con la cantidad.
 *
 * Es opcional a propósito: un cambio sin contexto se registra igual, como OTHER.
 * Es preferible un renglón pobre a un hueco en el historial.
 */
class StockContext
{
    public function __construct(
        public readonly StockLedgerReason $reason = StockLedgerReason::OTHER,
        /** Documento que originó el movimiento (venta, compra, orden de producción…). */
        public readonly ?Model $reference = null,
        public readonly ?string $notes = null,
        /** Costo unitario en la unidad base del producto, si el flujo lo conoce. */
        public readonly ?float $unitCost = null,
        /** Quién lo provocó. Por defecto, el usuario autenticado. */
        public readonly ?int $userId = null,
        /** Contraparte en traspasos: la otra sucursal. */
        public readonly ?int $counterpartLocationId = null,
    ) {}

    public static function for(StockLedgerReason $reason, ?Model $reference = null, ?string $notes = null): self
    {
        return new self(reason: $reason, reference: $reference, notes: $notes);
    }

    public function resolvedUserId(): ?int
    {
        return $this->userId ?? Auth::id();
    }
}
