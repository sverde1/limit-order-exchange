<?php

namespace Tests\Feature;

use App\Events\OrderBookUpdated;
use App\Events\OrderMatched;
use App\Events\UserBalancesUpdated;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrdersControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure index only returns open orders.
     */
    public function test_index_lists_only_open_orders(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, abilities: ['*']);

        Order::factory()->create([
            'symbol' => 'BTC',
            'status' => Order::STATUS_OPEN,
        ]);

        Order::factory()->create([
            'symbol' => 'BTC',
            'status' => Order::STATUS_FILLED,
        ]);

        $openId = Order::where('status', Order::STATUS_OPEN)->value('id');

        $response = $this->getJson('/api/orders?symbol=BTC');

        $response->assertOk();
        $this->assertCount(1, $response->json('orders'));
        $this->assertSame($openId, $response->json('orders.0.id'));
    }

    /**
     * Ensure index filters by symbol.
     */
    public function test_index_lists_only_orders_of_symbol(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, abilities: ['*']);

        Order::factory()->create([
            'symbol' => 'BTC',
            'status' => Order::STATUS_OPEN,
        ]);

        Order::factory()->create([
            'symbol' => 'ETH',
            'status' => Order::STATUS_OPEN,
        ]);

        $response = $this->getJson('/api/orders?symbol=BTC');

        $response->assertOk();
        $this->assertCount(1, $response->json('orders'));
        $this->assertSame('BTC', $response->json('orders.0.symbol'));
    }

    /**
     * Ensure creating a buy order reserves balance and returns the order.
     */
    public function test_create_creates_order_and_reserves_balance(): void
    {
        $user = User::factory()->create([
            'balance' => '100.00',
        ]);
        Sanctum::actingAs($user, abilities: ['*']);

        $response = $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '10.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $response->assertCreated();
        $this->assertSame('90.00', $user->fresh()->balance);
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('id'),
            'symbol' => 'BTC',
            'side' => Order::SIDE_BUY,
            'status' => Order::STATUS_OPEN,
        ]);
    }

    /**
     * Ensure creating an order dispatches book and balance events.
     */
    public function test_create_dispatches_pusher_events(): void
    {
        $user = User::factory()->create([
            'balance' => '50.00',
        ]);
        Sanctum::actingAs($user, abilities: ['*']);

        Event::fake([OrderBookUpdated::class, UserBalancesUpdated::class, OrderMatched::class]);

        $response = $this->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'buy',
            'price' => '5.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $response->assertCreated();

        Event::assertDispatched(OrderBookUpdated::class);
        Event::assertDispatched(UserBalancesUpdated::class);
        Event::assertNotDispatched(OrderMatched::class);
    }

    /**
     * Ensure orders of the same size match and update statuses.
     */
    public function test_create_matches_same_size_orders(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create([
            'balance' => '100.00',
        ]);

        Asset::create([
            'user_id' => $seller->id,
            'symbol' => 'BTC',
            'amount' => '1.000000000000000000',
            'locked_amount' => '0',
        ]);

        Sanctum::actingAs($seller, abilities: ['*']);

        $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '20.00000000',
            'amount' => '1.000000000000000000',
        ])->assertCreated();

        Sanctum::actingAs($buyer, abilities: ['*']);
        Event::fake([OrderMatched::class, OrderBookUpdated::class, UserBalancesUpdated::class]);

        $response = $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '20.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $response->assertCreated();

        Event::assertDispatched(OrderMatched::class);
        $this->assertEquals(Order::STATUS_FILLED, Order::find($response->json('id'))->status);
        $this->assertEquals(
            Order::STATUS_FILLED,
            Order::where('side', Order::SIDE_SELL)->where('symbol', 'BTC')->value('status')
        );
    }

    /**
     * Ensure cancel updates status to canceled and releases funds/assets.
     */
    public function test_cancel_changes_status_to_canceled(): void
    {
        $user = User::factory()->create([
            'balance' => '100.00',
        ]);
        Sanctum::actingAs($user, abilities: ['*']);

        $orderResponse = $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '10.00000000',
            'amount' => '1.000000000000000000',
        ])->assertCreated();

        $orderId = $orderResponse->json('id');

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        $this->assertEquals(Order::STATUS_CANCELED, Order::find($orderId)->status);
        $this->assertSame('100.00', $user->fresh()->balance);
    }

    /**
     * Ensure cancel validates open status.
     */
    public function test_cancel_requires_order_open(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, abilities: ['*']);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_FILLED,
        ]);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Ensure cancel dispatches order book and balance events.
     */
    public function test_cancel_dispatches_pusher_events(): void
    {
        $user = User::factory()->create([
            'balance' => '50.00',
        ]);
        Sanctum::actingAs($user, abilities: ['*']);

        $orderResponse = $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '5.00000000',
            'amount' => '1.000000000000000000',
        ])->assertCreated();

        Event::fake([OrderBookUpdated::class, UserBalancesUpdated::class]);

        $this->postJson("/api/orders/{$orderResponse->json('id')}/cancel")->assertOk();

        Event::assertDispatched(OrderBookUpdated::class);
        Event::assertDispatched(UserBalancesUpdated::class);
    }
}
