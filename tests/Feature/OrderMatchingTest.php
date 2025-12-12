<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_and_buy_match_with_commission_on_seller(): void
    {
        // Admin to collect commission (existing admin may already be id=1)
        $admin = User::firstOrCreate(
            ['id' => 1],
            [
                'name'     => 'Admin',
                'email'    => 'admin@example.com',
                'password' => bcrypt('password'),
                'balance'  => '0',
            ],
        );
        $admin->balance = '0';
        $admin->save();

        // setup seller with $0 of initial balance and with 1 BTC of assets
        $seller = User::factory()->create([
            'balance' => '0',
        ]);

        Asset::create([
            'user_id'       => $seller->id,
            'symbol'        => 'BTC',
            'amount'        => '1.0',
            'locked_amount' => '0',
        ]);

        // setup buyer with $10000 of initial balance and with 0 BTC of assets
        $buyer = User::factory()->create([
            'balance' => '10000.00',
        ]);

        // Seller places a sell order: 0.1 BTC @ 30000
        $this->actingAs($seller)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side'   => Order::SIDE_SELL,
                'price'  => '30000',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Buyer places a buy order: 0.1 BTC @ 35000 (should match immediately)
        $this->actingAs($buyer)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side'   => Order::SIDE_BUY,
                'price'  => '35000',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $seller->refresh();
        $buyer->refresh();
        $admin->refresh();

        $sellerAsset = Asset::where('user_id', $seller->id)->where('symbol', 'BTC')->first();
        $buyerAsset  = Asset::where('user_id', $buyer->id)->where('symbol', 'BTC')->first();

        // Seller sold 0.1 BTC at maker price 30000, buyer pays commission
        $this->assertEquals('0.900000000000000000', $sellerAsset->amount);
        $this->assertEquals('0.000000000000000000', $sellerAsset->locked_amount);
        $this->assertEquals('3000.00', $seller->balance);

        // Buyer gets refunded the difference between bid (3500) and trade (3000), then pays commission
        $this->assertEquals('6955.00', $buyer->balance);
        $this->assertEquals('0.100000000000000000', $buyerAsset->amount);

        // Commission credited to admin (1.5% of 3000 = 45)
        $this->assertEquals('45.00', $admin->balance);
    }
}
