<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderBookUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param string $action "add"|"remove"
     * @param int $orderId
     * @param int|null $actorId
     * @param string $symbol
     * @param string $side
     * @param string $price
     * @param string $amount
     * @param int $status
     */
    public function __construct(
        public readonly string $action,
        public readonly int $orderId,
        public readonly ?int $actorId,
        public readonly string $symbol,
        public readonly string $side,
        public readonly string $price,
        public readonly string $amount,
        public readonly int $status,
    ) {
    }

    /**
     * Broadcast event name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'OrderBookUpdated';
    }

    /**
     * Channels to broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('orderbook'),
        ];
    }

    /**
     * Payload delivered to listeners.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action'   => $this->action,
            'order_id' => $this->orderId,
            'actor_id' => $this->actorId,
            'symbol'   => $this->symbol,
            'side'     => $this->side,
            'price'    => $this->price,
            'amount'   => $this->amount,
            'status'   => $this->status,
        ];
    }
}
