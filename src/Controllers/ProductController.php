<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\CategoryModel;
use App\Models\ProductModel;

final class ProductController
{
    private ProductModel $products;
    private CategoryModel $categories;

    public function __construct()
    {
        $this->products = new ProductModel();
        $this->categories = new CategoryModel();
    }

    public function index(Request $request)
    {
        $filters = [
            'search' => (string) $request->query('search', ''),
            'category_id' => (int) $request->query('category_id', 0),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        return Response::success($this->products->list($filters), 'Products loaded.');
    }

    public function show(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid product id.', null, 422);
        }

        $product = $this->products->findById($id);
        if ($product === null || (int) $product['is_active'] !== 1) {
            return Response::error('Product not found.', null, 404);
        }

        unset($product['is_active']);

        return Response::success($product, 'Product loaded.');
    }

    public function create(Request $request)
    {
        [$data, $errors] = $this->validatePayload($request);

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $id = $this->products->create($data);
        $product = $this->products->findById($id);
        unset($product['is_active']);

        return Response::success($product, 'Product created.', 201);
    }

    public function update(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid product id.', null, 422);
        }

        $existing = $this->products->findById($id);
        if ($existing === null || (int) $existing['is_active'] !== 1) {
            return Response::error('Product not found.', null, 404);
        }

        [$data, $errors] = $this->validatePayload($request, $id);

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $this->products->update($id, $data);
        $product = $this->products->findById($id);
        unset($product['is_active']);

        return Response::success($product, 'Product updated.');
    }

    public function delete(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid product id.', null, 422);
        }

        $deleted = $this->products->softDelete($id);
        if (!$deleted) {
            return Response::error('Product not found.', null, 404);
        }

        return Response::success(null, 'Product deleted.');
    }

    private function validatePayload(Request $request, ?int $exceptId = null): array
    {
        $name = trim((string) $request->json('name', ''));
        $sku = strtoupper(trim((string) $request->json('sku', '')));
        $unit = trim((string) $request->json('unit', 'piece'));
        $categoryId = (int) $request->json('category_id', 0);
        $costPrice = $request->json('cost_price');
        $sellingPrice = $request->json('selling_price');
        $reorderLevel = $request->json('reorder_level');

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Product name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Product name must not exceed 150 characters.';
        }

        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        } elseif (mb_strlen($sku) > 60) {
            $errors['sku'] = 'SKU must not exceed 60 characters.';
        } elseif ($this->products->skuExists($sku, $exceptId)) {
            $errors['sku'] = 'SKU already exists.';
        }

        if ($unit === '') {
            $errors['unit'] = 'Unit is required.';
        } elseif (mb_strlen($unit) > 40) {
            $errors['unit'] = 'Unit must not exceed 40 characters.';
        }

        if ($categoryId <= 0 || $this->categories->findById($categoryId) === null) {
            $errors['category_id'] = 'A valid category is required.';
        }

        if (!is_numeric($costPrice) || (float) $costPrice < 0) {
            $errors['cost_price'] = 'Cost price must be a valid non-negative number.';
        }

        if (!is_numeric($sellingPrice) || (float) $sellingPrice < 0) {
            $errors['selling_price'] = 'Selling price must be a valid non-negative number.';
        }

        if (!is_numeric($reorderLevel) || (int) $reorderLevel < 0) {
            $errors['reorder_level'] = 'Reorder level must be a valid non-negative integer.';
        }

        $data = [
            'category_id' => $categoryId,
            'name' => $name,
            'sku' => $sku,
            'unit' => $unit,
            'cost_price' => number_format((float) $costPrice, 2, '.', ''),
            'selling_price' => number_format((float) $sellingPrice, 2, '.', ''),
            'reorder_level' => max(0, (int) $reorderLevel),
        ];

        return [$data, $errors];
    }
}
