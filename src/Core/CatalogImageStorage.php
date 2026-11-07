<?php

declare(strict_types=1);

namespace App\Core;

final class CatalogImageStorage
{
    private const ALLOWED_CATALOGS = ['gadget-catalog', 'pharmacy-catalog'];
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function save(string $catalog): array
    {
        self::assertCatalog($catalog);
        if (!isset($_FILES['image'])) {
            return [null, null];
        }

        $file = $_FILES['image'];
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            return [null, 'Image upload data is invalid. Please choose the image again.'];
        }

        $uploadError = filter_var($file['error'], FILTER_VALIDATE_INT);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            return [null, 'Image upload failed. Please choose the image again.'];
        }

        $temporaryPath = $file['tmp_name'] ?? '';
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            return [null, 'Uploaded image is not valid. Please choose the image again.'];
        }

        $size = filesize($temporaryPath);
        if ($size === false) {
            return [null, 'Uploaded image size could not be checked.'];
        }
        if ($size > self::MAX_SIZE) {
            return [null, 'Image must be 5 MB or smaller.'];
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!is_string($mime) || !isset(self::EXTENSIONS[$mime]) || @getimagesize($temporaryPath) === false) {
            return [null, 'Use a valid JPG, PNG, or WebP image.'];
        }

        $relativeDirectory = 'public/uploads/' . $catalog;
        $absoluteDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            return [null, 'Image upload directory could not be created.'];
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
        if (!move_uploaded_file($temporaryPath, $absoluteDirectory . DIRECTORY_SEPARATOR . $filename)) {
            return [null, 'Uploaded image could not be saved.'];
        }

        return [$relativeDirectory . '/' . $filename, null];
    }

    public static function delete(string $imagePath, string $catalog): void
    {
        self::assertCatalog($catalog);
        $prefix = 'public/uploads/' . $catalog . '/';
        $filename = str_starts_with($imagePath, $prefix) ? substr($imagePath, strlen($prefix)) : '';
        if ($filename === '' || basename($filename) !== $filename) {
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . $catalog . DIRECTORY_SEPARATOR . $filename;
        if (is_file($absolutePath) && !unlink($absolutePath)) {
            error_log('Catalogue image could not be removed: ' . $imagePath);
        }
    }

    private static function assertCatalog(string $catalog): void
    {
        if (!in_array($catalog, self::ALLOWED_CATALOGS, true)) {
            throw new \InvalidArgumentException('Unsupported catalogue image directory.');
        }
    }
}
