<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure show returns profile, assets, and locked balance totals.
     */
    public function test_show_returns_profile_with_balances(): void
    {
        $user = User::factory()->create([
            'balance' => '500.00',
        ]);

        Asset::create([
            'user_id'       => $user->id,
            'symbol'        => 'BTC',
            'amount'        => '1.250000000000000000',
            'locked_amount' => '0.250000000000000000',
        ]);

        Order::create([
            'user_id'    => $user->id,
            'symbol'     => 'BTC',
            'side'       => Order::SIDE_BUY,
            'price'      => '10.00000000',
            'amount'     => '2.000000000000000000',
            'status'     => Order::STATUS_OPEN,
            'created_at' => now(),
        ]);

        Order::create([
            'user_id'    => $user->id,
            'symbol'     => 'ETH',
            'side'       => Order::SIDE_BUY,
            'price'      => '5.50000000',
            'amount'     => '1.000000000000000000',
            'status'     => Order::STATUS_OPEN,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user, abilities: ['*']);

        $response = $this->getJson('/api/profile');

        $response->assertOk()->assertJsonFragment([
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'balance'        => '500.00',
            'balance_locked' => '25.50',
        ]);

        $this->assertArrayHasKey(0, $response->json('assets'));
        $this->assertSame('BTC', $response->json('assets.0.symbol'));
        $this->assertSame('1.250000000000000000', $response->json('assets.0.amount'));
    }

    /**
     * Ensure demo balance top-up increases USD balance.
     */
    public function test_add_demo_assets_increases_balance(): void
    {
        $user = User::factory()->create([
            'balance' => '10.00',
        ]);

        Sanctum::actingAs($user, abilities: ['*']);

        $this->postJson('/api/profile/add-demo-assets', [
            'balance' => '5.25',
        ])->assertOk()->assertJsonFragment([
            'balance' => '15.25',
        ]);

        $this->assertSame('15.25', $user->fresh()->balance);
    }

    /**
     * Ensure demo assets creation adds or increments asset balances.
     */
    public function test_add_demo_assets_adds_asset_balance(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, abilities: ['*']);

        $this->postJson('/api/profile/add-demo-assets', [
            'symbol' => 'eth',
            'amount' => '2.500000000000000000',
        ])->assertOk();

        $asset = Asset::where('user_id', $user->id)->where('symbol', 'ETH')->first();

        $this->assertNotNull($asset);
        $this->assertSame('2.500000000000000000', (string) $asset->amount);
        $this->assertSame('0.000000000000000000', (string) $asset->locked_amount);
    }

    /**
     * Ensure negative values are rejected when topping up demo balances.
     */
    public function test_add_demo_assets_rejects_out_of_range_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, abilities: ['*']);

        $this->postJson('/api/profile/add-demo-assets', [
            'balance' => -1,
            'symbol'  => 'BTC',
            'amount'  => -0.1,
        ])->assertStatus(422)->assertJsonValidationErrors(['balance', 'amount']);
    }

    /**
     * Ensure orders endpoint only returns the authenticated user's orders.
     */
    public function test_orders_returns_only_authenticated_users_orders(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $ownedOrders = [
            Order::create([
                'user_id'    => $user->id,
                'symbol'     => 'BTC',
                'side'       => Order::SIDE_BUY,
                'price'      => '1.00000000',
                'amount'     => '1.000000000000000000',
                'status'     => Order::STATUS_OPEN,
                'created_at' => now(),
            ]),
            Order::create([
                'user_id'    => $user->id,
                'symbol'     => 'ETH',
                'side'       => Order::SIDE_SELL,
                'price'      => '2.00000000',
                'amount'     => '2.000000000000000000',
                'status'     => Order::STATUS_CANCELED,
                'created_at' => now()->subMinute(),
            ]),
        ];

        Order::create([
            'user_id'    => $other->id,
            'symbol'     => 'BTC',
            'side'       => Order::SIDE_BUY,
            'price'      => '3.00000000',
            'amount'     => '3.000000000000000000',
            'status'     => Order::STATUS_OPEN,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user, abilities: ['*']);

        $response = $this->getJson('/api/profile/orders');

        $response->assertOk();
        $this->assertCount(count($ownedOrders), $response->json('orders'));
        $this->assertEqualsCanonicalizing(
            collect($ownedOrders)->pluck('id')->all(),
            collect($response->json('orders'))->pluck('id')->all()
        );
    }

    /**
     * Ensure orders endpoint applies symbol and status filters.
     */
    public function test_orders_filters_by_symbol_and_status(): void
    {
        $user = User::factory()->create();

        $openBtc = Order::create([
            'user_id'    => $user->id,
            'symbol'     => 'BTC',
            'side'       => Order::SIDE_BUY,
            'price'      => '10.00000000',
            'amount'     => '1.000000000000000000',
            'status'     => Order::STATUS_OPEN,
            'created_at' => now(),
        ]);

        Order::create([
            'user_id'    => $user->id,
            'symbol'     => 'BTC',
            'side'       => Order::SIDE_SELL,
            'price'      => '11.00000000',
            'amount'     => '1.000000000000000000',
            'status'     => Order::STATUS_FILLED,
            'created_at' => now()->subMinutes(2),
        ]);

        Order::create([
            'user_id'    => $user->id,
            'symbol'     => 'ETH',
            'side'       => Order::SIDE_BUY,
            'price'      => '12.00000000',
            'amount'     => '1.000000000000000000',
            'status'     => Order::STATUS_OPEN,
            'created_at' => now()->subMinutes(1),
        ]);

        Sanctum::actingAs($user, abilities: ['*']);

        $response = $this->getJson('/api/profile/orders?symbol=btc&status=open');

        $response->assertOk();
        $this->assertCount(1, $response->json('orders'));
        $this->assertSame($openBtc->id, $response->json('orders.0.id'));
        $this->assertSame('BTC', $response->json('orders.0.symbol'));
        $this->assertSame(Order::STATUS_OPEN, $response->json('orders.0.status'));
    }
}
