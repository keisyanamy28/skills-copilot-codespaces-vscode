<?php

declare(strict_types=1);

namespace App;

use DateInterval;
use DateTimeImmutable;
use PDO;

final class PosApi
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status:int,data:array<string,mixed>}
     */
    public function handle(string $method, string $path, array $headers, array $payload = []): array
    {
        return match ([$method, $path]) {
            ['POST', '/api/owner/stocks'] => $this->createStock($headers, $payload),
            ['GET', '/api/owner/stocks/history'] => $this->stockHistory($headers),
            ['POST', '/api/employee/pos/sales'] => $this->createSale($headers, $payload),
            ['POST', '/api/owner/transactions'] => $this->createOwnerTransaction($headers, $payload),
            ['GET', '/api/owner/reports'] => $this->ownerReport($headers),
            ['GET', '/api/owner/dashboard'] => $this->ownerDashboard($headers),
            default => ['status' => 404, 'data' => ['message' => 'Endpoint tidak ditemukan']],
        };
    }

    /** @param array<string, string> $headers */
    private function authorize(array $headers, string $requiredRole): ?array
    {
        $role = strtolower(trim($headers['X-Role'] ?? $headers['x-role'] ?? ''));
        if ($role !== $requiredRole) {
            return ['status' => 403, 'data' => ['message' => 'Akses ditolak untuk role ini']];
        }

        return null;
    }

    /** @param array<string, string> $headers @param array<string,mixed> $payload */
    private function createStock(array $headers, array $payload): array
    {
        if ($response = $this->authorize($headers, 'owner')) {
            return $response;
        }

        $quantity = (int) ($payload['quantity'] ?? 0);
        if ($quantity <= 0) {
            return ['status' => 422, 'data' => ['message' => 'quantity harus lebih dari 0']];
        }

        $stockDate = $this->normalizeDate((string) ($payload['stock_date'] ?? ''));
        if ($stockDate === null) {
            return ['status' => 422, 'data' => ['message' => 'stock_date harus format Y-m-d']];
        }

        $note = trim((string) ($payload['note'] ?? ''));
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);

        $statement = $this->db->prepare('INSERT INTO stock_movements(quantity, stock_date, note, created_at) VALUES(:quantity, :stock_date, :note, :created_at)');
        $statement->execute([
            ':quantity' => $quantity,
            ':stock_date' => $stockDate,
            ':note' => $note,
            ':created_at' => $now,
        ]);

        return [
            'status' => 201,
            'data' => [
                'message' => 'Stok tahu berhasil ditambahkan',
                'current_stock' => $this->currentStock(),
            ],
        ];
    }

    /** @param array<string, string> $headers */
    private function stockHistory(array $headers): array
    {
        if ($response = $this->authorize($headers, 'owner')) {
            return $response;
        }

        $rows = $this->db->query('SELECT id, quantity, stock_date, note, created_at FROM stock_movements ORDER BY stock_date DESC, id DESC')
            ->fetchAll(PDO::FETCH_ASSOC);

        return [
            'status' => 200,
            'data' => [
                'history' => $rows,
                'total_added_stock' => $this->totalStockAdded(),
            ],
        ];
    }

    /** @param array<string, string> $headers @param array<string,mixed> $payload */
    private function createSale(array $headers, array $payload): array
    {
        if ($response = $this->authorize($headers, 'employee')) {
            return $response;
        }

        $quantity = (int) ($payload['quantity'] ?? 0);
        $pricePerItem = (float) ($payload['price_per_item'] ?? 0);
        if ($quantity <= 0 || $pricePerItem <= 0) {
            return ['status' => 422, 'data' => ['message' => 'quantity dan price_per_item harus lebih dari 0']];
        }

        if ($this->currentStock() < $quantity) {
            return ['status' => 422, 'data' => ['message' => 'Stok tahu tidak mencukupi']];
        }

        $transactionDate = $this->normalizeDate((string) ($payload['transaction_date'] ?? ''))
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');

        $amount = round($quantity * $pricePerItem, 2);
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);

        $statement = $this->db->prepare('INSERT INTO transactions(type, amount, quantity, description, transaction_date, created_at, source_role) VALUES("sale", :amount, :quantity, :description, :transaction_date, :created_at, "employee")');
        $statement->execute([
            ':amount' => $amount,
            ':quantity' => $quantity,
            ':description' => trim((string) ($payload['description'] ?? 'Penjualan POS')),
            ':transaction_date' => $transactionDate,
            ':created_at' => $now,
        ]);

        return [
            'status' => 201,
            'data' => [
                'message' => 'Transaksi penjualan berhasil disimpan',
                'sale_amount' => $amount,
                'current_stock' => $this->currentStock(),
            ],
        ];
    }

    /** @param array<string, string> $headers @param array<string,mixed> $payload */
    private function createOwnerTransaction(array $headers, array $payload): array
    {
        if ($response = $this->authorize($headers, 'owner')) {
            return $response;
        }

        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if (!in_array($type, ['income', 'expense'], true)) {
            return ['status' => 422, 'data' => ['message' => 'type harus income atau expense']];
        }

        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 422, 'data' => ['message' => 'amount harus lebih dari 0']];
        }

        $transactionDate = $this->normalizeDate((string) ($payload['transaction_date'] ?? ''))
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');
        $description = trim((string) ($payload['description'] ?? ''));
        $now = (new DateTimeImmutable('now'))->format(DATE_ATOM);

        $statement = $this->db->prepare('INSERT INTO transactions(type, amount, quantity, description, transaction_date, created_at, source_role) VALUES(:type, :amount, NULL, :description, :transaction_date, :created_at, "owner")');
        $statement->execute([
            ':type' => $type,
            ':amount' => $amount,
            ':description' => $description,
            ':transaction_date' => $transactionDate,
            ':created_at' => $now,
        ]);

        return [
            'status' => 201,
            'data' => ['message' => 'Transaksi owner berhasil disimpan'],
        ];
    }

    /** @param array<string, string> $headers */
    private function ownerReport(array $headers): array
    {
        if ($response = $this->authorize($headers, 'owner')) {
            return $response;
        }

        $saleTotal = (float) $this->singleValue('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = "sale"');
        $incomeTotal = (float) $this->singleValue('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = "income"');
        $expenseTotal = (float) $this->singleValue('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = "expense"');
        $totalStockAdded = $this->totalStockAdded();
        $salesQuantity = (int) $this->singleValue('SELECT COALESCE(SUM(quantity), 0) FROM transactions WHERE type = "sale"');

        return [
            'status' => 200,
            'data' => [
                'summary' => [
                    'stock_current' => $this->currentStock(),
                    'stock_added_total' => $totalStockAdded,
                    'sales_quantity_total' => $salesQuantity,
                    'sales_total' => $saleTotal,
                    'income_total' => $incomeTotal + $saleTotal,
                    'expense_total' => $expenseTotal,
                    'net_total' => ($incomeTotal + $saleTotal) - $expenseTotal,
                ],
            ],
        ];
    }

    /** @param array<string, string> $headers */
    private function ownerDashboard(array $headers): array
    {
        if ($response = $this->authorize($headers, 'owner')) {
            return $response;
        }

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $todaySales = (float) $this->singleValue('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = "sale" AND transaction_date = :date', [':date' => $today]);
        $todayIncome = (float) $this->singleValue('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = "income" AND transaction_date = :date', [':date' => $today]);
        $todayExpense = (float) $this->singleValue('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE type = "expense" AND transaction_date = :date', [':date' => $today]);

        $dates = [];
        for ($index = 6; $index >= 0; $index--) {
            $dates[] = (new DateTimeImmutable('today'))->sub(new DateInterval('P' . $index . 'D'))->format('Y-m-d');
        }

        $graph = [];
        $graphStmt = $this->db->prepare('SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE type = "sale" AND transaction_date = :date');
        foreach ($dates as $date) {
            $graphStmt->execute([':date' => $date]);
            $graph[] = [
                'date' => $date,
                'sales_total' => (float) $graphStmt->fetchColumn(),
            ];
        }

        $currentStock = $this->currentStock();

        return [
            'status' => 200,
            'data' => [
                'cards' => [
                    'current_stock' => $currentStock,
                    'today_sales_total' => $todaySales,
                    'today_income_total' => $todayIncome + $todaySales,
                    'today_expense_total' => $todayExpense,
                    'today_net_total' => ($todayIncome + $todaySales) - $todayExpense,
                    'low_stock_alert' => $currentStock <= 20,
                ],
                'daily_sales_graph' => $graph,
            ],
        ];
    }

    private function currentStock(): int
    {
        $stockIn = (int) $this->singleValue('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements');
        $stockOut = (int) $this->singleValue('SELECT COALESCE(SUM(quantity), 0) FROM transactions WHERE type = "sale"');

        return max(0, $stockIn - $stockOut);
    }

    private function totalStockAdded(): int
    {
        return (int) $this->singleValue('SELECT COALESCE(SUM(quantity), 0) FROM stock_movements');
    }

    /** @param array<string,mixed> $params */
    private function singleValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function normalizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }
}
