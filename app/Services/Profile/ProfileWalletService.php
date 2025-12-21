<?php

namespace App\Services\Profile;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProfileWalletService
{
    /**
     * Add demo balances or assets for the authenticated user.
     *
     * @param  User  $user  Authenticated user instance.
     * @param  array{balance?: string|int|float|null, symbol?: string|null, amount?: string|int|float|null}  $validated  Validated demo input.
     * @return array{message: string, balance: string|null, assets: mixed} Updated wallet payload.
     */
    public function addDemoAssets(User $user, array $validated): array
    {
        $this->ensureBcMath();

        DB::transaction(function () use ($user, $validated) {
            if (isset($validated['balance']) && (float) $validated['balance'] > 0) {
                $current       = $user->balance ?? '0';
                $user->balance = bcadd((string) $current, (string) $validated['balance'], 2);
                $user->save();
            }

            if (
                isset($validated['symbol'], $validated['amount']) &&
                $validated['symbol'] &&
                (float) $validated['amount'] > 0
            ) {
                $symbol = strtoupper((string) $validated['symbol']);
                $asset  = Asset::firstOrNew([
                    'user_id' => $user->id,
                    'symbol' => $symbol,
                ]);

                $asset->amount        = bcadd((string) ($asset->amount ?? 0), (string) $validated['amount'], 18);
                $asset->locked_amount = $asset->locked_amount ?? 0;
                $asset->save();
            }
        });

        return [
            'message' => 'Demo balances updated.',
            'balance' => $user->balance,
            'assets' => $user->fresh()->assets,
        ];
    }

    /**
     * Ensure BCMath is available for precise decimal operations.
     *
     * @return void Validates the BCMath extension.
     */
    private function ensureBcMath(): void
    {
        if (! extension_loaded('bcmath')) {
            abort(500, 'BCMath PHP extension is required to process funds.');
        }
    }
}
