<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Regla de negocio incumplida: la operación no se puede hacer tal como se
 * pidió (no hay turno abierto, el turno ya se cerró, no alcanza el stock, el
 * documento está en otro estado...).
 *
 * Responde 422 {message, code}: `message` es el texto en español que la app
 * muestra tal cual y `code` una clave estable (snake_case) para que el
 * cliente distinga el caso sin depender del texto. Con `details` se agregan
 * datos para el cliente (p. ej. InsufficientStockException).
 *
 * El mensaje lo escribe quien la lanza: nunca debe llevar el texto de una
 * excepción interna (SQL, errores de PHP).
 *
 * La respuesta es la misma si la excepción llega al manejador global (método
 * render()) o si un controlador la atrapa y llama a errorResponse(). No se
 * registra en el log como error del servidor (ShouldntReport): es una
 * respuesta esperada, igual que una validación.
 *
 * Uso: throw new BusinessRuleException('Abre un turno de caja para cobrar en efectivo', 'no_open_session');
 */
class BusinessRuleException extends RuntimeException implements ShouldntReport
{
    /**
     * @param string $message Texto para el usuario, en español.
     * @param string $errorCode Clave estable para el cliente (va en "code").
     * @param array<string, mixed> $details Datos extra para el cliente (van en "details").
     */
    public function __construct(
        string $message,
        protected string $errorCode = 'business_rule',
        protected array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Clave estable del caso (el "code" de la respuesta). No es getCode():
     * ese es el código numérico de PHP y no se puede redefinir.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function render(?Request $request = null): JsonResponse
    {
        $payload = [
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ];

        if ($this->details !== []) {
            $payload['details'] = $this->details;
        }

        return response()->json($payload, 422);
    }
}
