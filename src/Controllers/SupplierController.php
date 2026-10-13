<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\SupplierModel;

final class SupplierController
{
    private SupplierModel $suppliers;

    public function __construct()
    {
        $this->suppliers = new SupplierModel();
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        return Response::success($this->suppliers->allActive($search), 'Suppliers loaded.');
    }

    public function create(Request $request)
    {
        [$data, $errors] = $this->validatePayload($request);

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $id = $this->suppliers->create($data);
        $supplier = $this->suppliers->findById($id);
        if ($supplier !== null) {
            unset($supplier['is_active']);
        }

        return Response::success($supplier, 'Supplier created.', 201);
    }

    public function update(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid supplier id.', null, 422);
        }

        $existing = $this->suppliers->findById($id);
        if ($existing === null || (int) $existing['is_active'] !== 1) {
            return Response::error('Supplier not found.', null, 404);
        }

        [$data, $errors] = $this->validatePayload($request, $id);

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $this->suppliers->update($id, $data);
        $supplier = $this->suppliers->findById($id);
        if ($supplier !== null) {
            unset($supplier['is_active']);
        }

        return Response::success($supplier, 'Supplier updated.');
    }

    public function delete(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid supplier id.', null, 422);
        }

        $existing = $this->suppliers->findById($id);
        if ($existing === null || (int) $existing['is_active'] !== 1) {
            return Response::error('Supplier not found.', null, 404);
        }

        if ($this->suppliers->hasOpenPurchaseOrders($id)) {
            return Response::error('Supplier cannot be deactivated while open purchase orders exist.', null, 409);
        }

        $this->suppliers->deactivate($id);
        return Response::success(null, 'Supplier deactivated.');
    }

    private function validatePayload(Request $request, ?int $exceptId = null): array
    {
        $name = trim((string) $request->json('name', ''));
        $contactPerson = trim((string) $request->json('contact_person', ''));
        $email = trim((string) $request->json('email', ''));
        $phone = trim((string) $request->json('phone', ''));
        $address = trim((string) $request->json('address', ''));

        $contactPerson = $contactPerson === '' ? null : $contactPerson;
        $email = $email === '' ? null : $email;
        $phone = $phone === '' ? null : $phone;
        $address = $address === '' ? null : $address;

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Supplier name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Supplier name must not exceed 150 characters.';
        } elseif ($this->suppliers->nameExists($name, $exceptId)) {
            $errors['name'] = 'Supplier name already exists.';
        }

        if ($contactPerson !== null && mb_strlen($contactPerson) > 120) {
            $errors['contact_person'] = 'Contact person must not exceed 120 characters.';
        }

        if ($email !== null) {
            if (mb_strlen($email) > 190) {
                $errors['email'] = 'Email must not exceed 190 characters.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Email must be a valid email address.';
            }
        }

        if ($phone !== null && mb_strlen($phone) > 40) {
            $errors['phone'] = 'Phone must not exceed 40 characters.';
        }

        if ($address !== null && mb_strlen($address) > 255) {
            $errors['address'] = 'Address must not exceed 255 characters.';
        }

        $data = [
            'name' => $name,
            'contact_person' => $contactPerson,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
        ];

        return [$data, $errors];
    }
}
