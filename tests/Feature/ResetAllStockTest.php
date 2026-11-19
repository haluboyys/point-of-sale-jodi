<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Stock;
use App\Models\StockLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetAllStockTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

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

        $this->category = Category::create([
            'category_code' => 'TEST',
            'category_name' => 'Test Items',
            'category_type' => Category::ITEM_CATEGORY,
        ]);
    }

    public function test_reset_all_sets_all_stock_quantities_to_zero_without_changing_other_fields(): void
    {
        $first = Stock::create([
            'stock_code' => 'ITEM-1',
            'stock_name' => 'Item 1',
            'stock_category_id' => $this->category->category_id,
            'stock_unit' => 'PCS',
            'stock_purchase_price' => 5000,
            'stock_sale_price_1' => 10000,
            'stock_sale_price_2' => 9500,
            'stock_sale_price_3' => 9000,
            'stock_total' => 25,
            'stock_in' => 30,
            'stock_out' => 5,
        ]);

        $second = Stock::create([
            'stock_code' => 'ITEM-2',
            'stock_name' => 'Item 2',
            'stock_category_id' => $this->category->category_id,
            'stock_unit' => 'PCS',
            'stock_purchase_price' => 7500,
            'stock_sale_price_1' => 12000,
            'stock_sale_price_2' => 11500,
            'stock_sale_price_3' => 11000,
            'stock_total' => 10,
            'stock_in' => 10,
            'stock_out' => 0,
        ]);

        $this->deleteJson(route('stock.reset-all'))
            ->assertOk()
            ->assertContent('true');

        $first->refresh();
        $second->refresh();

        $this->assertSame(0, $first->stock_total);
        $this->assertSame(0, $first->stock_in);
        $this->assertSame(0, $first->stock_out);
        $this->assertSame(5000, $first->stock_purchase_price);

        $this->assertSame(0, $second->stock_total);
        $this->assertSame(0, $second->stock_in);
        $this->assertSame(0, $second->stock_out);
        $this->assertSame(7500, $second->stock_purchase_price);

        $this->assertSame(0, StockLog::count());
    }

    public function test_reset_all_rejects_non_json_requests(): void
    {
        $this->delete(route('stock.reset-all'))->assertForbidden();
    }
}
