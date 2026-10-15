<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Folios consecutivos sin duplicados bajo concurrencia.
 *
 * Antes cada modelo leía "el último folio" y le sumaba 1: dos ventas
 * simultáneas leían el mismo y salían con el mismo número (y en compras,
 * transferencias y pedidos, que tienen índice único, la segunda fallaba).
 * Aquí el contador vive en `document_sequences` y se incrementa con un
 * upsert que bloquea la fila hasta que termina la transacción: si el
 * documento se revierte, el número también (no quedan huecos).
 */
class DocumentSequence
{
    /**
     * Siguiente número de la serie `$scope`.
     *
     * @param  Closure(): int  $seed  Último número ya usado en la serie. Solo
     *   se llama la primera vez que aparece la serie, para seguir después de
     *   los folios emitidos antes de que existiera el contador.
     */
    public static function next(string $scope, Closure $seed): int
    {
        return DB::transaction(function () use ($scope, $seed) {
            $table = DB::table('document_sequences');
            $now = now();

            $start = (clone $table)->where('scope', $scope)->exists() ? 0 : (int) $seed();

            // Inserta la serie o la incrementa en una sola sentencia; el
            // bloqueo de la fila serializa a quien pida número al mismo tiempo.
            (clone $table)->upsert(
                [['scope' => $scope, 'last_number' => $start + 1, 'created_at' => $now, 'updated_at' => $now]],
                ['scope'],
                ['last_number' => DB::raw('last_number + 1'), 'updated_at' => $now],
            );

            return (int) (clone $table)->where('scope', $scope)->value('last_number');
        });
    }

    /**
     * Mayor número ya usado entre los folios `$prefix` + dígitos de `$query`.
     */
    public static function lastUsed(Builder $query, string $column, string $prefix): int
    {
        $last = $query
            ->where($column, 'like', $prefix . '%')
            ->orderByRaw('LENGTH(' . $query->getQuery()->getGrammar()->wrap($column) . ') DESC')
            ->orderByDesc($column)
            ->value($column);

        return $last && preg_match('/(\d+)$/', substr($last, strlen($prefix)), $m) ? (int) $m[1] : 0;
    }
}
