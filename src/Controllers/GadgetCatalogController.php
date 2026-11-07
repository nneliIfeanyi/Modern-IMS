<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\InputSanitizer;
use App\Core\CatalogImageStorage;
use App\Core\Request;
use App\Core\Response;
use App\Models\GadgetCatalogModel;

final class GadgetCatalogController
{
    private const CATEGORIES = [
        'TVs & Displays',
        'Refrigerators',
        'Washing Machines',
        'Home Theatre',
        'Kitchen Appliances',
        'Other',
    ];

    private GadgetCatalogModel $catalog;

    public function __construct()
    {
        $this->catalog = new GadgetCatalogModel();
    }

    public function publicIndex(Request $request)
    {
        unset($request);

        return Response::success($this->catalog->listPublished(), 'Gadget catalogue loaded.');
    }

    public function adminIndex(Request $request)
    {
        unset($request);

        return Response::success($this->catalog->listForAdmin(), 'Gadget catalogue loaded.');
    }

    public function create(Request $request)
    {
        [$data, $errors] = $this->validatedPayload($this->payload($request));
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        [$imagePath, $uploadError] = CatalogImageStorage::save('gadget-catalog');
        if ($uploadError !== null) {
            return Response::error($uploadError, ['fields' => ['image' => $uploadError]], 422);
        }
        if ($imagePath === null) {
            return Response::error('A product image is required.', ['fields' => ['image' => 'Choose an image.']], 422);
        }

        $data['image_path'] = $imagePath;
        $id = $this->catalog->create($data);

        return Response::success($this->catalog->findById($id), 'Catalogue item created.', 201);
    }

    public function update(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid catalogue item id.', null, 422);
        }

        $existing = $this->catalog->findById($id);
        if ($existing === null) {
            return Response::error('Catalogue item not found.', null, 404);
        }

        [$data, $errors] = $this->validatedPayload($this->payload($request));
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        [$uploadedPath, $uploadError] = CatalogImageStorage::save('gadget-catalog');
        if ($uploadError !== null) {
            return Response::error($uploadError, ['fields' => ['image' => $uploadError]], 422);
        }

        $data['image_path'] = $uploadedPath ?? $existing['image_path'];
        $this->catalog->update($id, $data);
        $updated = $this->catalog->findById($id);

        if ($updated === null) {
            return Response::error('Catalogue item could not be loaded after updating.', null, 500);
        }

        if ($uploadedPath !== null && $uploadedPath !== $existing['image_path']) {
            CatalogImageStorage::delete((string) $existing['image_path'], 'gadget-catalog');
        }

        return Response::success($updated, 'Catalogue item updated.');
    }

    public function delete(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid catalogue item id.', null, 422);
        }

        $existing = $this->catalog->findById($id);
        if ($existing === null) {
            return Response::error('Catalogue item not found.', null, 404);
        }

        if (!$this->catalog->delete($id)) {
            return Response::error('Catalogue item could not be deleted.', null, 409);
        }

        CatalogImageStorage::delete((string) $existing['image_path'], 'gadget-catalog');

        return Response::success(null, 'Catalogue item deleted.');
    }

    private function payload(Request $request): array
    {
        if ($_POST !== []) {
            return InputSanitizer::sanitizeArray($_POST);
        }

        return $request->json();
    }

    private function validatedPayload(array $payload): array
    {
        $name = $this->stringValue($payload['name'] ?? '');
        $category = $this->stringValue($payload['category'] ?? '');
        $brand = $this->stringValue($payload['brand'] ?? '');
        $model = $this->stringValue($payload['model'] ?? '');
        $description = $this->stringValue($payload['description'] ?? '');
        $priceInput = $this->stringValue($payload['display_price'] ?? '');
        $sortInput = $this->stringValue($payload['sort_order'] ?? '0');
        $sortOrder = filter_var($sortInput, FILTER_VALIDATE_INT);
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Product name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Product name must not exceed 150 characters.';
        }

        if (!in_array($category, self::CATEGORIES, true)) {
            $errors['category'] = 'Choose a valid product category.';
        }

        if (mb_strlen($brand) > 100) {
            $errors['brand'] = 'Brand must not exceed 100 characters.';
        }
        if (mb_strlen($model) > 100) {
            $errors['model'] = 'Model must not exceed 100 characters.';
        }
        if ($description === '') {
            $errors['description'] = 'Product description is required.';
        } elseif (mb_strlen($description) > 500) {
            $errors['description'] = 'Description must not exceed 500 characters.';
        }

        $displayPrice = null;
        if ($priceInput !== '') {
            if (!is_numeric($priceInput) || (float) $priceInput < 0 || (float) $priceInput > 9999999999.99) {
                $errors['display_price'] = 'Display price must be a non-negative amount.';
            } else {
                $displayPrice = number_format((float) $priceInput, 2, '.', '');
            }
        }

        if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 99999) {
            $errors['sort_order'] = 'Display order must be a whole number between 0 and 99999.';
        }

        return [[
            'name' => $name,
            'category' => $category,
            'brand' => $brand === '' ? null : $brand,
            'model' => $model === '' ? null : $model,
            'description' => $description,
            'display_price' => $displayPrice,
            'is_published' => filter_var($payload['is_published'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'sort_order' => $sortOrder === false ? 0 : $sortOrder,
        ], $errors];
    }

    private function stringValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) InputSanitizer::sanitizeValue((string) $value));
    }
}
