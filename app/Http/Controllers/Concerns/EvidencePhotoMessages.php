<?php

namespace App\Http\Controllers\Concerns;

/**
 * Mensajes de validación de las fotos de evidencia (envío y recepción de
 * transferencias, ajustes de inventario).
 *
 * El servidor acepta fotos de hasta 5 MB (y PHP corta la subida arriba de 6
 * MB). Con los mensajes por defecto la app mostraba "El archivo evidence.0 no
 * debe pesar más de 5120 kilobytes" o "No se pudo subir evidence.0", que no
 * dicen qué hacer.
 */
trait EvidencePhotoMessages
{
    /**
     * @param string[] $fields Campos de cada foto, p. ej. ['evidence.*', 'items.*.adjustment_evidence.*'].
     * @return array<string, string>
     */
    protected function evidencePhotoMessages(array $fields): array
    {
        $tooBig = 'Cada foto de evidencia debe pesar 5 MB o menos. Tómala con menos resolución o elige otra.';
        $notAPhoto = 'La evidencia debe ser una foto (JPG, PNG o WEBP).';

        $messages = [];
        foreach ($fields as $field) {
            $messages["{$field}.max"] = $tooBig;
            // La subida falló antes de llegar a Laravel (pasa del límite de PHP).
            $messages["{$field}.uploaded"] = $tooBig;
            $messages["{$field}.image"] = $notAPhoto;
            $messages["{$field}.mimes"] = $notAPhoto;
            $messages["{$field}.required"] = 'Una de las fotos de evidencia llegó vacía. Vuelve a adjuntarla.';
        }

        return $messages;
    }
}
