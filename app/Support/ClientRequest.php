<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Llave de idempotencia de los cobros: el campo client_request_id que la app
 * genera en el primer intento de cobro (un UUID) y repite en cada reintento.
 *
 * La aceptan la venta del punto de venta, la de la pantalla anterior, el alta
 * de un pedido, su cobro, el cobro rápido del kiosco y el abono a una venta.
 * Si ya existe un registro con esa llave, el endpoint responde 200 con el
 * mismo cuerpo que el alta original y no crea nada: un reintento después de
 * un timeout o de perder la red ya no cobra dos veces.
 *
 * Dos peticiones simultáneas con la misma llave las resuelve el índice único
 * (empresa + llave; venta + llave en los abonos): la segunda falla al
 * insertar, su transacción se revierte completa (stock, caja, folio) y se
 * responde con lo que guardó la primera.
 */
final class ClientRequest
{
    /**
     * Regla de validación del campo (opcional salvo en el cobro rápido).
     *
     * @return array<int, string>
     */
    public static function rules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:64'];
    }

    /**
     * ¿Falló el INSERT porque la llave ya existe? Es el error de llave
     * duplicada de MySQL (1062) en uno de los índices de client_request_id.
     */
    public static function isDuplicate(Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }

        $isDuplicateEntry = ($e->errorInfo[1] ?? null) === 1062
            || ($e->errorInfo[0] ?? null) === '23000' && str_contains($e->getMessage(), 'Duplicate');

        return $isDuplicateEntry && str_contains($e->getMessage(), 'client_request');
    }

    /**
     * La llave ya se usó en otra operación (otra sucursal u otro pedido): no
     * se devuelve ese registro ni se crea uno nuevo. Con un UUID por intento
     * de cobro no debería pasar.
     */
    public static function conflict(): BusinessRuleException
    {
        return new BusinessRuleException(
            'Esta llave de cobro ya se usó en otra operación. Vuelve a intentar el cobro desde el principio.',
            'client_request_conflict',
        );
    }
}
