<?php

declare(strict_types=1);

use App\Database;
use App\PosApi;
use PHPUnit\Framework\TestCase;

final class PosApiTest extends TestCase
{
    private PosApi $api;

    protected function setUp(): void
    {
        $databasePath = sys_get_temp_dir() . '/pos-api-test-' . uniqid('', true) . '.sqlite';
        $this->api = new PosApi(Database::connect($databasePath));
    }

    public function testOwnerCanAddStockAndSeeHistory(): void
    {
        $create = $this->api->handle('POST', '/api/owner/stocks', ['X-Role' => 'owner'], [
            'quantity' => 40,
            'stock_date' => '2026-10-16',
            'note' => 'Restok pagi',
        ]);

        $this->assertSame(201, $create['status']);
        $this->assertSame(40, $create['data']['current_stock']);

        $history = $this->api->handle('GET', '/api/owner/stocks/history', ['X-Role' => 'owner']);
        $this->assertSame(200, $history['status']);
        $this->assertCount(1, $history['data']['history']);
        $this->assertSame(40, $history['data']['total_added_stock']);
    }

    public function testEmployeePosSaleReducesStock(): void
    {
        $this->api->handle('POST', '/api/owner/stocks', ['X-Role' => 'owner'], [
            'quantity' => 50,
            'stock_date' => '2026-10-16',
        ]);

        $sale = $this->api->handle('POST', '/api/employee/pos/sales', ['X-Role' => 'employee'], [
            'quantity' => 5,
            'price_per_item' => 3000,
            'transaction_date' => '2026-10-16',
        ]);

        $this->assertSame(201, $sale['status']);
        $this->assertSame(45, $sale['data']['current_stock']);
        $this->assertSame(15000.0, $sale['data']['sale_amount']);
    }

    public function testOwnerDashboardHasImportantCardsAndDailyGraph(): void
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        $this->api->handle('POST', '/api/owner/stocks', ['X-Role' => 'owner'], [
            'quantity' => 25,
            'stock_date' => $today,
        ]);

        $this->api->handle('POST', '/api/employee/pos/sales', ['X-Role' => 'employee'], [
            'quantity' => 2,
            'price_per_item' => 3000,
            'transaction_date' => $today,
        ]);

        $dashboard = $this->api->handle('GET', '/api/owner/dashboard', ['X-Role' => 'owner']);

        $this->assertSame(200, $dashboard['status']);
        $this->assertArrayHasKey('cards', $dashboard['data']);
        $this->assertArrayHasKey('daily_sales_graph', $dashboard['data']);
        $this->assertCount(7, $dashboard['data']['daily_sales_graph']);
        $this->assertSame(23, $dashboard['data']['cards']['current_stock']);
        $this->assertSame(6000.0, $dashboard['data']['cards']['today_sales_total']);
    }
}
