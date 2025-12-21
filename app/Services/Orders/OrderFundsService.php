<?php

namespace App\Services\Orders;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrderFundsService
{
    /**
     * Reserve USD or assets for a new order.
     *
     * @param  User  $user  User placing the order.
     * @param  array{side: string, price: string, amount: string}  $validated  Validated order data.
     * @param  string  $symbol  Asset symbol being traded.
     * @return void Reserves funds or assets.
     */
    public function reserveForOrder(User $user, array $validated, string $symbol): void
    {
        $this->ensureBcMath();

        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

        if ($validated['side'] === Order::SIDE_BUY) {
            $totalCost  = bcmul($validated['price'], $validated['amount'], 18);
            $newBalance = bcsub($user->balance ?? '0', $totalCost, 2);

            if (bccomp($newBalance, '0', 2) < 0) {
                throw ValidationException::withMessages([
                    'balance' => 'User has insufficient USD balance.',
                ]);
            }

            $user->balance = $newBalance;
            $user->save();

            return;
        }

        $asset = Asset::where('user_id', $user->id)
            ->where('symbol', $symbol)
            ->lockForUpdate()
            ->first();

        if (! $asset || bccomp($asset->amount, $validated['amount'], 18) < 0) {
            throw ValidationException::withMessages([
                'balance' => 'User has insufficient asset balance.',
            ]);
        }

        $asset->amount        = bcsub($asset->amount, $validated['amount'], 18);
        $asset->locked_amount = bcadd($asset->locked_amount, $validated['amount'], 18);
        $asset->save();
    }

    /**
     * Release reserved funds or assets for a canceled order.
     *
     * @param  Order  $order  Order being canceled.
     * @return void Releases reserved funds or assets.
     */
    public function releaseForCancellation(Order $order): void
    {
        $this->ensureBcMath();

        if ($order->side === Order::SIDE_BUY) {
            $user          = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $refund        = bcmul($order->price, $order->amount, 18);
            $user->balance = bcadd($user->balance ?? '0', $refund, 2);
            $user->save();

            return;
        }

        $asset = Asset::where('user_id', $order->user_id)
            ->where('symbol', $order->symbol)
            ->lockForUpdate()
            ->first();

        if (! $asset || bccomp($asset->locked_amount, $order->amount, 18) < 0) {
            throw ValidationException::withMessages([
                'locked_amount' => 'Unable to release reserved assets.',
            ]);
        }

        $asset->locked_amount = bcsub($asset->locked_amount, $order->amount, 18);
        $asset->amount        = bcadd($asset->amount, $order->amount, 18);
        $asset->save();
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
}
