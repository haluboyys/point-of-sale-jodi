<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Region;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Stock;
use App\Models\StockLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaleStockTest extends TestCase
{
    use RefreshDatabase;

    private Stock $stock;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['role_name' => 'Admin']);
        $this->actingAs(User::create([
            'full_name' => 'Stock Test Admin',
            'username' => 'stock-test-admin',
            'password' => bcrypt('test-password'),
            'active' => 1,
            'user_role_id' => $role->role_id,
        ]));

        $category = Category::create([
            'category_code' => 'TEST',
            'category_name' => 'Test Items',
            'category_type' => Category::ITEM_CATEGORY,
        ]);
        $region = Region::create(['region_code' => 'TEST', 'region_name' => 'Test Region']);
        $this->customer = Customer::create([
            'customer_category_id' => $category->category_id,
            'customer_name' => 'Test Customer',
            'customer_region_id' => $region->region_id,
        ]);
        $this->stock = Stock::create([
            'stock_code' => 'TEST-ITEM',
            'stock_name' => 'Test Item',
            'stock_category_id' => $category->category_id,
            'stock_unit' => 'PCS',
            'stock_purchase_price' => 50,
            'stock_sale_price_1' => 100,
            'stock_sale_price_2' => 100,
            'stock_sale_price_3' => 100,
            'stock_total' => 20,
            'stock_in' => 20,
            'stock_out' => 0,
        ]);
    }

    public function test_deleting_an_item_returns_its_stock_and_logs_an_incoming_movement(): void
    {
        $sale = $this->checkoutSale([3, 2]);
        $detail = $sale->details()->firstOrFail();
        $this->assertStock(15, 5);

        $this->deleteJson(route('sale-detail.destroy', $detail))->assertOk()->assertContent('true');

        $this->assertStock(18, 2);
        $this->assertSoftDeleted($detail);
        $this->assertEquals(Sale::PAID_STATUS, $sale->fresh()->sales_status);
        $this->assertIncomingLog(3);
    }

    public function test_deleting_the_last_item_returns_stock_and_cancels_the_sale(): void
    {
        $sale = $this->checkoutSale();
        $detail = $sale->details()->firstOrFail();

        $this->deleteJson(route('sale-detail.destroy', $detail))->assertOk();

        $this->assertStock(20, 0);
        $this->assertSoftDeleted($detail);
        $this->assertEquals(Sale::CANCEL_STATUS, $sale->fresh()->sales_status);
        $this->assertIncomingLog(3);

        $this->deleteJson(route('sale-detail.destroy', $detail))->assertNotFound();
        $this->assertStock(20, 0);
        $this->assertEquals(2, StockLog::count());
    }

    public function test_cancelling_a_sale_restores_all_lines_including_repeated_stock(): void
    {
        $sale = $this->checkoutSale([3, 2]);
        $this->assertStock(15, 5);

        $this->deleteJson(route('sale.destroy', $sale))->assertOk()->assertContent('true');

        $this->assertStock(20, 0);
        $this->assertEquals(Sale::CANCEL_STATUS, $sale->fresh()->sales_status);
        $this->assertIncomingLog(3);
        $this->assertIncomingLog(2);
    }

    public function test_cancelling_after_an_item_is_deleted_only_returns_the_remaining_stock(): void
    {
        $sale = $this->checkoutSale([3, 2]);
        $this->deleteJson(route('sale-detail.destroy', $sale->details()->firstOrFail()))->assertOk();

        $this->deleteJson(route('sale.destroy', $sale))->assertOk();

        $this->assertStock(20, 0);
        $this->assertEquals(2, StockLog::where('stock_log_status', StockLog::IN_STATUS)->count());
    }

    public function test_cancelling_twice_does_not_return_stock_twice(): void
    {
        $sale = $this->checkoutSale();

        $this->deleteJson(route('sale.destroy', $sale))->assertOk();
        $this->deleteJson(route('sale.destroy', $sale))->assertOk();

        $this->assertStock(20, 0);
        $this->assertEquals(1, StockLog::where('stock_log_status', StockLog::IN_STATUS)->count());
    }

    #[DataProvider('quantityChanges')]
    public function test_quantity_changes_apply_only_the_stock_difference(int $quantity, ?int $logStatus): void
    {
        $sale = $this->checkoutSale();
        $detail = $sale->details()->firstOrFail();

        $this->putJson(route('sale-detail.update', $detail), ['quantity' => $quantity])->assertOk();

        $this->assertStock(20 - $quantity, $quantity);
        $this->assertEquals($quantity, $detail->fresh()->sale_detail_quantity);
        if ($logStatus !== null) {
            $this->assertDatabaseHas('tbl_stock_logs', [
                'stock_log_stock_id' => $this->stock->stock_id,
                'stock_log_quantity' => abs(3 - $quantity),
                'stock_log_status' => $logStatus,
            ]);
        }
        $this->assertEquals($quantity === 3 ? 1 : 2, StockLog::count());

        $this->deleteJson(route('sale.destroy', $sale))->assertOk();
        $this->assertStock(20, 0);
        $this->assertIncomingLog($quantity);
    }

    public static function quantityChanges(): array
    {
        return [
            'reduce' => [2, StockLog::IN_STATUS],
            'increase' => [5, StockLog::OUT_STATUS],
            'unchanged' => [3, null],
        ];
    }

    public function test_cancelled_sale_items_cannot_change_stock_again(): void
    {
        $sale = $this->checkoutSale();
        $detail = $sale->details()->firstOrFail();
        $this->deleteJson(route('sale.destroy', $sale))->assertOk();

        $this->deleteJson(route('sale-detail.destroy', $detail))->assertStatus(409);
        $this->putJson(route('sale-detail.update', $detail), ['quantity' => 2])->assertStatus(409);

        $this->assertStock(20, 0);
        $this->assertNotSoftDeleted($detail);
        $this->assertEquals(3, $detail->fresh()->sale_detail_quantity);
        $this->assertEquals(Sale::CANCEL_STATUS, $sale->fresh()->sales_status);
        $this->assertEquals(2, StockLog::count());
    }

    public function test_failed_item_deletion_rolls_back_stock_and_log(): void
    {
        $sale = $this->checkoutSale();
        $detail = $sale->details()->firstOrFail();
        $this->withoutExceptionHandling();
        SaleDetail::deleting(function () {
            throw new \RuntimeException('Simulated deletion failure');
        });

        try {
            $this->deleteJson(route('sale-detail.destroy', $detail));
            $this->fail('Expected deletion to fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated deletion failure', $exception->getMessage());
        } finally {
            SaleDetail::flushEventListeners();
        }

        $this->assertStock(17, 3);
        $this->assertNotSoftDeleted($detail);
        $this->assertEquals(Sale::PAID_STATUS, $sale->fresh()->sales_status);
        $this->assertEquals(1, StockLog::count());
    }

    public function test_failed_sale_cancellation_rolls_back_stock_and_log(): void
    {
        $sale = $this->checkoutSale([3, 2]);
        $this->withoutExceptionHandling();
        Sale::updating(function () {
            throw new \RuntimeException('Simulated cancellation failure');
        });

        try {
            $this->deleteJson(route('sale.destroy', $sale));
            $this->fail('Expected cancellation to fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated cancellation failure', $exception->getMessage());
        } finally {
            Sale::flushEventListeners();
        }

        $this->assertStock(15, 5);
        $this->assertEquals(Sale::PAID_STATUS, $sale->fresh()->sales_status);
        $this->assertEquals(2, StockLog::count());
    }

    private function checkoutSale(array $quantities = [3]): Sale
    {
        $response = $this->postJson(route('cashier.checkout'), [
            'customerId' => $this->customer->customer_id,
            'customerPay' => array_sum($quantities) * 100,
            'customerDebt' => 0,
            'items' => array_map(fn (int $quantity) => [
                'id' => $this->stock->stock_id,
                'quantity' => $quantity,
                'price' => 100,
                'discount' => 0,
            ], $quantities),
        ])->assertOk()->assertJsonPath('success', true);

        return Sale::findOrFail($response->json('id'));
    }

    private function assertStock(int $total, int $out): void
    {
        $this->stock->refresh();
        $this->assertEquals($total, $this->stock->stock_total);
        $this->assertEquals($out, $this->stock->stock_out);
        $this->assertEquals(20, $this->stock->stock_in);
    }

    private function assertIncomingLog(int $quantity): void
    {
        $this->assertDatabaseHas('tbl_stock_logs', [
            'stock_log_stock_id' => $this->stock->stock_id,
            'stock_log_quantity' => $quantity,
            'stock_log_status' => StockLog::IN_STATUS,
        ]);
    }
}
