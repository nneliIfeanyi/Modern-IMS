<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\WarehouseModel;

final class WarehouseController
{
    private WarehouseModel $warehouses;

    public function __construct()
    {
        $this->warehouses = new WarehouseModel();
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        return Response::success($this->warehouses->all($search), 'Warehouses loaded.');
    }

    public function create(Request $request)
    {
        [$data, $errors] = $this->validatePayload($request);

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $id = $this->warehouses->create($data);
        $warehouse = $this->warehouses->findById($id);

        return Response::success($warehouse, 'Warehouse created.', 201);
    }

    public function update(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid warehouse id.', null, 422);
        }

        $existing = $this->warehouses->findById($id);
        if ($existing === null) {
            return Response::error('Warehouse not found.', null, 404);
        }

        [$data, $errors] = $this->validatePayload($request, $id);

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $this->warehouses->update($id, $data);
        $warehouse = $this->warehouses->findById($id);

        return Response::success($warehouse, 'Warehouse updated.');
    }

    public function delete(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid warehouse id.', null, 422);
        }

        $existing = $this->warehouses->findById($id);
        if ($existing === null) {
            return Response::error('Warehouse not found.', null, 404);
        }

        if ($this->warehouses->hasDependencies($id)) {
            return Response::error('Warehouse cannot be deleted because it is referenced by stock or orders.', null, 409);
        }

        $this->warehouses->delete($id);
        return Response::success(null, 'Warehouse deleted.');
    }

    private function validatePayload(Request $request, ?int $exceptId = null): array
    {
        $name = trim((string) $request->json('name', ''));
        $location = trim((string) $request->json('location', ''));
        $location = $location === '' ? null : $location;

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Warehouse name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Warehouse name must not exceed 100 characters.';
        } elseif ($this->warehouses->nameExists($name, $exceptId)) {
            $errors['name'] = 'Warehouse name already exists.';
        }

        if ($location !== null && mb_strlen($location) > 190) {
            $errors['location'] = 'Location must not exceed 190 characters.';
        }

        $data = [
            'name' => $name,
            'location' => $location,
        ];

        return [$data, $errors];
    }
}
