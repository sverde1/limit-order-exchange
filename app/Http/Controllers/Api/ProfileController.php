<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\AddDemoAssetsRequest;
use App\Http\Requests\Profile\ProfileOrdersRequest;
use App\Services\Profile\ProfileOrdersService;
use App\Services\Profile\ProfileSummaryService;
use App\Services\Profile\ProfileWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Return authenticated user profile with USD balance and asset balances.
     *
     * @param  Request  $request  Authenticated request.
     * @param  ProfileSummaryService  $summaryService  Service building the profile response.
     * @return JsonResponse Profile payload response.
     */
    public function show(Request $request, ProfileSummaryService $summaryService): JsonResponse
    {
        return response()->json($summaryService->build($request->user()));
    }

    /**
     * Reset the authenticated user wallet balance to $10k.
     *
     * @param  AddDemoAssetsRequest  $request  Validated demo asset request.
     * @param  ProfileWalletService  $walletService  Service updating demo balances.
     * @return JsonResponse Updated balances payload.
     */
    public function addDemoAssets(AddDemoAssetsRequest $request, ProfileWalletService $walletService): JsonResponse
    {
        return response()->json(
            $walletService->addDemoAssets($request->user(), $request->validated())
        );
    }

    /**
     * Return the authenticated user's orders with optional filters.
     *
     * @param  ProfileOrdersRequest  $request  Validated filter request.
     * @param  ProfileOrdersService  $ordersService  Service for fetching orders.
     * @return JsonResponse Filtered orders payload.
     */
    public function orders(ProfileOrdersRequest $request, ProfileOrdersService $ordersService): JsonResponse
    {
        $validated = $request->validated();
        $symbol    = isset($validated['symbol']) ? strtoupper($validated['symbol']) : null;
        $status    = $validated['status'] ?? 'all';

        return response()->json([
            'orders' => $ordersService->getOrders($request->user(), $symbol, $status),
        ]);
    }
}
