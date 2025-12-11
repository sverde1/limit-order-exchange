<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    /**
     * Return current user's personal access tokens.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request)
    {
        $tokens = $request->user()->tokens()
            ->select(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at'])
            ->latest()
            ->get();

        return response()->json([
            'tokens' => $tokens,
        ]);
    }

    /**
     * Issue a new personal access token with optional abilities/expiry.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'max:32'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $token = $request->user()->createToken(
            $validated['name'],
            $validated['abilities'] ?? ['*'],
            expiresAt: isset($validated['expires_at'])
                ? CarbonImmutable::parse($validated['expires_at'])
                : null,
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'access_token' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'abilities' => $token->accessToken->abilities,
                'expires_at' => $token->accessToken->expires_at,
                'created_at' => $token->accessToken->created_at,
            ],
        ], 201);
    }

    /**
     * Revoke a specific personal access token.
     *
     * @param Request $request
     * @param PersonalAccessToken $token
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, PersonalAccessToken $token)
    {
        $user = $request->user();

        if ($token->tokenable_id !== $user->id || $token->tokenable_type !== $user::class) {
            abort(404);
        }

        $token->delete();

        return response()->json([
            'message' => 'Token revoked.',
        ]);
    }
}
