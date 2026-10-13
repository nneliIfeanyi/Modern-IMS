<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\ReportsModel;

final class ReportsController
{
    private ReportsModel $reports;

    public function __construct()
    {
        $this->reports = new ReportsModel();
    }

    public function stockValuation(Request $request)
    {
        $warehouseId = (int) $request->query('warehouse_id', 0);
        $search = (string) $request->query('search', '');

        $data = $this->reports->stockValuation($warehouseId > 0 ? $warehouseId : null, $search);
        return Response::success($data, 'Stock valuation report loaded.');
    }

    public function lowStock(Request $request)
    {
        $warehouseId = (int) $request->query('warehouse_id', 0);
        $search = (string) $request->query('search', '');

        $items = $this->reports->lowStock($search, $warehouseId > 0 ? $warehouseId : null);
        return Response::success(['items' => $items], 'Low-stock report loaded.');
    }

    public function movementHistory(Request $request)
    {
        $filters = [
            'type' => (string) $request->query('type', 'all'),
            'warehouse_id' => (int) $request->query('warehouse_id', 0),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
            'search' => (string) $request->query('search', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 50),
        ];

        $export = strtolower((string) $request->query('export', ''));
        if ($export === 'csv' || $export === '1') {
            $result = $this->reports->movementHistory($filters, false);
            return $this->csvResponse($result['items']);
        }

        return Response::success($this->reports->movementHistory($filters, true), 'Movement history report loaded.');
    }

    private function csvResponse(array $rows)
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            return Response::error('Failed to generate CSV export.', null, 500);
        }

        fputcsv($stream, ['Date', 'Type', 'SKU', 'Product', 'Warehouse', 'Quantity', 'Reference', 'Created by', 'Notes']);

        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['created_at'] ?? ''),
                (string) ($row['movement_type'] ?? ''),
                (string) ($row['sku'] ?? ''),
                (string) ($row['product_name'] ?? ''),
                (string) ($row['warehouse_name'] ?? ''),
                (string) ($row['quantity'] ?? ''),
                (string) ($row['reference'] ?? ''),
                (string) ($row['created_by_name'] ?? ''),
                (string) ($row['notes'] ?? ''),
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'movement-history-' . date('Ymd-His') . '.csv';

        return Response::raw(
            $csv === false ? '' : $csv,
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
    }
}
