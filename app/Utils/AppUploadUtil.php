<?php

namespace App\Utils;

use App\Constants\Files;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class AppUploadUtil
{
    /**
     * Guarda un archivo en la ruta especificada
     *
     * @param UploadedFile $file
     * @param string $path Constante del path (ej: Files::PRODUCT_IMAGES_PATH)
     * @param string|null $customName Nombre personalizado (opcional)
     * @return array ['success' => bool, 'path' => string|null, 'error' => string|null, 'metadata' => array]
     */
    public static function saveFile(UploadedFile $file, string $path, ?string $customName = null): array
    {
        try {
            // Validar el archivo
            $validation = self::validateFile($file);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'path' => null,
                    'error' => $validation['error'],
                    'metadata' => []
                ];
            }

            // Generar nombre único si no se proporciona uno personalizado
            $fileName = $customName ?? self::generateUniqueFileName($file);

            // Crear la ruta completa
            $fullPath = $path . '/' . $fileName;

            // Guardar el archivo en el disco público
            $savedPath = Storage::disk('public')->putFileAs($path, $file, $fileName);

            if (!$savedPath) {
                throw new Exception('Error al guardar el archivo en el storage');
            }

            // Obtener metadata del archivo
            $metadata = self::getFileMetadata($file, $savedPath);

            return [
                'success' => true,
                'path' => $savedPath,
                'error' => null,
                'metadata' => $metadata
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'path' => null,
                'error' => 'Error al guardar archivo: ' . $e->getMessage(),
                'metadata' => []
            ];
        }
    }

    /**
     * Elimina un archivo por su path completo
     *
     * @param string $filePath Path completo del archivo
     * @return bool
     */
    public static function deleteFile(string $filePath): bool
    {
        try {
            if (Storage::disk('public')->exists($filePath)) {
                return Storage::disk('public')->delete($filePath);
            }
            return true; // Si no existe, consideramos que ya está "eliminado"
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Elimina un archivo por path base y nombre
     *
     * @param string $basePath Path base (ej: Files::PRODUCT_IMAGES_PATH)
     * @param string $fileName Nombre del archivo
     * @return bool
     */
    public static function deleteFileByName(string $basePath, string $fileName): bool
    {
        $fullPath = $basePath . '/' . $fileName;
        return self::deleteFile($fullPath);
    }

    /**
     * Elimina múltiples archivos por paths completos
     *
     * @param array $filePaths Array de paths completos
     * @return array ['deleted' => int, 'failed' => array]
     */
    public static function deleteMultipleFiles(array $filePaths): array
    {
        $deleted = 0;
        $failed = [];

        foreach ($filePaths as $path) {
            if (self::deleteFile($path)) {
                $deleted++;
            } else {
                $failed[] = $path;
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed
        ];
    }

    /**
     * Elimina múltiples archivos por path base y nombres
     *
     * @param string $basePath Path base (ej: Files::PRODUCT_IMAGES_PATH)
     * @param array $fileNames Array de nombres de archivos
     * @return array ['deleted' => int, 'failed' => array]
     */
    public static function deleteMultipleFilesByNames(string $basePath, array $fileNames): array
    {
        $deleted = 0;
        $failed = [];

        foreach ($fileNames as $fileName) {
            if (self::deleteFileByName($basePath, $fileName)) {
                $deleted++;
            } else {
                $failed[] = $fileName;
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed
        ];
    }

    /**
     * Sincroniza archivos: elimina los que ya no están en la nueva lista y guarda los nuevos
     *
     * @param string $path Constante del path
     * @param array $newFiles Array de UploadedFile
     * @param array $oldFilePaths Array de paths completos de archivos antiguos
     * @param string|null $prefix Prefijo para nombres de archivo
     * @return array ['saved' => array, 'deleted' => array, 'errors' => array]
     */
    public static function syncFiles(string $path, array $newFiles, array $oldFilePaths, ?string $prefix = null): array
    {
        $result = [
            'saved' => [],
            'deleted' => [],
            'errors' => []
        ];

        // 1. Guardar nuevos archivos
        foreach ($newFiles as $index => $file) {
            if ($file instanceof UploadedFile) {
                $customName = $prefix ? $prefix . '_' . ($index + 1) . '.' . $file->getClientOriginalExtension() : null;
                $saveResult = self::saveFile($file, $path, $customName);

                if ($saveResult['success']) {
                    $result['saved'][] = [
                        'path' => $saveResult['path'],
                        'metadata' => $saveResult['metadata'],
                        'name' => $customName,
                    ];
                } else {
                    $result['errors'][] = $saveResult['error'];
                }
            }
        }

        // 2. Eliminar archivos antiguos que ya no están en uso
        if (!empty($oldFilePaths)) {
            $deleteResult = self::deleteMultipleFiles($oldFilePaths);
            $result['deleted'] = $oldFilePaths;

            if (!empty($deleteResult['failed'])) {
                $result['errors'] = array_merge($result['errors'], [
                    'Failed to delete: ' . implode(', ', $deleteResult['failed'])
                ]);
            }
        }

        return $result;
    }

    /**
     * Sincroniza archivos usando nombres de archivo en lugar de paths completos
     *
     * @param string $path Constante del path base
     * @param array $newFiles Array de UploadedFile
     * @param array $oldFileNames Array de nombres de archivos antiguos (solo nombres, no paths completos)
     * @param string|null $prefix Prefijo para nombres de archivo
     * @return array ['saved' => array, 'deleted' => array, 'errors' => array]
     */
    public static function syncFilesByNames(string $path, array $newFiles, array $oldFileNames, ?string $prefix = null): array
    {
        $result = [
            'saved' => [],
            'deleted' => [],
            'errors' => []
        ];

        $currentFilesNames = [];

        // 1. Guardar nuevos archivos
        foreach ($newFiles as $index => $file) {
            if ($file instanceof UploadedFile) {
                $customName = $prefix ? $prefix . '_' . ($index + 1) . '.' . $file->getClientOriginalExtension() : null;
                $saveResult = self::saveFile($file, $path, $customName);

                if ($saveResult['success']) {
                    // Extraer solo el nombre del archivo del path
                    $fileName = basename($saveResult['path']);
                    $result['saved'][] = [
                        'path' => $saveResult['path'],
                        'metadata' => $saveResult['metadata'],
                        'name' => $fileName, // Solo el nombre del archivo
                    ];
                } else {
                    $result['errors'][] = $saveResult['error'];
                }
            } else {
                $currentFilesNames[] = $file['name'];
            }
        }

        // 2. Eliminar archivos antiguos por nombres
        if (!empty($oldFileNames)) {
            // Determinar qué archivos antiguos ya no están en la lista actual
            $toDelete = array_values(array_diff($oldFileNames, $currentFilesNames));

            if (!empty($toDelete)) {
                $deleteResult = self::deleteMultipleFilesByNames($path, $toDelete);
                $result['deleted'] = $toDelete;

                if (!empty($deleteResult['failed'])) {
                    $result['errors'] = array_merge($result['errors'], [
                        'Failed to delete: ' . implode(', ', $deleteResult['failed'])
                    ]);
                }
            } else {
                // No hay archivos para eliminar
                $result['deleted'] = [];
            }
        }

        return $result;
    }

    /**
     * Reemplaza archivos usando paths completos: elimina los antiguos y guarda los nuevos
     *
     * @param string $path
     * @param array $newFiles
     * @param array $oldFilePaths Array de paths completos
     * @param string|null $prefix
     * @return array
     */
    public static function replaceFiles(string $path, array $newFiles, array $oldFilePaths, ?string $prefix = null): array
    {
        // Primero eliminar los antiguos
        if (!empty($oldFilePaths)) {
            self::deleteMultipleFiles($oldFilePaths);
        }

        // Luego guardar los nuevos
        return self::syncFiles($path, $newFiles, [], $prefix);
    }

    /**
     * Reemplaza archivos usando nombres: elimina los antiguos y guarda los nuevos
     *
     * @param string $path Path base
     * @param array $newFiles Array de UploadedFile
     * @param array $oldFileNames Array de nombres de archivo (solo nombres)
     * @param string|null $prefix
     * @return array
     */
    public static function replaceFilesByNames(string $path, array $newFiles, array $oldFileNames, ?string $prefix = null): array
    {
        // Primero eliminar los antiguos
        if (!empty($oldFileNames)) {
            self::deleteMultipleFilesByNames($path, $oldFileNames);
        }

        // Luego guardar los nuevos
        return self::syncFilesByNames($path, $newFiles, [], $prefix);
    }

    /**
     * Valida un archivo subido
     *
     * @param UploadedFile $file
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private static function validateFile(UploadedFile $file): array
    {
        // Verificar que el archivo se subió correctamente
        if (!$file->isValid()) {
            return ['valid' => false, 'error' => 'Archivo inválido o corrupto'];
        }

        $mimeType = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();
        $size = $file->getSize();

        // Validar tipo de archivo
        $isImage = Files::isValidImageType($mimeType) && Files::isValidImageExtension($extension);
        $isDocument = Files::isValidDocumentType($mimeType) && Files::isValidDocumentExtension($extension);

        if (!$isImage && !$isDocument) {
            return ['valid' => false, 'error' => 'Tipo de archivo no permitido: ' . $mimeType];
        }

        // Validar tamaño
        $maxSize = $isImage ? Files::MAX_IMAGE_SIZE : Files::MAX_DOCUMENT_SIZE;
        if ($size > $maxSize) {
            $maxSizeMB = $maxSize / 1024 / 1024;
            return ['valid' => false, 'error' => "Archivo demasiado grande. Máximo: {$maxSizeMB}MB"];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Genera un nombre único para el archivo
     *
     * @param UploadedFile $file
     * @return string
     */
    private static function generateUniqueFileName(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Ymd_His');
        $random = Str::random(8);

        return "{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Obtiene metadata del archivo
     *
     * @param UploadedFile $file
     * @param string $savedPath
     * @return array
     */
    private static function getFileMetadata(UploadedFile $file, string $savedPath): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
            'saved_path' => $savedPath,
            'url' => url('/storage/' . $savedPath), // URL completa para archivos públicos
            'is_image' => Files::isValidImageType($file->getMimeType()),
            'uploaded_at' => now()->toISOString()
        ];
    }

    /**
     * Obtiene información de un archivo existente
     *
     * @param string $filePath
     * @return array|null
     */
    public static function getFileInfo(string $filePath): ?array
    {
        try {
            if (!Storage::disk('public')->exists($filePath)) {
                return null;
            }

            return [
                'path' => $filePath,
                'url' => url('/storage/' . $filePath), // URL completa para archivos públicos
                'size' => Storage::disk('public')->size($filePath),
                'last_modified' => Storage::disk('public')->lastModified($filePath),
                'exists' => true
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Crea los directorios necesarios si no existen
     *
     * @param string $path
     * @return bool
     */
    public static function ensureDirectoryExists(string $path): bool
    {
        try {
            if (!Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->makeDirectory($path);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Crea un objeto compatible con la interfaz UploadedFile (para uso en frontend)
     *
     * @param string $basePath Path base donde está el archivo (ej: Files::PRODUCT_IMAGES_PATH)
     * @param string $fileName Nombre del archivo
     * @return array|null ['uri' => string, 'name' => string, 'type' => string, 'size' => int|null] | null si no existe o ocurre error
     */
    public static function formatFile(string $basePath, string $fileName): ?array
    {
        try {
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($fileName, '/');

            if (!Storage::disk('public')->exists($fullPath)) {
                return null;
            }

            // Generar URL completa para archivos públicos
            $uri = url('/storage/' . $fullPath);

            // Obtener tipo MIME basado en la extensión del archivo
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $type = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                default => 'application/octet-stream'
            };

            $size = null;

            try {
                $size = Storage::disk('public')->size($fullPath);
            } catch (Exception $e) {
                // size puede quedarse en null si el driver no lo soporta
            }

            return [
                'uri' => $uri,
                'name' => $fileName,
                'type' => $type,
                'size' => $size,
            ];
        } catch (Exception $e) {
            return null;
        }
    }
}
