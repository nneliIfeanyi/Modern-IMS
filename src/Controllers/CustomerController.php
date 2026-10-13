<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Models\CustomerLedgerModel;
use App\Models\CustomerModel;
use App\Models\SalesOrderModel;
use App\Models\SalesOrderPaymentModel;
use App\Services\SalesOrderException;
use App\Services\SmsNotificationService;

final class CustomerController
{
    private CustomerModel $customers;
    private CustomerLedgerModel $ledger;
    private SalesOrderModel $orders;
    private SalesOrderPaymentModel $payments;
    private SmsNotificationService $sms;

    public function __construct()
    {
        $this->customers = new CustomerModel();
        $this->ledger = new CustomerLedgerModel();
        $this->orders = new SalesOrderModel();
        $this->payments = new SalesOrderPaymentModel();
        $this->sms = new SmsNotificationService();
    }

    public function index(Request $request)
    {
        $search = (string) $request->query('search', '');
        return Response::success($this->customers->all($search), 'Customers loaded.');
    }

    public function show(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid customer id.', null, 422);
        }

        $customer = $this->customers->findById($id);
        if ($customer === null) {
            return Response::error('Customer not found.', null, 404);
        }

        return Response::success([
            'customer' => $customer,
            'summary' => $this->ledger->summary($id),
            'entries' => $this->ledger->entries($id, 100),
        ], 'Customer loaded.');
    }

    public function create(Request $request)
    {
        [$data, $errors] = $this->validatePayload($request);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $code = $this->generateCustomerCode();
        $id = $this->customers->create($data + ['customer_code' => $code]);
        $customer = $this->customers->findById($id);
        return Response::success($customer, 'Customer created.', 201);
    }

    public function update(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid customer id.', null, 422);
        }

        $existing = $this->customers->findById($id);
        if ($existing === null || (int) $existing['is_active'] !== 1) {
            return Response::error('Customer not found.', null, 404);
        }

        [$data, $errors] = $this->validatePayload($request, $id);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $this->customers->update($id, $data);
        return Response::success($this->customers->findById($id), 'Customer updated.');
    }

    public function delete(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid customer id.', null, 422);
        }

        if ($this->customers->hasOpenSalesOrders($id)) {
            return Response::error('Customer cannot be deactivated while open sales orders exist.', null, 409);
        }

        if ($this->customers->deactivate($id)) {
            return Response::success(null, 'Customer deactivated.');
        }

        return Response::error('Customer not found.', null, 404);
    }

    public function payment(Request $request)
    {
        $customerId = (int) $request->attribute('id', 0);
        if ($customerId <= 0) {
            return Response::error('Invalid customer id.', null, 422);
        }

        $customer = $this->customers->findActiveById($customerId);
        if ($customer === null) {
            return Response::error('Customer not found.', null, 404);
        }

        $salesOrderId = (int) $request->json('sales_order_id', 0);
        $amount = (float) $request->json('amount', 0);
        $method = trim((string) $request->json('method', 'cash'));
        $reference = trim((string) $request->json('reference', ''));
        $notes = trim((string) $request->json('notes', ''));
        $paidAt = trim((string) $request->json('paid_at', ''));

        $errors = [];
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        }
        if ($method === '') {
            $errors['method'] = 'Payment method is required.';
        }

        $methodAliases = [
            'transfer' => 'bank_transfer',
        ];
        $normalizedMethod = $methodAliases[$method] ?? $method;
        if (!in_array($normalizedMethod, ['cash', 'bank_transfer', 'card', 'cheque', 'other'], true)) {
            $errors['method'] = 'Payment method must be cash, transfer, card, cheque, or other.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        if ($salesOrderId > 0) {
            $order = $this->orders->findById($salesOrderId);
            if ($order === null) {
                return Response::error('Sales order not found.', null, 404);
            }
            if ((int) ($order['customer_id'] ?? 0) !== $customerId) {
                return Response::error('Sales order does not belong to this customer.', null, 409);
            }
        }

        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $paymentId = $this->payments->create([
                'sales_order_id' => $salesOrderId > 0 ? $salesOrderId : null,
                'amount' => number_format($amount, 2, '.', ''),
                'method' => $normalizedMethod,
                'reference' => $reference === '' ? null : $reference,
                'notes' => $notes === '' ? null : $notes,
                'paid_at' => $paidAt === '' ? date('Y-m-d H:i:s') : $paidAt,
                'created_by' => $userId > 0 ? $userId : null,
            ]);

            $paymentCode = $this->ledger->record([
                'customer_id' => $customerId,
                'sales_order_id' => $salesOrderId > 0 ? $salesOrderId : null,
                'sales_order_payment_id' => $paymentId,
                'entry_type' => 'payment',
                'direction' => 'credit',
                'amount' => number_format($amount, 2, '.', ''),
                'reference' => $reference === '' ? null : $reference,
                'notes' => $notes === '' ? null : $notes,
                'created_by' => $userId > 0 ? $userId : null,
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $this->sms->notifyPayment($customer, $amount, $normalizedMethod, $paymentId, $userId);

        return Response::success([
            'payment_id' => $paymentId,
            'ledger_id' => $paymentCode,
            'payment' => [
                'id' => $paymentId,
                'amount' => number_format($amount, 2, '.', ''),
                'method' => $normalizedMethod,
                'reference' => $reference === '' ? null : $reference,
                'notes' => $notes === '' ? null : $notes,
                'paid_at' => $paidAt === '' ? date('Y-m-d H:i:s') : $paidAt,
            ],
            'summary' => $this->ledger->summary($customerId),
            'entries' => $this->ledger->entries($customerId, 25),
        ], 'Payment recorded.', 201);
    }

    public function statement(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid customer id.', null, 422);
        }

        $customer = $this->customers->findById($id);
        if ($customer === null) {
            return Response::error('Customer not found.', null, 404);
        }

        return Response::success([
            'customer' => $customer,
            'summary' => $this->ledger->summary($id),
            'entries' => $this->ledger->entries($id, 100),
        ], 'Customer statement loaded.');
    }

    private function generateCustomerCode(): string
    {
        $existing = $this->customers->all();
        $max = 0;
        foreach ($existing as $customer) {
            if (preg_match('/CUST-(\d+)/', (string) ($customer['customer_code'] ?? ''), $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return 'CUST-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function validatePayload(Request $request, ?int $exceptId = null): array
    {
        $fullName = trim((string) $request->json('full_name', ''));
        $phone = trim((string) $request->json('phone', ''));
        $email = trim((string) $request->json('email', ''));
        $address = trim((string) $request->json('address', ''));
        $creditLimit = (string) $request->json('credit_limit', '0');
        $openingBalance = (string) $request->json('opening_balance', '0');

        $errors = [];

        if ($fullName === '') {
            $errors['full_name'] = 'Customer name is required.';
        } elseif (mb_strlen($fullName) > 150) {
            $errors['full_name'] = 'Customer name must not exceed 150 characters.';
        }

        if ($phone !== '' && mb_strlen($phone) > 40) {
            $errors['phone'] = 'Phone must not exceed 40 characters.';
        }

        if ($email !== '') {
            if (mb_strlen($email) > 190) {
                $errors['email'] = 'Email must not exceed 190 characters.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Email must be valid.';
            }
        }

        if ($address !== '' && mb_strlen($address) > 255) {
            $errors['address'] = 'Address must not exceed 255 characters.';
        }

        if (!is_numeric($creditLimit) || (float) $creditLimit < 0) {
            $errors['credit_limit'] = 'Credit limit must be zero or more.';
        }

        if (!is_numeric($openingBalance)) {
            $errors['opening_balance'] = 'Opening balance must be numeric.';
        }

        return [[
            'full_name' => $fullName,
            'phone' => $phone === '' ? null : $phone,
            'email' => $email === '' ? null : $email,
            'address' => $address === '' ? null : $address,
            'credit_limit' => number_format((float) $creditLimit, 2, '.', ''),
            'opening_balance' => number_format((float) $openingBalance, 2, '.', ''),
        ], $errors];
    }
}
