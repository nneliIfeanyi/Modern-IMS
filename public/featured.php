<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dataFile = dirname(__DIR__) . '/storage/featured.json';
$uploadDir = dirname(__DIR__) . '/public/uploads/featured';
$uploadPrefix = 'public/uploads/featured/';

function featuredResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function readFeatured(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return [];
    }

    $items = json_decode((string) file_get_contents($dataFile), true);
    return is_array($items) ? array_values($items) : [];
}

function writeFeatured(string $dataFile, array $items): void
{
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (file_put_contents($dataFile, $json, LOCK_EX) === false) {
        featuredResponse(['message' => 'Featured data could not be saved.'], 500);
    }
}

function requestPayload(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    return is_array($payload) ? $payload : [];
}

function saveUploadedImage(string $uploadDir, string $uploadPrefix, string $currentImage = ''): string
{
    if (!isset($_FILES['image']) || (int) $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return $currentImage;
    }

    $file = $_FILES['image'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] > 5 * 1024 * 1024) {
        featuredResponse(['message' => 'Image upload failed or exceeds the 5 MB limit.', 'fields' => ['image' => 'Choose an image up to 5 MB.']], 422);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        featuredResponse(['message' => 'Unsupported image type.', 'fields' => ['image' => 'Use JPG, PNG, or WebP.']], 422);
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        featuredResponse(['message' => 'Image upload directory could not be created.'], 500);
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
        featuredResponse(['message' => 'Uploaded image could not be saved.'], 500);
    }

    return $uploadPrefix . $filename;
}

function validateFeatured(array $payload, ?int $id = null): array
{
    $title = trim((string) ($payload['title'] ?? ''));
    $category = trim((string) ($payload['category'] ?? ''));
    $label = trim((string) ($payload['label'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $image = trim((string) ($payload['image'] ?? ''));
    $sortOrder = (int) ($payload['sort_order'] ?? 1);

    $errors = [];
    if ($title === '' || strlen($title) > 150) $errors['title'] = 'Title is required and must not exceed 150 characters.';
    if ($category === '' || strlen($category) > 60) $errors['category'] = 'Category is required and must not exceed 60 characters.';
    if ($label === '' || strlen($label) > 100) $errors['label'] = 'Label is required and must not exceed 100 characters.';
    if ($description === '' || strlen($description) > 255) $errors['description'] = 'Description is required and must not exceed 255 characters.';
    if ($image === '' || !preg_match('/^(https?:\/\/|public\/uploads\/featured\/)[^\s]+$/', $image)) $errors['image'] = 'A valid uploaded image is required.';
    if ($sortOrder < 1 || $sortOrder > 8) $errors['sort_order'] = 'Sort order must be between 1 and 8.';

    return [$errors, [
        'id' => $id,
        'title' => $title,
        'category' => strtoupper($category),
        'label' => $label,
        'description' => $description,
        'image' => $image,
        'sort_order' => $sortOrder,
        'is_active' => filter_var($payload['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ]];
}

$method = strtoupper($_POST['_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$items = readFeatured($dataFile);

if ($method === 'GET') {
    $isAdmin = ($_GET['admin'] ?? '') === '1';
    $items = array_values(array_filter($items, static fn(array $item): bool => $isAdmin || !empty($item['is_active'])));
    usort($items, static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0)));
    featuredResponse(['items' => $items]);
}

if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    featuredResponse(['message' => 'Method not allowed.'], 405);
}

if ($method === 'POST') {
    if (count($items) >= 8) featuredResponse(['message' => 'You can manage up to 8 featured items.'], 422);
    $payload = requestPayload();
    $payload['image'] = saveUploadedImage($uploadDir, $uploadPrefix, '');
    [$errors, $item] = validateFeatured($payload, null);
    if ($errors !== []) featuredResponse(['message' => 'Validation failed.', 'fields' => $errors], 422);
    $item['id'] = $items === [] ? 1 : max(array_map(static fn(array $row): int => (int) $row['id'], $items)) + 1;
    $items[] = $item;
    writeFeatured($dataFile, $items);
    featuredResponse(['item' => $item], 201);
}

$id = (int) ($_GET['id'] ?? 0);
$index = array_search($id, array_map(static fn(array $item): int => (int) $item['id'], $items), true);
if ($index === false) featuredResponse(['message' => 'Featured item not found.'], 404);

if ($method === 'DELETE') {
    array_splice($items, (int) $index, 1);
    writeFeatured($dataFile, $items);
    featuredResponse(['message' => 'Featured item deleted.']);
}

$payload = requestPayload();
$payload['image'] = saveUploadedImage($uploadDir, $uploadPrefix, trim((string) ($payload['existing_image'] ?? $items[(int) $index]['image'] ?? '')));
[$errors, $item] = validateFeatured($payload, $id);
if ($errors !== []) featuredResponse(['message' => 'Validation failed.', 'fields' => $errors], 422);
$items[(int) $index] = $item;
writeFeatured($dataFile, $items);
featuredResponse(['item' => $item]);
