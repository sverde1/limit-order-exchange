<?php

namespace App\Http\Controllers\Api;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\PlaceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\IndexOrdersRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Models\Order;
use App\Services\Orders\OrderEventQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * List open or all orders for a given symbol.
     *
     * @param  IndexOrdersRequest  $request  Validated orderbook request.
     * @return JsonResponse Open orders payload.
     */
    public function index(IndexOrdersRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $symbol    = strtoupper($validated['symbol']);

        $orders = Order::query()
            ->select('id', 'symbol', 'side', 'price', 'amount')
            ->where('symbol', $symbol)
            ->where('status', Order::STATUS_OPEN)
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
     * @param  StoreOrderRequest  $request  Validated order creation request.
     * @param  PlaceOrder  $placeOrder  Action that places the order.
     * @return JsonResponse Created order payload.
     */
    public function create(StoreOrderRequest $request, PlaceOrder $placeOrder): JsonResponse
    {
        $order = $placeOrder->execute($request->user(), $request->validated());

        return response()->json($order, 201);
    }

    /**
     * Cancel an open order and release reserved funds/assets.
     *
     * @param  Request  $request  Authenticated request.
     * @param  Order  $order  Order to cancel.
     * @param  CancelOrder  $cancelOrder  Action that cancels the order.
     * @return JsonResponse Cancellation payload.
     */
    public function cancel(Request $request, Order $order, CancelOrder $cancelOrder): JsonResponse
    {
        $order = $cancelOrder->execute($request->user(), $order);

        return response()->json([
            'message' => 'Order canceled.',
            'order' => $order->fresh(),
        ]);
    }

    /**
     * Temporary helper to test Pusher payloads.
     *
     * @param  OrderEventQueue  $events  Event queue for broadcasting.
     * @return JsonResponse Test payload response.
     */
    public function pusherTest(OrderEventQueue $events): JsonResponse
    {
        $now = now()->toIso8601String();

        $dummyOrder          = new Order;
        $dummyOrder->id      = 9999;
        $dummyOrder->user_id = 0;
        $dummyOrder->symbol  = 'BTC';
        $dummyOrder->side    = Order::SIDE_SELL;
        $dummyOrder->price   = '25001.00';
        $dummyOrder->amount  = '0.05000000';
        $dummyOrder->status  = Order::STATUS_OPEN;

        $events->queueBookEvent($dummyOrder, 'open');
        $events->dispatch();

        return response()->json(['message' => 'Test event sent', 'matched_at' => $now]);
    }
}
