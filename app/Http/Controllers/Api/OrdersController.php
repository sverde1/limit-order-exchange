<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderBookUpdated;
use App\Events\OrderMatched;
use App\Events\UserBalancesUpdated;
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
     * Commission rate applied to buyer on successful match.
     *
     * @var float
     */
    private float $commissionRate = 0.015;

    /**
     * Admin user ID to receive commission.
     *
     * @var int
     */
    private int $adminUserId = 1;

    /**
     * Pending events to dispatch after commit.
     *
     * @var array<int, array{type: string, payload: mixed}>
     */
    private array $pendingEvents = [];

    /**
     * List open or all orders for a given symbol.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            // Orderbook for selected symbol
            'symbol' => ['required', 'string', 'max:16'],
        ]);

        $symbol = isset($validated['symbol']) ? strtoupper($validated['symbol']) : null;

        $orders = Order::query()
            ->select('id', 'symbol', 'side', 'price', 'amount')
            ->where('symbol', $symbol)
            ->where('status', Order::STATUS_OPEN)
            ->orderByDesc('created_at')
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
        $this->pendingEvents = [];

        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:16'],
            'side'   => ['required', 'in:buy,sell'],
            'price'  => ['required', 'decimal:0,8', 'gt:0'],
            'amount' => ['required', 'decimal:0,18', 'gt:0'],
        ]);

        $symbol = isset($validated['symbol']) ? strtoupper($validated['symbol']) : null;
        $userId = $request->user()->id;

        $order = DB::transaction(function () use ($validated, $symbol, $userId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();

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
            } else {
                $asset = Asset::where('user_id', $userId)
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

            $order = Order::create([
                'user_id'    => $userId,
                'symbol'     => $symbol,
                'side'       => $validated['side'],
                'price'      => $validated['price'],
                'amount'     => $validated['amount'],
                'status'     => Order::STATUS_OPEN,
                'created_at' => now(),
            ]);

            $matchResult = $this->attemptMatch($order);
            if ($matchResult) {
                $order = $matchResult['taker_order'];

                // dispatch events
                // first dispatch OrderMatched event to show recent matched orders
                $this->queueMatchedOrder($matchResult['payload']);

                // then dispatch OrderBookUpdated event to update order book entries
                $this->queueBookEvent($matchResult['taker_order'], 'filled');
                $this->queueBookEvent($matchResult['maker_order'], 'filled');

                // finally dispatch UserBalancesUpdated event to update user balances
                $this->queueBalancesUpdate($matchResult['taker_order']->user_id);
                $this->queueBalancesUpdate($matchResult['maker_order']->user_id);
            } else {
                // dispatch OrderBookUpdated event to update order book entries
                $this->queueBookEvent($order, 'open');

                // finally dispatch UserBalancesUpdated event to update user balances
                $this->queueBalancesUpdate($order->user_id);
            }

            return $order;
        });

        $this->dispatchEvents();

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
        $this->pendingEvents = [];
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
            $order->updated_at = now();
            $order->save();

            return $order;
        });

        $this->queueBookEvent($order, 'canceled');
        $this->queueBalancesUpdate($order->user_id);
        $this->dispatchEvents();

        return response()->json([
            'message' => 'Order canceled.',
            'order'   => $order->fresh(),
        ]);
    }

    /**
     * Attempt to fully match an order against the best opposing order.
     *
     * @param Order $taker
     * @return array<string, mixed>|null
     */
    protected function attemptMatch(Order $taker): ?array
    {
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

        // Full match only: amounts must be identical.
        if (bccomp($maker->amount, $taker->amount, 18) !== 0) {
            return null;
        }

        // Price guard (defensive).
        if ($taker->side === Order::SIDE_BUY && bccomp($maker->price, $taker->price, 8) === 1) {
            return null;
        }
        if ($taker->side === Order::SIDE_SELL && bccomp($maker->price, $taker->price, 8) === -1) {
            return null;
        }

        $buyerOrder  = $taker->side === Order::SIDE_BUY ? $taker : $maker;
        $sellerOrder = $taker->side === Order::SIDE_SELL ? $taker : $maker;

        $buyer = User::whereKey($buyerOrder->user_id)->lockForUpdate()->firstOrFail();
        $seller = User::whereKey($sellerOrder->user_id)->lockForUpdate()->firstOrFail();
        $admin = User::whereKey($this->adminUserId)->lockForUpdate()->first();

        $matchedAt      = now();
        $matchedPrice   = $maker->price; // trade executes at maker price
        $matchedAmount  = $taker->amount;
        $tradeValue     = bcmul($matchedPrice, $matchedAmount, 18);
        $tradeValueCash = $this->formatMoney($tradeValue);

        $commissionRaw = bcmul($tradeValue, (string) $this->commissionRate, 18);
        $commission    = $this->formatMoney($commissionRaw);

        if (bccomp($buyer->balance ?? '0', $commission, 2) < 0) {
            throw ValidationException::withMessages([
                'balance' => 'Buyer has insufficient balance for commission.',
            ]);
        }

        // Credit seller USD
        $seller->balance = bcadd($seller->balance ?? '0', $tradeValueCash, 2);
        $seller->save();

        // Debit buyer commission
        $buyer->balance = bcsub($buyer->balance ?? '0', $commission, 2);
        $buyer->save();

        // Credit admin commission
        if ($admin) {
            $admin->balance = bcadd($admin->balance ?? '0', $commission, 2);
            $admin->save();
        }

        // Release seller locked asset and credit buyer asset
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
                'user_id'       => $buyer->id,
                'symbol'        => $symbol,
                'amount'        => $matchedAmount,
                'locked_amount' => '0',
            ]);
        }

        $buyerOrder->status = Order::STATUS_FILLED;
        $buyerOrder->updated_at = now();
        $buyerOrder->save();

        $sellerOrder->status = Order::STATUS_FILLED;
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
     * @return void
     */
    protected function ensureBcMath(): void
    {
        if (! extension_loaded('bcmath')) {
            abort(500, 'BCMath PHP extension is required to process orders.');
        }
    }

    /**
     * Format monetary values to 2 decimal places with rounding.
     *
     * @param string $value
     * @return string
     */
    protected function formatMoney(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Queue balance update payload for a user.
     *
     * @param int $userId
     * @return void
     */
    protected function queueBalancesUpdate(int $userId): void
    {
        $user = User::whereKey($userId)
            ->with('assets:id,user_id,symbol,amount,locked_amount')
            ->first();

        if (! $user) {
            return;
        }

        $this->pendingEvents[] = [
            'type'    => 'balances',
            'payload' => [
                'user_id' => $user->id,
                'balance' => $user->balance,
                'assets'  => $user->assets,
            ],
        ];
    }

    /**
     * Enqueue an order book event for later broadcast.
     *
     * @param Order $order
     * @param string $action "add"|"remove"
     * @return void
     */
    protected function queueBookEvent(Order $order, string $action): void
    {
        $this->pendingEvents[] = [
            'type'    => 'orderbook',
            'payload' => [
                'order'  => $order,
                'action' => $action,
            ],
        ];
    }

    /**
     * Queue a matched order event for broadcasting.
     *
     * @param array<string, mixed> $payload
     * @return void
     */
    protected function queueMatchedOrder(array $payload): void
    {
        $this->pendingEvents[] = [
            'type'    => 'matched',
            'payload' => $payload,
        ];
    }

    /**
     * Dispatch any queued events.
     *
     * @return void
     */
    protected function dispatchEvents(): void
    {
        foreach ($this->pendingEvents as $event) {
            switch ($event['type']) {
                case 'orderbook':
                    /** @var Order $order */
                    $order  = $event['payload']['order'];
                    $action = $event['payload']['action'];

                    event(new OrderBookUpdated(
                        $action,
                        $order->id,
                        $order->symbol,
                        $order->side,
                        (string) $order->price,
                        (string) $order->amount,
                        $order->status,
                    ));
                    break;
                case 'matched':
                    $payload = $event['payload'];

                    event(new OrderMatched(
                        $payload['symbol'],
                        $payload['price'],
                        $payload['amount'],
                        $payload['matched_at'],
                        $payload['taker_side'],
                        $payload['buy_order_id'],
                        $payload['sell_order_id'],
                        $payload['buyer_id'],
                        $payload['seller_id'],
                        $payload['buy_status'],
                        $payload['sell_status'],
                    ));
                    break;
                case 'balances':
                    $payload = $event['payload'];
                    event(new UserBalancesUpdated(
                        $payload['user_id'],
                        $payload['balance'],
                        $payload['assets'],
                    ));
                    break;
            }
        }

        $this->pendingEvents = [];
    }

    /**
     * Temporary helper to test Pusher payloads.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pusherTest()
    {
        $now = now()->toIso8601String();
        /*
        $this->queueMatchedOrder([
            'symbol'        => 'BTC',
            'price'         => '25000.00',
            'amount'        => '0.05000000',
            'matched_at'    => $now,
            'taker_side'    => Order::SIDE_BUY,
            'buy_order_id'  => 101,
            'sell_order_id' => 202,
            'buyer_id'      => 2, // make sure these are existing user id'd
            'seller_id'     => 3,
            'buy_status'    => Order::STATUS_FILLED,
            'sell_status'   => Order::STATUS_FILLED,
        ]);
        */

        $dummyOrder = new Order();
        $dummyOrder->id      = 9999;
        $dummyOrder->user_id = 0;
        $dummyOrder->symbol  = 'BTC';
        $dummyOrder->side    = Order::SIDE_SELL;
        $dummyOrder->price   = '25001.00';
        $dummyOrder->amount  = '0.05000000';
        $dummyOrder->status  = Order::STATUS_OPEN;

        $this->queueBookEvent($dummyOrder, 'open');

        $this->dispatchEvents();

        return response()->json(['message' => 'Test event sent', 'matched_at' => $now]);
    }
}
