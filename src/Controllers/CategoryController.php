<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\CategoryModel;

final class CategoryController
{
    private CategoryModel $categories;

    public function __construct()
    {
        $this->categories = new CategoryModel();
    }

    public function index(Request $request)
    {
        unset($request);

        return Response::success($this->categories->all(), 'Categories loaded.');
    }

    public function create(Request $request)
    {
        $name = trim((string) $request->json('name', ''));
        $description = trim((string) $request->json('description', ''));
        $description = $description === '' ? null : $description;

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Category name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Category name must not exceed 100 characters.';
        } elseif ($this->categories->nameExists($name)) {
            $errors['name'] = 'Category name already exists.';
        }

        if ($description !== null && mb_strlen($description) > 255) {
            $errors['description'] = 'Description must not exceed 255 characters.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $id = $this->categories->create($name, $description);
        $row = $this->categories->findById($id);

        return Response::success($row, 'Category created.', 201);
    }

    public function update(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid category id.', null, 422);
        }

        if ($this->categories->findById($id) === null) {
            return Response::error('Category not found.', null, 404);
        }

        [$name, $description, $errors] = $this->validatedPayload($request, $id);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $this->categories->update($id, $name, $description);
        return Response::success($this->categories->findById($id), 'Category updated.');
    }

    public function delete(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid category id.', null, 422);
        }

        if ($this->categories->findById($id) === null) {
            return Response::error('Category not found.', null, 404);
        }

        if ($this->categories->hasDependencies($id)) {
            return Response::error('Category cannot be deleted because products reference it.', null, 409);
        }

        $this->categories->delete($id);
        return Response::success(null, 'Category deleted.');
    }

    private function validatedPayload(Request $request, ?int $exceptId = null): array
    {
        $name = trim((string) $request->json('name', ''));
        $description = trim((string) $request->json('description', ''));
        $description = $description === '' ? null : $description;
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Category name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Category name must not exceed 100 characters.';
        } elseif ($this->categories->nameExists($name, $exceptId)) {
            $errors['name'] = 'Category name already exists.';
        }

        if ($description !== null && mb_strlen($description) > 255) {
            $errors['description'] = 'Description must not exceed 255 characters.';
        }

        return [$name, $description, $errors];
    }
}
