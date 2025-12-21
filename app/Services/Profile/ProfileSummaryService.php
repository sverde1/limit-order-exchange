<?php

namespace App\Services\Profile;

use App\Models\User;

class ProfileSummaryService
{
    /**
     * Build the profile summary payload for the authenticated user.
     *
     * @param  User  $user  Authenticated user instance.
     * @return array{user: array{id: int, name: string, email: string, balance: string|null, balance_locked: string|null}, commission_rate: float, assets: mixed} Summary payload.
     */
    public function build(User $user): array
    {
        $user = $user->load([
            'assets:id,user_id,symbol,amount,locked_amount',
        ]);

        return [
            'user' => [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'balance'        => $user->balance,
                'balance_locked' => $user->balance_locked,
            ],
            'commission_rate' => (float) config('trading.commission_rate', 0.015),
            'assets'          => $user->assets,
        ];
    }
}
