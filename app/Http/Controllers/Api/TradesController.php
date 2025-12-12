<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TradesController extends Controller
{
    /**
     * Return trades that involve the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $trades = DB::table('trades as t')
            ->join('orders as bo', 'bo.id', '=', 't.buy_order_id')
            ->join('orders as so', 'so.id', '=', 't.sell_order_id')
            ->where(function ($query) use ($userId) {
                $query->where('bo.user_id', $userId)
                    ->orWhere('so.user_id', $userId);
            })
            ->orderByDesc('t.matched_at')
            ->limit(200)
            ->select([
                't.id',
                't.symbol',
                't.price',
                't.amount',
                't.commission',
                't.matched_at',
                'bo.user_id as buyer_id',
                'so.user_id as seller_id',
            ])
            ->get()
            ->map(function ($trade) use ($userId) {
                $side  = ((int) $trade->buyer_id === $userId) ? 'buy' : 'sell';
                $value = number_format((float) bcmul($trade->price, $trade->amount, 18), 2, '.', '');
                $fee   = ((int) $trade->seller_id === $userId)
                    ? number_format((float) $trade->commission, 2, '.', '')
                    : null;

                return [
                    'id'         => $trade->id,
                    'symbol'     => $trade->symbol,
                    'side'       => $side,
                    'price'      => (string) $trade->price,
                    'amount'     => (string) $trade->amount,
                    'value'      => $value,
                    'fee'        => $fee,
                    'matched_at' => $trade->matched_at,
                ];
            });

        return response()->json([
            'trades' => $trades,
        ]);
    }
}
