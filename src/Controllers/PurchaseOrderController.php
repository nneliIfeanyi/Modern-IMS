<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\ExpiryTracking;
use App\Models\ProductModel;
use App\Models\PurchaseOrderModel;
use App\Models\SupplierModel;
use App\Models\WarehouseModel;
use App\Services\PurchaseOrderException;
use App\Services\PurchaseOrderService;

final class PurchaseOrderController
{
    private PurchaseOrderModel $orders;
    private PurchaseOrderService $service;
    private SupplierModel $suppliers;
    private WarehouseModel $warehouses;
    private ProductModel $products;

    public function __construct()
    {
        $this->orders = new PurchaseOrderModel();
        $this->service = new PurchaseOrderService();
        $this->suppliers = new SupplierModel();
        $this->warehouses = new WarehouseModel();
        $this->products = new ProductModel();
    }

    public function index(Request $request)
    {
        $filters = [
            'status' => (string) $request->query('status', 'all'),
            'supplier_id' => (int) $request->query('supplier_id', 0),
            'search' => (string) $request->query('search', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        return Response::success($this->orders->list($filters), 'Purchase orders loaded.');
    }

    public function show(Request $request)
    {
        $poId = (int) $request->attribute('id', 0);
        if ($poId <= 0) {
            return Response::error('Invalid purchase order id.', null, 422);
        }

        try {
            return Response::success($this->service->hydrate($poId), 'Purchase order loaded.');
        } catch (PurchaseOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function create(Request $request)
    {
        [$header, $items, $errors] = $this->validateDraftPayload($request);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);

        try {
            $result = $this->service->createDraft($header, $items, $userId);
            return Response::success($result, 'Purchase order created.', 201);
        } catch (PurchaseOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function update(Request $request)
    {
        $poId = (int) $request->attribute('id', 0);
        if ($poId <= 0) {
            return Response::error('Invalid purchase order id.', null, 422);
        }

        [$header, $items, $errors] = $this->validateDraftPayload($request);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        try {
            $result = $this->service->updateDraft($poId, $header, $items);
            return Response::success($result, 'Purchase order updated.');
        } catch (PurchaseOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function send(Request $request)
    {
        $poId = (int) $request->attribute('id', 0);
        if ($poId <= 0) {
            return Response::error('Invalid purchase order id.', null, 422);
        }

        try {
            $result = $this->service->send($poId);
            return Response::success($result, 'Purchase order sent.');
        } catch (PurchaseOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function receive(Request $request)
    {
        $poId = (int) $request->attribute('id', 0);
        if ($poId <= 0) {
            return Response::error('Invalid purchase order id.', null, 422);
        }

        $receipts = $request->json('receipts', []);
        if (!is_array($receipts) || $receipts === []) {
            return Response::error('Validation failed.', ['fields' => ['receipts' => 'At least one receipt line is required.']], 422);
        }

        $lineErrors = [];
        foreach ($receipts as $idx => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $qty = (string) ($line['quantity'] ?? '');
            $batchNumber = trim((string) ($line['batch_number'] ?? ''));
            $expiryDate = trim((string) ($line['expiry_date'] ?? ''));

            if ($itemId <= 0) {
                $lineErrors["receipts.{$idx}.item_id"] = 'item_id is required.';
            }
            if (!preg_match('/^\d+$/', $qty) || (int) $qty <= 0) {
                $lineErrors["receipts.{$idx}.quantity"] = 'quantity must be a whole number greater than zero.';
            }
            if (ExpiryTracking::enabled() && ($batchNumber === '' || mb_strlen($batchNumber) > 80)) {
                $lineErrors["receipts.{$idx}.batch_number"] = 'batch_number is required and must not exceed 80 characters.';
            }
            if (ExpiryTracking::enabled() && (!$this->isValidDate($expiryDate) || $expiryDate <= date('Y-m-d'))) {
                $lineErrors["receipts.{$idx}.expiry_date"] = 'expiry_date must be a future date in YYYY-MM-DD format.';
            }
        }

        if ($lineErrors !== []) {
            return Response::error('Validation failed.', ['fields' => $lineErrors], 422);
        }

        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);

        try {
            $result = $this->service->receive($poId, $receipts, $userId);
            return Response::success($result, 'Purchase order receipt saved.');
        } catch (PurchaseOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    private function validateDraftPayload(Request $request): array
    {
        $header = [
            'supplier_id' => (int) $request->json('supplier_id', 0),
            'warehouse_id' => (int) $request->json('warehouse_id', 0),
            'order_date' => trim((string) $request->json('order_date', '')),
            'expected_date' => trim((string) $request->json('expected_date', '')),
            'notes' => trim((string) $request->json('notes', '')),
        ];

        $items = $request->json('items', []);
        $errors = [];

        $supplier = $header['supplier_id'] > 0 ? $this->suppliers->findById($header['supplier_id']) : null;
        if ($supplier === null || (int) ($supplier['is_active'] ?? 0) !== 1) {
            $errors['supplier_id'] = 'A valid active supplier is required.';
        }

        if ($header['warehouse_id'] <= 0 || $this->warehouses->findById($header['warehouse_id']) === null) {
            $errors['warehouse_id'] = 'A valid warehouse is required.';
        }

        if ($header['order_date'] !== '' && !$this->isValidDate($header['order_date'])) {
            $errors['order_date'] = 'order_date must be in YYYY-MM-DD format.';
        }

        if ($header['expected_date'] !== '' && !$this->isValidDate($header['expected_date'])) {
            $errors['expected_date'] = 'expected_date must be in YYYY-MM-DD format.';
        }

        if (!is_array($items) || $items === []) {
            $errors['items'] = 'At least one line item is required.';
            return [$header, [], $errors];
        }

        $validatedItems = [];
        $seenProducts = [];

        foreach ($items as $idx => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qtyOrdered = (string) ($item['qty_ordered'] ?? '');
            $unitCost = $item['unit_cost'] ?? null;

            if ($productId <= 0) {
                $errors["items.{$idx}.product_id"] = 'product_id is required.';
                continue;
            }

            $product = $this->products->findById($productId);
            if ($product === null || (int) ($product['is_active'] ?? 0) !== 1) {
                $errors["items.{$idx}.product_id"] = 'product_id must reference an active product.';
                continue;
            }

            if (isset($seenProducts[$productId])) {
                $errors["items.{$idx}.product_id"] = 'Duplicate product lines are not allowed.';
                continue;
            }
            $seenProducts[$productId] = true;

            if (!preg_match('/^\d+$/', $qtyOrdered) || (int) $qtyOrdered <= 0) {
                $errors["items.{$idx}.qty_ordered"] = 'qty_ordered must be a whole number greater than zero.';
                continue;
            }

            if (!is_numeric($unitCost) || (float) $unitCost < 0) {
                $errors["items.{$idx}.unit_cost"] = 'unit_cost must be a non-negative number.';
                continue;
            }

            $validatedItems[] = [
                'product_id' => $productId,
                'qty_ordered' => (int) $qtyOrdered,
                'unit_cost' => number_format((float) $unitCost, 2, '.', ''),
            ];
        }

        $header['order_date'] = $header['order_date'] === '' ? null : $header['order_date'];
        $header['expected_date'] = $header['expected_date'] === '' ? null : $header['expected_date'];
        $header['notes'] = $header['notes'] === '' ? null : $header['notes'];

        return [$header, $validatedItems, $errors];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
