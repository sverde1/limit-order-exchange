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
}
