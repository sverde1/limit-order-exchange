<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Return authenticated user profile with USD balance and asset balances.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        $user = $request->user()->load([
            'assets:id,user_id,symbol,amount,locked_amount',
        ]);

        return response()->json([
            'user' => [
                'id'      => $user->id,
                'name'    => $user->name,
                'email'   => $user->email,
                'balance' => $user->balance,
            ],
            'assets' => $user->assets,
        ]);
    }

    /**
     * Reset the authenticated user wallet balance to $10k.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetWallet(Request $request)
    {
        if (! extension_loaded('bcmath')) {
            abort(500, 'BCMath PHP extension is required to process funds.');
        }

        $user = $request->user();
        $user->balance = 10000;
        $user->save();

        return response()->json([
            'message' => 'Wallet reset to $10k.',
            'balance' => $user->balance,
        ]);
    }
}
