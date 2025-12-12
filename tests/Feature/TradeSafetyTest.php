<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradeSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure buy orders reserve USD and prevent spending more than the available balance.
     */
    public function test_buy_order_reserves_balance_and_blocks_overspend(): void
    {
        $user = User::factory()->create([
            'balance' => '100.00',
        ]);
        Sanctum::actingAs($user, abilities: ['*']);

        $first = $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side'   => 'buy',
            'price'  => '60.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $first->assertCreated();
        $this->assertSame('40.00', $user->fresh()->balance);

        $second = $this->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side'   => 'buy',
            'price'  => '50.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $second->assertStatus(422)->assertJsonValidationErrors(['balance']);
        $this->assertSame('40.00', $user->fresh()->balance);
        $this->assertCount(1, Order::where('user_id', $user->id)->get());
    }

    /**
     * Ensure sell orders lock assets and reject additional sells that exceed holdings.
     */
    public function test_sell_order_reserves_asset_and_blocks_oversell(): void
    {
        $user = User::factory()->create();
        $asset = Asset::create([
            'user_id'       => $user->id,
            'symbol'        => 'ETH',
            'amount'        => '1.000000000000000000',
            'locked_amount' => '0',
        ]);

        Sanctum::actingAs($user, abilities: ['*']);

        $first = $this->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side'   => 'sell',
            'price'  => '100.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $first->assertCreated();
        $asset->refresh();
        $this->assertSame('0.000000000000000000', (string) $asset->amount);
        $this->assertSame('1.000000000000000000', (string) $asset->locked_amount);

        $second = $this->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side'   => 'sell',
            'price'  => '90.00000000',
            'amount' => '1.000000000000000000',
        ]);

        $second->assertStatus(422)->assertJsonValidationErrors(['balance']);
        $asset->refresh();
        $this->assertSame('0.000000000000000000', (string) $asset->amount);
        $this->assertSame('1.000000000000000000', (string) $asset->locked_amount);
        $this->assertCount(1, Order::where('user_id', $user->id)->get());
    }

    /**
     * Verify canceling a buy order releases reserved USD funds.
     */
    public function test_cancel_buy_order_refunds_balance(): void
    {
        $user = User::factory()->create([
            'balance' => '200.00',
        ]);
        Sanctum::actingAs($user, abilities: ['*']);

        $created = $this->postJson('/api/orders', [
            'symbol' => 'SOL',
            'side'   => 'buy',
            'price'  => '50.00000000',
            'amount' => '1.000000000000000000',
        ])->assertCreated();

        $orderId = $created->json('id');
        $this->assertSame('150.00', $user->fresh()->balance);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        $this->assertSame('200.00', $user->fresh()->balance);
        $this->assertEquals(
            Order::STATUS_CANCELED,
            Order::whereKey($orderId)->value('status')
        );
    }

    /**
     * Verify canceling a sell order releases locked assets back to available balance.
     */
    public function test_cancel_sell_order_releases_locked_assets(): void
    {
        $user = User::factory()->create();
        $asset = Asset::create([
            'user_id'       => $user->id,
            'symbol'        => 'ETH',
            'amount'        => '2.000000000000000000',
            'locked_amount' => '0',
        ]);

        Sanctum::actingAs($user, abilities: ['*']);

        $created = $this->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side'   => 'sell',
            'price'  => '1.00000000',
            'amount' => '1.500000000000000000',
        ])->assertCreated();

        $orderId = $created->json('id');
        $asset->refresh();
        $this->assertSame('0.500000000000000000', (string) $asset->amount);
        $this->assertSame('1.500000000000000000', (string) $asset->locked_amount);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        $asset->refresh();
        $this->assertSame('2.000000000000000000', (string) $asset->amount);
        $this->assertSame('0.000000000000000000', (string) $asset->locked_amount);
        $this->assertEquals(
            Order::STATUS_CANCELED,
            Order::whereKey($orderId)->value('status')
        );
    }
}
