<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\ProductModel;
use App\Models\StockMovementModel;
use App\Models\WarehouseModel;
use App\Services\StockMovementException;
use App\Services\StockMovementService;
use App\Services\SmsNotificationService;

final class StockMovementController
{
    private StockMovementModel $movements;
    private StockMovementService $service;
    private ProductModel $products;
    private WarehouseModel $warehouses;
    private SmsNotificationService $sms;

    public function __construct()
    {
        $this->movements = new StockMovementModel();
        $this->service = new StockMovementService();
        $this->products = new ProductModel();
        $this->warehouses = new WarehouseModel();
        $this->sms = new SmsNotificationService();
    }

    public function index(Request $request)
    {
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $limit = (int) $request->query('limit', 0);
        $perPage = $limit > 0 ? $limit : (int) $request->query('per_page', 20);

        $errors = [];
        if ($dateFrom !== '' && !$this->isValidDate($dateFrom)) {
            $errors['date_from'] = 'date_from must be in YYYY-MM-DD format.';
        }
        if ($dateTo !== '' && !$this->isValidDate($dateTo)) {
            $errors['date_to'] = 'date_to must be in YYYY-MM-DD format.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $filters = [
            'type' => (string) $request->query('type', 'all'),
            'warehouse_id' => (int) $request->query('warehouse_id', 0),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => (string) $request->query('search', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => $perPage,
        ];

        return Response::success($this->movements->list($filters), 'Stock movements loaded.');
    }

    public function create(Request $request)
    {
        $payload = [
            'product_id' => (int) $request->json('product_id', 0),
            'warehouse_id' => (int) $request->json('warehouse_id', 0),
            'type' => strtolower(trim((string) $request->json('type', ''))),
            'quantity' => $request->json('quantity'),
            'destination_warehouse_id' => (int) $request->json('destination_warehouse_id', 0),
            'reference' => trim((string) $request->json('reference', '')),
            'notes' => trim((string) $request->json('notes', '')),
        ];

        [$cleanPayload, $errors] = $this->validatePayload($payload);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);

        try {
            $result = $this->service->record($cleanPayload, $userId);
            $this->sms->notifyLowStock((int) $cleanPayload['product_id'], $userId);
            return Response::success($result, 'Stock movement recorded.', 201);
        } catch (StockMovementException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    private function validatePayload(array $payload): array
    {
        $errors = [];

        $productId = (int) $payload['product_id'];
        $warehouseId = (int) $payload['warehouse_id'];
        $type = $payload['type'];
        $quantityRaw = (string) $payload['quantity'];
        $destinationWarehouseId = (int) $payload['destination_warehouse_id'];
        $reference = $payload['reference'] !== '' ? $payload['reference'] : null;
        $notes = $payload['notes'] !== '' ? $payload['notes'] : null;

        if (!in_array($type, ['in', 'out', 'adjustment', 'transfer'], true)) {
            $errors['type'] = 'Movement type must be one of in, out, adjustment, transfer.';
        }

        $product = $productId > 0 ? $this->products->findById($productId) : null;
        if ($product === null || (int) ($product['is_active'] ?? 0) !== 1) {
            $errors['product_id'] = 'A valid active product is required.';
        }

        if ($warehouseId <= 0 || $this->warehouses->findById($warehouseId) === null) {
            $errors['warehouse_id'] = 'A valid warehouse is required.';
        }

        if (!preg_match('/^-?\d+$/', $quantityRaw)) {
            $errors['quantity'] = 'Quantity must be a whole number.';
        }

        $numericQuantity = (float) ((int) $quantityRaw);
        if (!isset($errors['quantity'])) {
            if ($type === 'adjustment') {
                if ($numericQuantity == 0.0) {
                    $errors['quantity'] = 'Adjustment quantity cannot be zero.';
                }
            } elseif ($numericQuantity <= 0) {
                $errors['quantity'] = 'Quantity must be greater than zero.';
            }
        }

        if ($type === 'transfer') {
            if ($destinationWarehouseId <= 0 || $this->warehouses->findById($destinationWarehouseId) === null) {
                $errors['destination_warehouse_id'] = 'A valid destination warehouse is required for transfer.';
            } elseif ($destinationWarehouseId === $warehouseId) {
                $errors['destination_warehouse_id'] = 'Destination warehouse must differ from source warehouse.';
            }
        }

        if ($reference !== null && mb_strlen($reference) > 100) {
            $errors['reference'] = 'Reference must not exceed 100 characters.';
        }

        if ($notes !== null && mb_strlen($notes) > 255) {
            $errors['notes'] = 'Notes must not exceed 255 characters.';
        }

        return [[
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => $type,
            'quantity' => $numericQuantity,
            'destination_warehouse_id' => $destinationWarehouseId,
            'reference' => $reference,
            'notes' => $notes,
        ], $errors];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
