<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Region;
use App\Models\Role;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetAllPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Region $region;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['role_name' => 'Admin']);
        $this->actingAs(User::create([
            'full_name' => 'Purchase Test Admin',
            'username' => 'purchase-test-admin',
            'password' => bcrypt('test-password'),
            'active' => 1,
            'user_role_id' => $role->role_id,
        ]));

        $this->category = Category::create([
            'category_code' => 'TEST',
            'category_name' => 'Test Items',
            'category_type' => Category::ITEM_CATEGORY,
        ]);

        $this->region = Region::create([
            'region_code' => 'REG-1',
            'region_name' => 'Test Region',
        ]);

        $this->supplier = Supplier::create([
            'supplier_code' => 'SUP-1',
            'supplier_name' => 'Test Supplier',
            'supplier_address' => 'Test Address',
            'supplier_region_id' => $this->region->region_id,
        ]);
    }

    public function test_reset_all_deletes_all_purchases_without_deleting_or_modifying_stocks(): void
    {
        $stock = Stock::create([
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

        $purchase = Purchase::create([
            'purchase_invoice' => 'INV-001',
            'purchase_supplier_id' => $this->supplier->supplier_id,
            'purchase_region_id' => $this->region->region_id,
        ]);

        PurchaseDetail::create([
            'purchase_detail_purchase_id' => $purchase->purchase_id,
            'purchase_detail_stock_id' => $stock->stock_id,
            'purchase_detail_stock_code' => $stock->stock_code,
            'purchase_detail_stock_name' => $stock->stock_name,
            'purchase_detail_stock_category_id' => $this->category->category_id,
            'purchase_detail_stock_category_name' => $this->category->category_name,
            'purchase_detail_stock_unit' => $stock->stock_unit,
            'purchase_detail_cost_price' => 5000,
            'purchase_detail_price' => 5000,
            'purchase_detail_quantity' => 10,
            'purchase_detail_total_price' => 50000,
        ]);

        $this->deleteJson(route('purchase.reset-all'))
            ->assertOk()
            ->assertContent('true');

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, PurchaseDetail::count());
        $this->assertSame(1, Stock::count());

        $stock->refresh();
        $this->assertSame(25, $stock->stock_total);
        $this->assertSame(30, $stock->stock_in);
        $this->assertSame(5, $stock->stock_out);
    }

    public function test_reset_all_rejects_non_json_requests(): void
    {
        $this->delete(route('purchase.reset-all'))->assertForbidden();
    }
}
