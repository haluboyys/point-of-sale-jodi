<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\Stock;
use App\Models\StockLog;
use App\Models\StockTaken;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockTakenTest extends TestCase
{
    use RefreshDatabase;

    private Stock $stock;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $role = Role::create(['role_name' => 'Admin']);
        $this->user = User::create([
            'full_name' => 'Stock Taken Test Admin',
            'username' => 'stock-taken-admin',
            'password' => bcrypt('test-password'),
            'active' => 1,
            'user_role_id' => $role->role_id,
        ]);
        $this->actingAs($this->user)->withSession(['user' => $this->user]);

        $category = Category::create([
            'category_code' => 'TEST',
            'category_name' => 'Test Items',
            'category_type' => Category::ITEM_CATEGORY,
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

    public function test_page_renders_working_stock_and_report_forms(): void
    {
        $this->withoutExceptionHandling();
        $this->get(route('stock.taken'))
            ->assertOk()
            ->assertViewHas('stocks')
            ->assertSee('action="'.route('stock.taken.store').'"', false)
            ->assertSee('name="stock_code"', false)
            ->assertSee('value="TEST-ITEM"', false)
            ->assertSee('name="quantity"', false)
            ->assertSee('name="description"', false)
            ->assertSee('name="stock_category"', false)
            ->assertSee('#form-taken', false)
            ->assertSee('#form-taken-report', false);
    }

    public function test_taking_stock_without_a_description_records_the_withdrawal_and_outgoing_log(): void
    {
        $this->post('/stock/taken', ['stock_code' => $this->stock->stock_code, 'quantity' => 3])
            ->assertRedirect(route('stock.taken'));

        $this->assertEquals(17, $this->stock->fresh()->stock_total);
        $this->assertEquals(3, $this->stock->fresh()->stock_out);
        $this->assertEquals(20, $this->stock->fresh()->stock_in);
        $this->assertDatabaseHas('tbl_stock_takens', [
            'stock_taken_stock_id' => $this->stock->stock_id,
            'stock_taken_stock_code' => $this->stock->stock_code,
            'stock_taken_quantity' => 3,
            'stock_taken_price' => 50,
            'stock_taken_description' => null,
            'stock_taken_user_id' => $this->user->user_id,
        ]);
        $this->assertDatabaseHas('tbl_stock_logs', [
            'stock_log_stock_id' => $this->stock->stock_id,
            'stock_log_quantity' => 3,
            'stock_log_status' => StockLog::OUT_STATUS,
        ]);
    }

    #[DataProvider('unavailableStock')]
    public function test_taking_more_than_available_stock_is_rejected_without_creating_history(int $available, int $quantity): void
    {
        $this->stock->update(['stock_total' => $available]);
        $this->postJson('/stock/taken', [
            'stock_code' => $this->stock->stock_code,
            'quantity' => $quantity,
            'description' => 'Test withdrawal',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertEquals($available, $this->stock->fresh()->stock_total);
        $this->assertDatabaseCount('tbl_stock_takens', 0);
        $this->assertDatabaseCount('tbl_stock_logs', 0);
    }

    public static function unavailableStock(): array
    {
        return [[20, 21], [0, 1], [-5, 1]];
    }

    public function test_taking_all_remaining_stock_succeeds(): void
    {
        $this->post(route('stock.taken.store'), ['stock_code' => $this->stock->stock_code, 'quantity' => 20])
            ->assertRedirect(route('stock.taken'));

        $this->assertEquals(0, $this->stock->fresh()->stock_total);
        $this->assertEquals(20, $this->stock->fresh()->stock_out);
        $this->assertDatabaseCount('tbl_stock_takens', 1);
        $this->assertDatabaseCount('tbl_stock_logs', 1);
    }

    public function test_unavailable_stock_error_returns_to_the_form_with_the_submitted_values(): void
    {
        $this->from(route('stock.taken'))->post(route('stock.taken.store'), [
            'stock_code' => $this->stock->stock_code,
            'quantity' => 21,
        ])->assertRedirect(route('stock.taken'))->assertSessionHasErrors('quantity');

        $this->get(route('stock.taken'))
            ->assertOk()
            ->assertSee('Jumlah pengambilan melebihi stok yang tersedia.')
            ->assertSee('value="21"', false)
            ->assertSee("openModal('#modal-taken')", false);
    }

    public function test_failed_log_creation_rolls_back_stock_and_withdrawal_history(): void
    {
        $this->withoutExceptionHandling();
        StockLog::creating(function () {
            throw new \RuntimeException('Simulated stock log failure');
        });

        try {
            $this->post('/stock/taken', [
                'stock_code' => $this->stock->stock_code,
                'quantity' => 3,
                'description' => 'Test withdrawal',
            ]);
            $this->fail('Expected log creation to fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated stock log failure', $exception->getMessage());
        } finally {
            StockLog::flushEventListeners();
        }

        $this->assertEquals(20, $this->stock->fresh()->stock_total);
        $this->assertEquals(0, $this->stock->fresh()->stock_out);
        $this->assertDatabaseCount('tbl_stock_takens', 0);
        $this->assertDatabaseCount('tbl_stock_logs', 0);
    }

    public function test_all_category_listing_returns_consistent_quantity_and_price_totals(): void
    {
        $this->recordWithdrawal(3);

        $this->getJson(route('stock.taken', ['category_id' => 'all']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('total_stock_all', 3)
            ->assertJsonPath('total_stock_purchase_price', 150)
            ->assertJsonPath('data.0.stock.stock_code', $this->stock->stock_code);
    }

    public function test_listing_defaults_to_all_categories_and_returns_zero_for_empty_totals(): void
    {
        $this->getJson(route('stock.taken'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0)
            ->assertJsonPath('total_stock_all', 0)
            ->assertJsonPath('total_stock_purchase_price', 0);

        $this->recordWithdrawal(3);
        $this->getJson(route('stock.taken'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('total_stock_all', 3)
            ->assertJsonPath('total_stock_purchase_price', 150);
    }

    public function test_listing_totals_are_limited_to_the_selected_category(): void
    {
        $this->recordWithdrawal(3);
        $otherCategory = Category::create([
            'category_code' => 'OTHER',
            'category_name' => 'Other Items',
            'category_type' => Category::ITEM_CATEGORY,
        ]);
        $this->recordWithdrawal(4)->update(['stock_taken_category_id' => $otherCategory->category_id]);

        $this->getJson(route('stock.taken', ['category_id' => $this->stock->stock_category_id]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('total_stock_all', 3)
            ->assertJsonPath('total_stock_purchase_price', 150);
    }

    public function test_pdf_report_downloads_with_the_category_and_dates_from_the_form(): void
    {
        $this->recordWithdrawal(3);
        $this->post(route('stock.taken.report'), [
            'stock_category' => 'all',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertOk()
            ->assertDownload('LAPORAN-PENGAMBILAN-STOK.pdf')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_report_filters_category_and_includes_the_whole_date_range(): void
    {
        $first = $this->recordWithdrawal(3);
        $first->forceFill(['created_at' => now()->startOfDay()])->save();
        $last = $this->recordWithdrawal(2);
        $last->forceFill(['created_at' => now()->endOfDay()->startOfSecond()])->save();
        $otherDate = $this->recordWithdrawal(1);
        $otherDate->forceFill(['created_at' => now()->addDay()->startOfDay()])->save();
        $otherCategory = Category::create([
            'category_code' => 'OTHER',
            'category_name' => 'Other Items',
            'category_type' => Category::ITEM_CATEGORY,
        ]);
        $other = $this->recordWithdrawal(4);
        $other->update(['stock_taken_category_id' => $otherCategory->category_id]);
        $expectedIds = [$first->getKey(), $last->getKey()];
        $reportData = null;

        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $variables) use (&$reportData) {
            $reportData = $variables['data'];

            return $view === 'stock.taken.report';
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        Pdf::shouldReceive('download')->once()->with('LAPORAN-PENGAMBILAN-STOK.pdf')->andReturn(response('%PDF-test', 200));

        $this->post(route('stock.taken.report'), [
            'stock_category' => $this->stock->stock_category_id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertOk();
        $this->assertSame($expectedIds, $reportData->flatten()->pluck('stock_taken_id')->all());
    }

    public function test_report_still_downloads_when_the_recorded_user_was_deleted(): void
    {
        $formerUser = User::create([
            'full_name' => 'Former Stock User',
            'username' => 'former-stock-user',
            'password' => bcrypt('test-password'),
            'active' => 1,
            'user_role_id' => $this->user->user_role_id,
        ]);
        $this->recordWithdrawal(3)->update(['stock_taken_user_id' => $formerUser->user_id]);
        $formerUser->delete();

        $this->post(route('stock.taken.report'), [
            'stock_category' => 'all',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertOk()->assertDownload('LAPORAN-PENGAMBILAN-STOK.pdf');
    }

    public function test_report_rejects_invalid_categories_and_dates(): void
    {
        $this->postJson(route('stock.taken.report'), [
            'stock_category' => 999,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('stock_category');

        $this->postJson(route('stock.taken.report'), [
            'stock_category' => 'all',
            'start_date' => now()->toDateString(),
            'end_date' => 'invalid-date',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');

        $this->postJson(route('stock.taken.report'), [
            'stock_category' => 'all',
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_report_downloads_when_the_selected_period_has_no_withdrawals(): void
    {
        $this->post(route('stock.taken.report'), [
            'stock_category' => 'all',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertOk()->assertDownload('LAPORAN-PENGAMBILAN-STOK.pdf');
    }

    private function recordWithdrawal(int $quantity): StockTaken
    {
        return StockTaken::create([
            'stock_taken_stock_id' => $this->stock->stock_id,
            'stock_taken_stock_code' => $this->stock->stock_code,
            'stock_taken_stock_name' => $this->stock->stock_name,
            'stock_taken_quantity' => $quantity,
            'stock_taken_price' => $this->stock->stock_purchase_price,
            'stock_taken_description' => 'Test withdrawal',
            'stock_taken_category_id' => $this->stock->stock_category_id,
            'stock_taken_user_id' => $this->user->user_id,
        ]);
    }
}
