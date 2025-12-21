<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderEventQueue;
use App\Services\Orders\OrderFundsService;
use App\Services\Orders\OrderMatchingService;
use Illuminate\Support\Facades\DB;

class PlaceOrder
{
    /**
     * Create a new place-order action.
     *
     * @param  OrderEventQueue  $events  Event queue for dispatching.
     * @param  OrderFundsService  $fundsService  Service for reserving funds.
     * @param  OrderMatchingService  $matchingService  Service for matching orders.
     */
    public function __construct(
        private OrderEventQueue $events,
        private OrderFundsService $fundsService,
        private OrderMatchingService $matchingService,
    ) {}

    /**
     * Place a new order and dispatch the resulting events.
     *
     * @param  User  $user  User placing the order.
     * @param  array{symbol: string, side: string, price: string, amount: string}  $validated  Validated order data.
     * @return Order Persisted order instance.
     */
    public function execute(User $user, array $validated): Order
    {
        $this->events->reset();

        $symbol = strtoupper($validated['symbol']);

        $order = DB::transaction(function () use ($validated, $symbol, $user) {
            $this->fundsService->reserveForOrder($user, $validated, $symbol);

            $order = Order::create([
                'user_id'    => $user->id,
                'symbol'     => $symbol,
                'side'       => $validated['side'],
                'price'      => $validated['price'],
                'amount'     => $validated['amount'],
                'status'     => Order::STATUS_OPEN,
                'created_at' => now(),
            ]);

            $matchResult = $this->matchingService->attemptMatch($order);
            if ($matchResult) {
                $order = $matchResult['taker_order'];

                $this->events->queueMatchedOrder($matchResult['payload']);
                $this->events->queueBookEvent($matchResult['taker_order'], 'filled', $user->id);
                $this->events->queueBookEvent($matchResult['maker_order'], 'filled', $user->id);
                $this->events->queueBalancesUpdate($matchResult['taker_order']->user_id);
                $this->events->queueBalancesUpdate($matchResult['maker_order']->user_id);
            } else {
                $this->events->queueBookEvent($order, 'open', $user->id);
                $this->events->queueBalancesUpdate($order->user_id);
            }

            return $order;
        });

        $this->events->dispatch();

        return $order;
    }
}
