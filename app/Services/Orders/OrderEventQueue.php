<?php

namespace App\Services\Orders;

use App\Events\OrderBookUpdated;
use App\Events\OrderMatched;
use App\Events\UserBalancesUpdated;
use App\Models\Order;
use App\Models\User;

class OrderEventQueue
{
    /**
     * @var array<int, array{type: string, payload: mixed}>
     */
    private array $pendingEvents = [];

    /**
     * Clear any queued events for a fresh transaction.
     *
     * @return void Resets the internal event queue.
     */
    public function reset(): void
    {
        $this->pendingEvents = [];
    }

    /**
     * Queue a balance update payload for the given user.
     *
     * @param  int  $userId  User ID to include in the balance payload.
     * @return void Queues a balance update event.
     */
    public function queueBalancesUpdate(int $userId): void
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
                'user_id'        => $user->id,
                'balance'        => $user->balance,
                'balance_locked' => $user->balance_locked,
                'assets'         => $user->assets,
            ],
        ];
    }

    /**
     * Queue an order book event payload.
     *
     * @param  Order  $order  Order to include in the payload.
     * @param  string  $action  Action for the orderbook update.
     * @param  int|null  $actorId  Actor user ID, if available.
     * @return void Queues an orderbook update event.
     */
    public function queueBookEvent(Order $order, string $action, ?int $actorId = null): void
    {
        $this->pendingEvents[] = [
            'type'    => 'orderbook',
            'payload' => [
                'order'    => $order,
                'action'   => $action,
                'actor_id' => $actorId,
            ],
        ];
    }

    /**
     * Queue a matched trade event payload.
     *
     * @param  array<string, mixed>  $payload  Matched trade payload.
     * @return void Queues a matched trade event.
     */
    public function queueMatchedOrder(array $payload): void
    {
        $this->pendingEvents[] = [
            'type'    => 'matched',
            'payload' => $payload,
        ];
    }

    /**
     * Dispatch any queued events in order and clear the queue.
     *
     * @return void Dispatches all queued events.
     */
    public function dispatch(): void
    {
        foreach ($this->pendingEvents as $event) {
            switch ($event['type']) {
                case 'orderbook':
                    /** @var Order $order */
                    $order   = $event['payload']['order'];
                    $action  = $event['payload']['action'];
                    $actorId = $event['payload']['actor_id'] ?? null;

                    event(new OrderBookUpdated(
                        $action,
                        $order->id,
                        $actorId,
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
                        $payload['balance_locked'],
                        $payload['assets'],
                    ));
                    break;
            }
        }

        $this->pendingEvents = [];
    }
}
