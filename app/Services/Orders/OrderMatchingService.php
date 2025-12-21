<?php

namespace App\Services\Orders;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderMatchingService
{
    /**
     * Attempt to fully match the taker order with the best opposing order.
     *
     * @param  Order  $taker  Taker order to match.
     * @return array{payload: array<string, mixed>, taker_order: Order, maker_order: Order}|null Match payload or null.
     */
    public function attemptMatch(Order $taker): ?array
    {
        $this->ensureBcMath();

        $oppositeSide = $taker->side === Order::SIDE_BUY ? Order::SIDE_SELL : Order::SIDE_BUY;

        $query = Order::query()
            ->where('symbol', $taker->symbol)
            ->where('status', Order::STATUS_OPEN)
            ->where('side', $oppositeSide)
            ->where('amount', $taker->amount);

        if ($taker->side === Order::SIDE_BUY) {
            $query->where('price', '<=', $taker->price)
                ->orderBy('price')
                ->orderBy('created_at');
        } else {
            $query->where('price', '>=', $taker->price)
                ->orderByDesc('price')
                ->orderBy('created_at');
        }

        /** @var Order|null $maker */
        $maker = $query->lockForUpdate()->first();

        if (! $maker) {
            return null;
        }

        if (bccomp($maker->amount, $taker->amount, 18) !== 0) {
            return null;
        }

        if ($taker->side === Order::SIDE_BUY && bccomp($maker->price, $taker->price, 8) === 1) {
            return null;
        }

        if ($taker->side === Order::SIDE_SELL && bccomp($maker->price, $taker->price, 8) === -1) {
            return null;
        }

        $buyerOrder  = $taker->side === Order::SIDE_BUY ? $taker : $maker;
        $sellerOrder = $taker->side === Order::SIDE_SELL ? $taker : $maker;

        $buyer  = User::whereKey($buyerOrder->user_id)->lockForUpdate()->firstOrFail();
        $seller = User::whereKey($sellerOrder->user_id)->lockForUpdate()->firstOrFail();

        $commissionUserId = (int) config('trading.commission_user_id', 1);
        $commissionUser   = User::whereKey($commissionUserId)->lockForUpdate()->first();

        $matchedAt      = now();
        $matchedPrice   = $maker->price;
        $matchedAmount  = $taker->amount;
        $tradeValue     = bcmul($matchedPrice, $matchedAmount, 18);
        $tradeValueCash = $this->formatMoney($tradeValue);

        $commissionRate = (float) config('trading.commission_rate', 0.015);
        $commissionRaw  = bcmul($tradeValue, (string) $commissionRate, 18);
        $commission     = $this->formatMoney($commissionRaw);

        $buyerBidValueRaw = bcmul($buyerOrder->price, $matchedAmount, 18);
        $buyerBidValue    = $this->formatMoney($buyerBidValueRaw);
        $refundRaw        = bcsub($buyerBidValue, $tradeValueCash, 18);
        $refund           = $this->formatMoney($refundRaw);

        if (bccomp($refund, '0', 2) > 0) {
            $buyer->balance = bcadd($buyer->balance ?? '0', $refund, 2);
        }

        $buyer->save();

        $sellerPayoutRaw = bcsub($tradeValueCash, $commission, 18);
        $sellerPayout    = $this->formatMoney($sellerPayoutRaw);
        $seller->balance = bcadd($seller->balance ?? '0', $sellerPayout, 2);
        $seller->save();

        if ($commissionUser) {
            $commissionUser->balance = bcadd($commissionUser->balance ?? '0', $commission, 2);
            $commissionUser->save();
        }

        $symbol = $taker->symbol;

        $sellerAsset = Asset::where('user_id', $seller->id)
            ->where('symbol', $symbol)
            ->lockForUpdate()
            ->first();

        if (! $sellerAsset || bccomp($sellerAsset->locked_amount, $matchedAmount, 18) < 0) {
            throw ValidationException::withMessages([
                'asset' => 'Seller does not have required locked amount.',
            ]);
        }

        $sellerAsset->locked_amount = bcsub($sellerAsset->locked_amount, $matchedAmount, 18);
        $sellerAsset->save();

        $buyerAsset = Asset::where('user_id', $buyer->id)
            ->where('symbol', $symbol)
            ->lockForUpdate()
            ->first();

        if ($buyerAsset) {
            $buyerAsset->amount = bcadd($buyerAsset->amount, $matchedAmount, 18);
            $buyerAsset->save();
        } else {
            Asset::create([
                'user_id' => $buyer->id,
                'symbol' => $symbol,
                'amount' => $matchedAmount,
                'locked_amount' => '0',
            ]);
        }

        $buyerOrder->status     = Order::STATUS_FILLED;
        $buyerOrder->updated_at = now();
        $buyerOrder->save();

        $sellerOrder->status     = Order::STATUS_FILLED;
        $sellerOrder->updated_at = now();
        $sellerOrder->save();

        DB::table('trades')->insert([
            'buy_order_id'  => $buyerOrder->id,
            'sell_order_id' => $sellerOrder->id,
            'symbol'        => $symbol,
            'price'         => $matchedPrice,
            'amount'        => $matchedAmount,
            'commission'    => $commission,
            'matched_at'    => $matchedAt->toDateTimeString(),
        ]);

        return [
            'payload' => [
                'symbol'        => $symbol,
                'price'         => $matchedPrice,
                'amount'        => $matchedAmount,
                'matched_at'    => $matchedAt->toIso8601String(),
                'taker_side'    => $taker->side,
                'buy_order_id'  => $buyerOrder->id,
                'sell_order_id' => $sellerOrder->id,
                'buyer_id'      => $buyer->id,
                'seller_id'     => $seller->id,
                'buy_status'    => $buyerOrder->status,
                'sell_status'   => $sellerOrder->status,
            ],
            'taker_order' => $taker->fresh(),
            'maker_order' => $maker->fresh(),
        ];
    }

    /**
     * Ensure BCMath is available for precise decimal operations.
     *
     * @return void Validates the BCMath extension.
     */
    private function ensureBcMath(): void
    {
        if (! extension_loaded('bcmath')) {
            abort(500, 'BCMath PHP extension is required to process orders.');
        }
    }

    /**
     * Format monetary values to 2 decimal places with rounding.
     *
     * @param  string  $value  Raw numeric string.
     * @return string Formatted money string.
     */
    private function formatMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
