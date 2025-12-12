<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradesControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure trades endpoint returns paginated trades for the authenticated user.
     *
     * @return void
     */
    public function test_lists_trades_for_authenticated_user_with_pagination(): void
    {
        $buyer  = User::factory()->create();
        $seller = User::factory()->create();

        // create trades via orders to respect relationships
        $buyOrder = Order::factory()->create([
            'user_id' => $buyer->id,
            'symbol'  => 'BTC',
            'side'    => Order::SIDE_BUY,
            'price'   => '30000',
            'amount'  => '0.1',
            'status'  => Order::STATUS_FILLED,
        ]);

        $sellOrder = Order::factory()->create([
            'user_id' => $seller->id,
            'symbol'  => 'BTC',
            'side'    => Order::SIDE_SELL,
            'price'   => '30000',
            'amount'  => '0.1',
            'status'  => Order::STATUS_FILLED,
        ]);

        // give seller an asset record so trade fee can be computed in map logic without nulls
        Asset::create([
            'user_id'       => $seller->id,
            'symbol'        => 'BTC',
            'amount'        => '1.0',
            'locked_amount' => '0',
        ]);

        // insert trades row directly
        \DB::table('trades')->insert([
            'buy_order_id'  => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'symbol'        => 'BTC',
            'price'         => '30000',
            'amount'        => '0.1',
            'commission'    => '45.00',
            'matched_at'    => now()->toDateTimeString(),
        ]);

        $response = $this->actingAs($buyer)->getJson('/api/trades?per_page=10&page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'trades' => [
                    [
                        'id',
                        'symbol',
                        'side',
                        'price',
                        'amount',
                        'value',
                        'fee',
                        'matched_at',
                    ],
                ],
                'meta' => [
                    'page',
                    'per_page',
                ],
            ]);

        $this->assertEquals(1, $response->json('meta.page'));
        $this->assertEquals(10, $response->json('meta.per_page'));

        $first = $response->json('trades.0');
        $this->assertEquals('buy', $first['side']);
        $this->assertEquals('BTC', $first['symbol']);
        $this->assertEquals('0.1', $first['amount']);
        $this->assertEquals('30000', $first['price']);
        $this->assertEquals('3000.00', $first['value']);
        // buyer should see no fee (only seller pays commission)
        $this->assertNull($first['fee']);
    }

    /**
     * Trades endpoint should require authentication.
     *
     * @return void
     */
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/trades')->assertUnauthorized();
    }
}
