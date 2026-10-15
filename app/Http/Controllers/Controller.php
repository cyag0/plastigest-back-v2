<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Respuesta de error para una excepción inesperada.
     *
     * No se devuelve $e->getMessage() cuando la excepción es interna (SQL,
     * errores de PHP, métodos inexistentes): esos mensajes filtran el esquema
     * de la base de datos y detalles del código al cliente. En su lugar se
     * registra en el log y se responde un mensaje genérico. Los mensajes de
     * negocio (stock insuficiente, reglas de dominio) sí llegan al usuario.
     */
    protected function errorResponse(
        Throwable $e,
        string $message = 'Ocurrió un error al procesar la solicitud.',
        int $status = 500
    ): JsonResponse {
        // Respuestas HTTP deliberadas (abort(403), abort(response()->json(...)))
        // lanzadas dentro de los hooks: se respetan en vez de volverlas un 500.
        if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException && $e->getResponse() instanceof JsonResponse) {
            return $e->getResponse();
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return response()->json(['message' => $e->getMessage() ?: $message], $e->getStatusCode());
        }

        Log::error($message, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        $payload = ['message' => $message];

        // Solo se expone el detalle cuando es un mensaje de negocio seguro, no
        // cuando es un error interno (que filtraría estructura/consultas).
        if (!$this->isInternalError($e)) {
            $payload['error'] = $e->getMessage();
        }

        return response()->json($payload, $status);
    }

    /**
     * ¿La excepción es interna (su mensaje no debe exponerse al cliente)?
     * Ojo: QueryException extiende de RuntimeException, por eso se verifica
     * explícitamente.
     */
    /**
     * Detalle para el campo `error` de una respuesta: el mensaje de negocio
     * tal cual, o null (y al log) si es un error interno que no debe salir.
     */
    protected function errorDetail(Throwable $e): ?string
    {
        if ($this->isInternalError($e)) {
            Log::error('Error interno', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return null;
        }

        return $e->getMessage();
    }

        protected function isInternalError(Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\QueryException
            || $e instanceof \PDOException
            || $e instanceof \Error            // TypeError, DivisionByZeroError, etc.
            || $e instanceof \ErrorException
            || $e instanceof \BadFunctionCallException; // incluye BadMethodCallException
    }
}
