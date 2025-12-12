<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderMatched implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param string $symbol
     * @param string $price
     * @param string $amount
     * @param string $matchedAt
     * @param string $takerSide
     * @param int $buyOrderId
     * @param int $sellOrderId
     * @param int $buyerId
     * @param int $sellerId
     * @param int $buyStatus
     * @param int $sellStatus
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $price,
        public readonly string $amount,
        public readonly string $matchedAt,
        public readonly string $takerSide,
        public readonly int $buyOrderId,
        public readonly int $sellOrderId,
        public readonly int $buyerId,
        public readonly int $sellerId,
        public readonly int $buyStatus,
        public readonly int $sellStatus,
    ) {
    }

    /**
     * Broadcast event name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'OrderMatched';
    }

    /**
     * Channels to broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->buyerId}"),
            new PrivateChannel("user.{$this->sellerId}"),
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
            'symbol'        => $this->symbol,
            'price'         => $this->price,
            'amount'        => $this->amount,
            'matched_at'    => $this->matchedAt,
            'taker_side'    => $this->takerSide,
            'buy_order_id'  => $this->buyOrderId,
            'sell_order_id' => $this->sellOrderId,
            'buyer_id'      => $this->buyerId,
            'seller_id'     => $this->sellerId,
            'buy_status'    => $this->buyStatus,
            'sell_status'   => $this->sellStatus,
        ];
    }
}
