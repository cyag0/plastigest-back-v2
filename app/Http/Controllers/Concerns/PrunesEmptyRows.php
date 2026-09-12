<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Limpia las filas en blanco que mandan las tablas editables.
 *
 * En la app, "Agregar ingrediente" inserta una fila vacía que el usuario puede
 * dejar sin llenar. Esa fila llega como `product_id = 0` y reventaría contra un
 * `exists:products,id` con un error que no le dice nada a nadie. Se descartan
 * antes de validar: una fila sin producto simplemente no existe.
 */
trait PrunesEmptyRows
{
    /**
     * Quita del request las filas de `$field` cuya columna `$keyColumn` viene
     * vacía. Si no queda ninguna, el campo se elimina para que las reglas de
     * "requerido" den el mensaje correcto en vez de fallar fila por fila.
     */
    protected function pruneEmptyRows(Request $request, string $field, string $keyColumn): void
    {
        if (!is_array($request->input($field))) {
            return;
        }

        $rows = array_values(array_filter(
            $request->input($field),
            fn($row) => is_array($row) && !empty($row[$keyColumn])
        ));

        if ($rows) {
            $request->merge([$field => $rows]);
            return;
        }

        $data = $request->all();
        unset($data[$field]);
        $request->replace($data);
    }
}
