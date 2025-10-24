<?php

namespace App\Constants;

class Files
{
    // Directorio base para archivos
    public const BASE_PATH = 'uploads';

    // Rutas de imágenes
    public const PRODUCT_IMAGES_PATH = self::BASE_PATH . '/products/images';
    public const CATEGORY_IMAGES_PATH = self::BASE_PATH . '/categories/images';
    public const COMPANY_LOGOS_PATH = self::BASE_PATH . '/companies/logos';
    public const USER_AVATARS_PATH = self::BASE_PATH . '/users/avatars';

    // Rutas de documentos/attachments
    public const PRODUCT_DOCUMENTS_PATH = self::BASE_PATH . '/products/documents';
    public const COMPANY_DOCUMENTS_PATH = self::BASE_PATH . '/companies/documents';
    public const CERTIFICATES_PATH = self::BASE_PATH . '/certificates';
    public const REPORTS_PATH = self::BASE_PATH . '/reports';

    // Rutas temporales
    public const TEMP_PATH = self::BASE_PATH . '/temp';
    public const IMPORTS_PATH = self::BASE_PATH . '/imports';

    // Tamaños máximos (en bytes)
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5MB
    public const MAX_DOCUMENT_SIZE = 10 * 1024 * 1024; // 10MB

    // Tipos MIME permitidos
    public const ALLOWED_IMAGE_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    public const ALLOWED_DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv'
    ];

    // Extensiones permitidas
    public const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];

    /**
     * Obtiene todas las rutas de archivos
     */
    public static function getAllPaths(): array
    {
        return [
            'base' => self::BASE_PATH,
            'product_images' => self::PRODUCT_IMAGES_PATH,
            'category_images' => self::CATEGORY_IMAGES_PATH,
            'company_logos' => self::COMPANY_LOGOS_PATH,
            'user_avatars' => self::USER_AVATARS_PATH,
            'product_documents' => self::PRODUCT_DOCUMENTS_PATH,
            'company_documents' => self::COMPANY_DOCUMENTS_PATH,
            'certificates' => self::CERTIFICATES_PATH,
            'reports' => self::REPORTS_PATH,
            'temp' => self::TEMP_PATH,
            'imports' => self::IMPORTS_PATH,
        ];
    }

    /**
     * Verifica si un tipo MIME es una imagen válida
     */
    public static function isValidImageType(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_IMAGE_TYPES);
    }

    /**
     * Verifica si un tipo MIME es un documento válido
     */
    public static function isValidDocumentType(string $mimeType): bool
    {
        return in_array($mimeType, self::ALLOWED_DOCUMENT_TYPES);
    }

    /**
     * Verifica si una extensión es válida para imágenes
     */
    public static function isValidImageExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::ALLOWED_IMAGE_EXTENSIONS);
    }

    /**
     * Verifica si una extensión es válida para documentos
     */
    public static function isValidDocumentExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::ALLOWED_DOCUMENT_EXTENSIONS);
    }
}
