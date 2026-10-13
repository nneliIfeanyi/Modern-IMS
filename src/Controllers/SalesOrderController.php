<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\SimplePdf;
use App\Models\CustomerModel;
use App\Models\AppSettingModel;
use App\Models\ProductModel;
use App\Models\SalesOrderModel;
use App\Models\WarehouseModel;
use App\Services\SalesOrderException;
use App\Services\SalesOrderService;

final class SalesOrderController
{
    private SalesOrderModel $orders;
    private SalesOrderService $service;
    private WarehouseModel $warehouses;
    private ProductModel $products;
    private CustomerModel $customers;
    private AppSettingModel $appSettings;

    public function __construct()
    {
        $this->orders = new SalesOrderModel();
        $this->service = new SalesOrderService();
        $this->warehouses = new WarehouseModel();
        $this->products = new ProductModel();
        $this->customers = new CustomerModel();
        $this->appSettings = new AppSettingModel();
    }

    public function index(Request $request)
    {
        $filters = [
            'status' => (string) $request->query('status', 'all'),
            'warehouse_id' => (int) $request->query('warehouse_id', 0),
            'search' => (string) $request->query('search', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
        ];

        return Response::success($this->orders->list($filters), 'Sales orders loaded.');
    }

    public function show(Request $request)
    {
        $soId = (int) $request->attribute('id', 0);
        if ($soId <= 0) {
            return Response::error('Invalid sales order id.', null, 422);
        }

        try {
            return Response::success($this->service->hydrate($soId), 'Sales order loaded.');
        } catch (SalesOrderException $exception) {
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
            return Response::success($result, 'Sales order created.', 201);
        } catch (SalesOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function update(Request $request)
    {
        $soId = (int) $request->attribute('id', 0);
        if ($soId <= 0) {
            return Response::error('Invalid sales order id.', null, 422);
        }

        [$header, $items, $errors] = $this->validateDraftPayload($request);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        try {
            $result = $this->service->updateDraft($soId, $header, $items);
            return Response::success($result, 'Sales order updated.');
        } catch (SalesOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function confirm(Request $request)
    {
        $soId = (int) $request->attribute('id', 0);
        if ($soId <= 0) {
            return Response::error('Invalid sales order id.', null, 422);
        }

        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);

        try {
            $result = $this->service->confirm($soId, $userId);
            return Response::success($result, 'Sales order confirmed.');
        } catch (SalesOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function fulfill(Request $request)
    {
        $soId = (int) $request->attribute('id', 0);
        if ($soId <= 0) {
            return Response::error('Invalid sales order id.', null, 422);
        }

        $fulfillments = $request->json('fulfillments', []);
        if (!is_array($fulfillments) || $fulfillments === []) {
            return Response::error('Validation failed.', ['fields' => ['fulfillments' => 'At least one fulfillment line is required.']], 422);
        }

        $lineErrors = [];
        foreach ($fulfillments as $idx => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $qty = (string) ($line['quantity'] ?? '');

            if ($itemId <= 0) {
                $lineErrors["fulfillments.{$idx}.item_id"] = 'item_id is required.';
            }
            if (!preg_match('/^\d+$/', $qty) || (int) $qty <= 0) {
                $lineErrors["fulfillments.{$idx}.quantity"] = 'quantity must be a whole number greater than zero.';
            }
        }

        if ($lineErrors !== []) {
            return Response::error('Validation failed.', ['fields' => $lineErrors], 422);
        }

        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);

        try {
            $result = $this->service->fulfill($soId, $fulfillments, $userId);
            return Response::success($result, 'Sales order fulfillment saved.');
        } catch (SalesOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }
    }

    public function receipt(Request $request)
    {
        $soId = (int) $request->attribute('id', 0);
        if ($soId <= 0) {
            return Response::error('Invalid sales order id.', null, 422);
        }

        try {
            $receipt = $this->service->receipt($soId);
        } catch (SalesOrderException $exception) {
            return Response::error($exception->getMessage(), $exception->extra(), $exception->statusCode());
        }

        $format = strtolower((string) $request->query('format', 'json'));
        if ($format === 'pdf') {
            $lines = $this->buildReceiptLines($receipt);
            $pdf = SimplePdf::fromLines($lines, 'Sales Receipt');

            return Response::raw($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $receipt['order']['so_number'] . '-receipt.pdf"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
        }

        return Response::success($receipt, 'Receipt loaded.');
    }

    private function validateDraftPayload(Request $request): array
    {
        $header = [
            'warehouse_id' => (int) $request->json('warehouse_id', 0),
            'customer_id' => (int) $request->json('customer_id', 0),
            'customer_name' => trim((string) $request->json('customer_name', '')),
            'order_date' => trim((string) $request->json('order_date', '')),
            'payment_mode' => trim((string) $request->json('payment_mode', 'cash')),
            'initial_payment' => $request->json('initial_payment', 0),
            'notes' => trim((string) $request->json('notes', '')),
        ];

        $items = $request->json('items', []);
        $errors = [];

        if ($header['warehouse_id'] <= 0 || $this->warehouses->findById($header['warehouse_id']) === null) {
            $errors['warehouse_id'] = 'A valid warehouse is required.';
        }

        if ($header['customer_id'] > 0 && $this->customers->findActiveById($header['customer_id']) === null) {
            $errors['customer_id'] = 'customer_id must reference an active customer.';
        }

        if ($header['customer_name'] !== '' && mb_strlen($header['customer_name']) > 150) {
            $errors['customer_name'] = 'customer_name must not exceed 150 characters.';
        }

        if ($header['order_date'] !== '' && !$this->isValidDate($header['order_date'])) {
            $errors['order_date'] = 'order_date must be in YYYY-MM-DD format.';
        }

        if (!in_array($header['payment_mode'], ['cash', 'credit', 'mixed'], true)) {
            $errors['payment_mode'] = 'payment_mode must be cash, credit, or mixed.';
        }

        if (!is_numeric($header['initial_payment']) || (float) $header['initial_payment'] < 0) {
            $errors['initial_payment'] = 'Initial payment must be zero or more.';
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
            $unitPrice = $item['unit_price'] ?? null;

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

            if (!is_numeric($unitPrice) || (float) $unitPrice < 0) {
                $errors["items.{$idx}.unit_price"] = 'unit_price must be a non-negative number.';
                continue;
            }

            $validatedItems[] = [
                'product_id' => $productId,
                'qty_ordered' => (int) $qtyOrdered,
                'unit_price' => number_format((float) $unitPrice, 2, '.', ''),
            ];
        }

        $header['customer_name'] = $header['customer_name'] === '' ? null : $header['customer_name'];
        $header['order_date'] = $header['order_date'] === '' ? null : $header['order_date'];
        $header['customer_id'] = $header['customer_id'] > 0 ? $header['customer_id'] : null;
        $header['payment_mode'] = $header['payment_mode'] === '' ? 'cash' : $header['payment_mode'];
        $header['initial_payment'] = number_format((float) $header['initial_payment'], 2, '.', '');
        $header['notes'] = $header['notes'] === '' ? null : $header['notes'];

        return [$header, $validatedItems, $errors];
    }

    private function buildReceiptLines(array $receipt): array
    {
        $order = $receipt['order'];
        $items = $receipt['items'] ?? [];
        $payments = $receipt['payments'] ?? [];
        $summary = $receipt['summary'] ?? [];
        $appName = $this->appSettings->getAll()['site_name'] ?? 'ANTOBELL IMS';

        $lines = [
            $appName . ' SALES RECEIPT',
            'Receipt/Order: ' . ($order['so_number'] ?? '-'),
            'Customer: ' . ($order['customer_name'] ?? 'Walk-in customer'),
            'Warehouse: ' . ($order['warehouse_name'] ?? '-'),
            'Status: ' . ($order['status'] ?? '-'),
            'Order date: ' . ($order['order_date'] ?? '-'),
            ' ',
            'Items',
        ];

        foreach ($items as $item) {
            $lines[] = sprintf(
                '%s x%s @ %s = %s',
                (string) ($item['product_name'] ?? '-'),
                (string) ($item['qty_ordered'] ?? '0'),
                (string) ($item['unit_price'] ?? '0.00'),
                (string) ($item['subtotal'] ?? '0.00')
            );
        }

        $lines[] = ' ';
        $lines[] = 'Total: NGN ' . ($summary['total_amount'] ?? '0.00');
        $lines[] = 'Paid: NGN ' . ($summary['total_paid'] ?? '0.00');
        $lines[] = 'Balance: NGN ' . ($summary['balance_due'] ?? '0.00');
        $lines[] = ' ';
        $lines[] = 'Payments';

        if ($payments === []) {
            $lines[] = 'No payments recorded yet.';
        } else {
            foreach ($payments as $payment) {
                $lines[] = sprintf(
                    '%s | %s | %s',
                    (string) ($payment['paid_at'] ?? ''),
                    (string) ($payment['method'] ?? ''),
                    (string) ($payment['amount'] ?? '0.00')
                );
            }
        }

        return $lines;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
