<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * Return authenticated user profile with USD balance and asset balances.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        $user = $request->user()->load([
            'assets:id,user_id,symbol,amount,locked_amount',
        ]);

        $lockedBalance = Order::query()
            ->where('user_id', $user->id)
            ->where('side', Order::SIDE_BUY)
            ->where('status', Order::STATUS_OPEN)
            ->get()
            ->reduce(function ($carry, $order) {
                $total = bcmul($order->price, $order->amount, 18);
                return bcadd($carry, $total, 2);
            }, '0.00');

        return response()->json([
            'user' => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'balance'        => $user->balance,
                'balance_locked' => $lockedBalance,
            ],
            'assets' => $user->assets,
        ]);
    }

    /**
     * Reset the authenticated user wallet balance to $10k.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addDemoAssets(Request $request)
    {
        if (! extension_loaded('bcmath')) {
            abort(500, 'BCMath PHP extension is required to process funds.');
        }

        $validated = $request->validate([
            'balance' => ['nullable', 'numeric', 'min:0'],
            'symbol'  => ['nullable', 'string', 'max:16'],
            'amount'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $validated) {
            if (isset($validated['balance']) && $validated['balance'] > 0) {
                $current       = $user->balance ?? 0;
                $user->balance = bcadd((string) $current, (string) $validated['balance'], 2);
                $user->save();
            }

            if (
                isset($validated['symbol'], $validated['amount']) &&
                $validated['symbol'] &&
                $validated['amount'] > 0
            ) {
                $symbol = strtoupper($validated['symbol']);
                $asset = Asset::firstOrNew([
                    'user_id' => $user->id,
                    'symbol'  => $symbol,
                ]);

                $asset->amount = bcadd((string) ($asset->amount ?? 0), (string) $validated['amount'], 18);
                $asset->locked_amount = $asset->locked_amount ?? 0;
                $asset->save();
            }
        });

        return response()->json([
            'message' => 'Demo balances updated.',
            'balance' => $user->balance,
            'assets'  => $user->fresh()->assets,
        ]);
    }

    /**
     * Return the authenticated user's orders with optional filters.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function orders(Request $request)
    {
        $validated = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:16'],
            'status' => ['sometimes', 'in:open,filled,canceled,all'],
        ]);

        $symbol = isset($validated['symbol']) ? strtoupper($validated['symbol']) : null;
        $status = $validated['status'] ?? 'all';

        $statusMap = [
            'open'     => Order::STATUS_OPEN,
            'filled'   => Order::STATUS_FILLED,
            'canceled' => Order::STATUS_CANCELED,
        ];

        $ordersQuery = $request->user()
            ->orders()
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->when($status !== 'all', fn ($query) => $query->where('status', $statusMap[$status]))
            ->orderByDesc('created_at');

        return response()->json([
            'orders' => $ordersQuery->get(),
        ]);
    }
}
