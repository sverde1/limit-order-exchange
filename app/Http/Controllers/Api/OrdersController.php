<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdersController extends Controller
{
    /**
     * List open orders for a given symbol.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:16'],
        ]);

        $symbol = strtoupper($validated['symbol']);

        $orders = Order::query()
            ->where('status', Order::STATUS_OPEN)
            ->where('symbol', $symbol)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'symbol' => $symbol,
            'orders' => $orders,
        ]);
    }

    /**
     * Create a new limit order, reserving USD or assets atomically.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        $this->ensureBcMath();

        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:16'],
            'side'   => ['required', 'in:buy,sell'],
            'price'  => ['required', 'decimal:0,18', 'gt:0'],
            'amount' => ['required', 'decimal:0,18', 'gt:0'],
        ]);

        $symbol = strtoupper($validated['symbol']);
        $userId = $request->user()->id;

        $order = DB::transaction(function () use ($validated, $symbol, $userId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($validated['side'] === Order::SIDE_BUY) {
                $totalCost = bcmul($validated['price'], $validated['amount'], 18);
                $newBalance = bcsub($user->balance ?? '0', $totalCost, 2);

                if (bccomp($newBalance, '0', 2) < 0) {
                    throw ValidationException::withMessages([
                        'balance' => 'Insufficient USD balance.',
                    ]);
                }

                $user->balance = $newBalance;
                $user->save();
            } else {
                $asset = Asset::where('user_id', $userId)
                    ->where('symbol', $symbol)
                    ->lockForUpdate()
                    ->first();

                if (! $asset || bccomp($asset->amount, $validated['amount'], 18) < 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Insufficient asset balance.',
                    ]);
                }

                $asset->amount = bcsub($asset->amount, $validated['amount'], 18);
                $asset->locked_amount = bcadd($asset->locked_amount, $validated['amount'], 18);
                $asset->save();
            }

            return Order::create([
                'user_id' => $userId,
                'symbol' => $symbol,
                'side' => $validated['side'],
                'price' => $validated['price'],
                'amount' => $validated['amount'],
                'status' => Order::STATUS_OPEN,
                'created_at' => now(),
            ]);
        });

        return response()->json($order, 201);
    }

    /**
     * Cancel an open order and release reserved funds/assets.
     *
     * @param Request $request
     * @param Order $order
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, Order $order)
    {
        $this->ensureBcMath();
        $userId = $request->user()->id;

        $order = DB::transaction(function () use ($order, $userId) {
            $order = Order::whereKey($order->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== Order::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'status' => 'Order is not open.',
                ]);
            }

            if ($order->side === Order::SIDE_BUY) {
                $user = User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $refund = bcmul($order->price, $order->amount, 18);
                $user->balance = bcadd($user->balance ?? '0', $refund, 2);
                $user->save();
            } else {
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
                $asset->amount = bcadd($asset->amount, $order->amount, 18);
                $asset->save();
            }

            $order->status = Order::STATUS_CANCELED;
            $order->canceled_at = now();
            $order->save();

            return $order;
        });

        return response()->json([
            'message' => 'Order canceled.',
            'order' => $order->fresh(),
        ]);
    }

    /**
     * Ensure BCMath is available for precise decimal operations.
     *
     * @return void
     */
    protected function ensureBcMath(): void
    {
        if (! extension_loaded('bcmath')) {
            abort(500, 'BCMath PHP extension is required to process orders.');
        }
    }
}
