<?php

namespace App\Services\Profile;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProfileOrdersService
{
    /**
     * Return filtered orders for the authenticated user.
     *
     * @param  User  $user  Authenticated user instance.
     * @param  string|null  $symbol  Optional symbol filter.
     * @param  string  $status  Status filter value.
     * @return Collection Filtered orders collection.
     */
    public function getOrders(User $user, ?string $symbol, string $status): Collection
    {
        $statusMap = [
            'open'     => Order::STATUS_OPEN,
            'filled'   => Order::STATUS_FILLED,
            'canceled' => Order::STATUS_CANCELED,
        ];

        return $user
            ->orders()
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->when($status !== 'all', fn ($query) => $query->where('status', $statusMap[$status]))
            ->orderByDesc('created_at')
            ->get();
    }
}
