<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaleDetailRequest;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Stock;
use App\Models\StockLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SaleDetailController
{
    public function update(SaleDetailRequest $request, SaleDetail $saleDetail): JsonResponse
    {
        return DB::transaction(function () use ($request, $saleDetail) {
            $sale = Sale::whereKey($saleDetail->sale_detail_sales_id)->lockForUpdate()->firstOrFail();
            abort_if($sale->sales_status == Sale::CANCEL_STATUS, 409, 'Penjualan sudah dibatalkan.');

            $saleDetail = $sale->details()->whereKey($saleDetail->getKey())->lockForUpdate()->firstOrFail();
            $newQuantity = $request->validated()['quantity'];
            $oldQuantity = $saleDetail->sale_detail_quantity;
            $user = Auth::user();

            $saleDetail->update([
                'sale_detail_quantity' => $newQuantity,
                'sale_detail_total_price' => $saleDetail->sale_detail_price * $newQuantity,
            ]);

            $stock = Stock::whereKey($saleDetail->sale_detail_stock_id)->lockForUpdate()->firstOrFail();

            if ($newQuantity < $oldQuantity) {
                $difference = $oldQuantity - $newQuantity;

                $stock->update([
                    'stock_total' => $stock->stock_total + $difference,
                    'stock_out' => $stock->stock_out - $difference,
                ]);

                StockLog::create([
                    'stock_log_stock_id' => $stock->stock_id,
                    'stock_log_quantity' => $difference,
                    'stock_log_description' => 'Penyesuaian karena pengurangan quantity penjualan',
                    'stock_log_status' => StockLog::IN_STATUS,
                    'stock_log_user_id' => $user->user_id,
                ]);
            } elseif ($newQuantity > $oldQuantity) {
                $difference = $newQuantity - $oldQuantity;

                $stock->update([
                    'stock_out' => $stock->stock_out + $difference,
                    'stock_total' => $stock->stock_total - $difference,
                ]);

                StockLog::create([
                    'stock_log_stock_id' => $stock->stock_id,
                    'stock_log_quantity' => $difference,
                    'stock_log_description' => 'Penyesuaian karena penambahan quantity penjualan',
                    'stock_log_status' => StockLog::OUT_STATUS,
                    'stock_log_user_id' => $user->user_id,
                ]);
            }

            $saleDetails = $sale->details()->get();

            $totalGross = 0;
            $totalPrice = 0;

            foreach ($saleDetails as $detail) {
                $quantity = $detail->sale_detail_quantity;
                $price = floatval($detail->sale_detail_price);
                $discount = intval($detail->sale_detail_discount);

                $subtotal = $price * $quantity;
                $discountAmount = $subtotal * ($discount / 100);

                $totalGross += $subtotal;
                $totalPrice += $subtotal - $discountAmount;
            }

            $sale->update([
                'sales_total_price' => $totalPrice,
                'sales_total_gross' => $totalGross,
                'sales_total_payment' => $totalPrice,
                'sales_total_change' => 0,
                'sales_status' => Sale::PAID_STATUS,
            ]);

            return response()->json(true);
        });
    }

    public function destroy(SaleDetail $saleDetail): JsonResponse
    {
        abort_unless(request()->expectsJson(), 403);

        return DB::transaction(function () use ($saleDetail) {
            $sale = Sale::whereKey($saleDetail->sale_detail_sales_id)->lockForUpdate()->firstOrFail();
            abort_if($sale->sales_status == Sale::CANCEL_STATUS, 409, 'Penjualan sudah dibatalkan.');

            $saleDetail = $sale->details()->whereKey($saleDetail->getKey())->lockForUpdate()->firstOrFail();
            $stock = Stock::whereKey($saleDetail->sale_detail_stock_id)->lockForUpdate()->firstOrFail();
            $user = Auth::user();

            $stock->update([
                'stock_out' => $stock->stock_out - $saleDetail->sale_detail_quantity,
                'stock_total' => $stock->stock_total + $saleDetail->sale_detail_quantity,
            ]);

            StockLog::create([
                'stock_log_stock_id' => $stock->stock_id,
                'stock_log_quantity' => $saleDetail->sale_detail_quantity,
                'stock_log_description' => 'Penyesuaian karena pengurangan quantity penjualan',
                'stock_log_status' => StockLog::IN_STATUS,
                'stock_log_user_id' => $user->user_id,
            ]);

            $saleDetail->delete();

            $remaining = $sale->details()->count();

            if ($remaining === 0) {
                $sale->update([
                    'sales_status' => Sale::CANCEL_STATUS,
                ]);
            }

            return response()->json(true);
        });
    }
}
