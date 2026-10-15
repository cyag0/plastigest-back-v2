<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta de ventas. La usan el punto de venta (con turno de caja y pagos por
 * método) y la pantalla de ventas anterior (formv2), que manda
 * payment_method + paid_amount.
 */
class SaleService
{
    /**
     * @param array $data {
     *   company_id, location_id, user_id, sale_date?, customer_id?,
     *   customer_name?, customer_phone?, customer_email?, notes?, comments?,
     *   document_number?,
     *   details: [{product_id, package_id?, unit_id, quantity, unit_price, discount?}],
     *   payments?: [{method: cash|card|transfer, amount, tendered?}],
     *   payment_method?, paid_amount?   (formato anterior, si no hay payments)
     * }
     */
    public function create(array $data, ?CashRegisterSession $session = null): Sale
    {
        return DB::transaction(function () use ($data, $session) {
            // Importes en centavos enteros (App\Support\Money), igual que la app
            // y que PosController: cada renglón se redondea mitad hacia arriba y
            // los totales son la suma exacta de los renglones.
            $lines = array_map(function ($d) {
                $amounts = Money::line($d['quantity'], $d['unit_price']);
                $subtotalCents = $amounts['subtotal'];
                $discountCents = min(Money::toCents($d['discount'] ?? 0), $subtotalCents);

                return array_merge($d, [
                    'quantity' => Money::roundQuantity($d['quantity']),
                    'unit_price' => Money::round($d['unit_price']),
                    'subtotal_cents' => $subtotalCents,
                    'discount_cents' => $discountCents,
                    'subtotal' => Money::fromCents($subtotalCents),
                    'discount' => Money::fromCents($discountCents),
                    'total' => Money::fromCents($subtotalCents - $discountCents),
                ]);
            }, $data['details']);

            $subtotalCents = array_sum(array_column($lines, 'subtotal_cents'));
            $discountCents = array_sum(array_column($lines, 'discount_cents'));
            $totalCents = $subtotalCents - $discountCents;
            $subtotal = Money::fromCents($subtotalCents);
            $discount = Money::fromCents($discountCents);
            $total = Money::fromCents($totalCents);

            $payments = $this->normalizePayments($data, $total);
            $paidCents = array_sum(array_map(fn($p) => Money::toCents($p['amount']), $payments));
            $paid = Money::fromCents($paidCents);

            $paymentMethod = count($payments) > 1
                ? 'mixed'
                : ($payments[0]['method'] ?? ($data['payment_method'] ?? 'cash'));

            $sale = Sale::create([
                'company_id' => $data['company_id'],
                'location_id' => $data['location_id'],
                'cash_register_id' => $session?->cash_register_id,
                'cash_register_session_id' => $session?->id,
                'user_id' => $data['user_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => 0,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'paid_amount' => $paid,
                'payment_status' => $paidCents >= $totalCents ? 'paid' : ($paidCents > 0 ? 'partial' : 'pending'),
                'payment_history' => $paid > 0 ? [[
                    'amount' => $paid,
                    'payment_method' => $paymentMethod,
                    'date' => now()->toDateTimeString(),
                    'notes' => $data['notes'] ?? null,
                ]] : [],
                'content' => array_filter([
                    'payment_method' => $paymentMethod,
                    'customer_name' => $data['customer_name'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                    'customer_email' => $data['customer_email'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'document_number' => $data['document_number'] ?? null,
                    'comments' => $data['comments'] ?? null,
                ], fn($v) => $v !== null),
            ]);

            foreach ($lines as $line) {
                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product_id'],
                    'package_id' => $line['package_id'] ?? null,
                    'unit_id' => $line['unit_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                    'tax' => 0,
                    'discount' => $line['discount'],
                    'total' => $line['total'],
                ]);
            }

            // La venta nace cerrada: descuenta stock (y deja su asiento en el libro mayor).
            $sale->validateAndUpdateStock();

            foreach ($payments as $payment) {
                if (isset($payment['method']) && in_array($payment['method'], SalePayment::METHODS, true)) {
                    $sale->payments()->create($payment);
                }
                CashMovementService::fromSale($sale, (float) $payment['amount'], $payment['method'], null, $session?->id);
            }

            return $sale;
        });
    }

    /**
     * Pagos de la venta. El punto de venta los manda explícitos; la pantalla
     * anterior manda un solo método con paid_amount (0 = crédito sin anticipo).
     *
     * @return array<int, array{method: string, amount: float, tendered?: float|null, change?: float|null}>
     */
    private function normalizePayments(array $data, float $total): array
    {
        if (!empty($data['payments'])) {
            return array_map(function ($p) {
                $amount = Money::round($p['amount']);
                $tendered = isset($p['tendered']) ? Money::round($p['tendered']) : null;

                return [
                    'method' => $p['method'],
                    'amount' => $amount,
                    'tendered' => $p['method'] === 'cash' ? ($tendered ?? $amount) : null,
                    'change' => $p['method'] === 'cash'
                        ? Money::fromCents(max(Money::toCents($tendered ?? $amount) - Money::toCents($amount), 0))
                        : null,
                ];
            }, $data['payments']);
        }

        $method = $data['payment_method'] ?? 'cash';

        // Crédito sin anticipo: si no se indica monto, no se registra pago (antes
        // tomaba el total por defecto y marcaba la venta como pagada).
        $default = $method === 'credit' ? 0.0 : $total;
        $paid = Money::round($data['paid_amount'] ?? $default);

        // "Crédito" no es una forma de pago: un anticipo con ese método entraba
        // a caja como "otro" sin saber si fue efectivo, tarjeta o transferencia.
        if ($method === 'credit' && $paid > 0) {
            throw self::creditWithPayment();
        }

        // Nunca registrar como ingreso más que el total de la venta: pagar de
        // más (500 en una venta de 380) no debe asentar 500 en caja; el cambio
        // no es ingreso.
        $paid = min($paid, $total);

        if ($paid <= 0) {
            return [];
        }

        return [['method' => $method, 'amount' => $paid]];
    }

    /**
     * Venta o pedido a crédito con un monto pagado. El anticipo se registra con
     * su forma de pago real (efectivo, tarjeta o transferencia) y un monto
     * menor al total: la venta queda parcial y el resto pendiente.
     */
    public static function creditWithPayment(): BusinessRuleException
    {
        return new BusinessRuleException(
            'Una venta a crédito no registra pago. Si el cliente deja un anticipo, elige con qué pagó '
            . '(efectivo, tarjeta o transferencia) y captura el monto: el resto queda pendiente.',
            'credit_with_payment',
        );
    }
}
