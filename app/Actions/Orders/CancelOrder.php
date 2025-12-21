<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderEventQueue;
use App\Services\Orders\OrderFundsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    /**
     * Create a new cancel-order action.
     *
     * @param  OrderEventQueue  $events  Event queue for dispatching.
     * @param  OrderFundsService  $fundsService  Service for releasing funds.
     */
    public function __construct(
        private OrderEventQueue $events,
        private OrderFundsService $fundsService,
    ) {}

    /**
     * Cancel an open order and dispatch the resulting events.
     *
     * @param  User  $user  User performing the cancellation.
     * @param  Order  $order  Order being canceled.
     * @return Order Updated order instance.
     */
    public function execute(User $user, Order $order): Order
    {
        $this->events->reset();

        $order = DB::transaction(function () use ($order, $user) {
            $order = Order::whereKey($order->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== Order::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'status' => 'Order is not open.',
                ]);
            }

            $this->fundsService->releaseForCancellation($order);

            $order->status     = Order::STATUS_CANCELED;
            $order->updated_at = now();
            $order->save();

            return $order;
        });

        $this->events->queueBookEvent($order, 'canceled');
        $this->events->queueBalancesUpdate($order->user_id);
        $this->events->dispatch();

        return $order->fresh();
    }
}
