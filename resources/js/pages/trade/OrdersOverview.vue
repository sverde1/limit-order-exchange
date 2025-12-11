<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { overview } from '@/routes/trade';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

type Asset = {
    symbol: string;
    amount: string;
    locked_amount: string;
};

type ProfileResponse = {
    user: {
        id: number;
        name: string;
        email: string;
        balance: string;
    };
    assets: Asset[];
};

type Order = {
    id: number;
    user_id: number;
    symbol: string;
    side: 'buy' | 'sell';
    price: string;
    amount: string;
    status: number;
    created_at?: string;
    opened_at?: string;
    filled_at?: string | null;
    canceled_at?: string | null;
};

type Orderbook = {
    buys: Order[];
    sells: Order[];
};

type Trade = {
    symbol: string;
    side: string;
    price: string;
    amount: string;
    matched_at: string;
};

const symbols = ['BTC', 'ETH'];
const selectedSymbol = ref(symbols[0]);

const profile = ref<ProfileResponse | null>(null);
const orders = ref<Order[]>([]);
const orderbook = ref<Orderbook>({
  buys: [],
  sells: [],
});
const recentTrades = ref<Trade[]>([]);

const loadingProfile = ref(false);
const loadingOrders = ref(false);
const loadingOrderbook = ref(false);
const errorMessage = ref('');

const usdBalance = computed(() => profile.value?.user.balance ?? '0.00');
const assetBalances = computed(() => profile.value?.assets ?? []);

const xsrfToken = () => {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const withAuthHeaders = async (json = true) => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });

    const headers: Record<string, string> = {
        Accept: 'application/json',
    };

    if (json) {
        headers['Content-Type'] = 'application/json';
    }

    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return headers;
};

const loadProfile = async () => {
    loadingProfile.value = true;
    const response = await fetch('/api/profile', {
        credentials: 'same-origin',
        headers: await withAuthHeaders(false),
    });

    if (response.ok) {
        profile.value = await response.json();
        errorMessage.value = '';
    } else {
        errorMessage.value = 'Unable to load balances.';
    }

    loadingProfile.value = false;
};

const loadOrders = async () => {
    loadingOrders.value = true;
    const response = await fetch(`/api/profile/orders`, {
        credentials: 'same-origin',
        headers: await withAuthHeaders(false),
    });

    if (response.ok) {
        const data = await response.json();
        orders.value = data.orders ?? [];
        errorMessage.value = '';
    } else {
        errorMessage.value = 'Unable to load orders.';
    }

    loadingOrders.value = false;
};

const loadOrderbook = async () => {
    loadingOrders.value = true;
    const response = await fetch(`/api/orders?symbol=${selectedSymbol.value}`, {
        credentials: 'same-origin',
        headers: await withAuthHeaders(false),
    });

    if (response.ok) {
        const data = await response.json();
        const orders = data.orders ?? [];

        orderbook.value = {
            buys: orders.filter((o: any) => o.side === 'buy'),
            sells: orders.filter((o: any) => o.side === 'sell'),
        };
        errorMessage.value = '';
    } else {
        errorMessage.value = 'Unable to load order book.';
    }

    loadingOrderbook.value = false;
};

const refreshAll = async () => {
    await Promise.all([loadProfile(), loadOrders(), loadOrderbook()]);
};

const resetWallet = async () => {
    const resetWalletUrl = new URL(
        '/api/profile/reset-wallet',
        window.location.origin,
    ).toString();

    const response = await fetch(resetWalletUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
    });

    if (!response.ok) {
        errorMessage.value = 'Unable to reset wallet.';
        return;
    }

    errorMessage.value = '';
    await loadProfile();
};

const statusLabel = (status: number) => {
    if (status === 1) return 'Open';
    if (status === 2) return 'Filled';
    if (status === 3) return 'Canceled';
    return 'Unknown';
};

const statusVariant = (status: number) => {
    if (status === 1) return 'secondary';
    if (status === 2) return 'success';
    if (status === 3) return 'outline';
    return 'secondary';
};

const handleOrderMatched = async (payload: any) => {
    const trade: Trade = {
        symbol: payload.symbol ?? selectedSymbol.value,
        side: payload.side ?? 'buy',
        price: payload.price ?? '0',
        amount: payload.amount ?? '0',
        matched_at: payload.matched_at ?? new Date().toISOString(),
    };

    recentTrades.value = [trade, ...recentTrades.value].slice(0, 10);

    const orderId = payload.order_id ?? payload.id;
    if (orderId) {
        const idx = orders.value.findIndex((o) => o.id === orderId);
        if (idx !== -1) {
            orders.value[idx] = {
                ...orders.value[idx],
                status: payload.status ?? orders.value[idx].status,
                filled_at: payload.filled_at ?? orders.value[idx].filled_at,
                canceled_at:
                    payload.canceled_at ?? orders.value[idx].canceled_at,
            };
        }
    }

    await refreshAll();
};

const setupOrderMatchedListener = () => {
    const echo = (window as any).Echo;
    if (echo?.channel) {
        const channel = echo.channel('orders');
        channel.listen('OrderMatched', (payload: any) => {
            handleOrderMatched(payload);
        });

        return () => channel.stopListening('OrderMatched');
    }

    const handler = (event: any) => handleOrderMatched(event.detail || event);
    window.addEventListener('order-matched', handler);
    return () => window.removeEventListener('order-matched', handler);
};

const cleanup = ref<(() => void) | null>(null);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Orders & Wallet',
        href: overview().url,
    },
];

onMounted(async () => {
    await refreshAll();
    cleanup.value = setupOrderMatchedListener();
});

onBeforeUnmount(() => {
    cleanup.value?.();
});

watch(selectedSymbol, async () => {
    await loadOrderbook();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Orders & Wallet" />

        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <HeadingSmall
                    title="Orders & Wallet Overview"
                    description="Track balances, past orders, and live orderbook."
                />
            </div>

            <div v-if="errorMessage" class="text-sm text-destructive">
                {{ errorMessage }}
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-1">
                    <CardHeader>
                        <CardTitle>Wallet</CardTitle>
                        <CardDescription>
                            USD and asset balances
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-muted-foreground"
                                >USD Balance</span
                            >
                            <span class="font-semibold"
                                >${{ usdBalance }}</span
                            >
                        </div>
                        <div class="flex items-center justify-end">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="resetWallet"
                                title="This is for demo purposes only"
                            >
                                Reset wallet balance to $10k
                            </Button>
                        </div>
                        <Separator />
                        <div class="space-y-2">
                            <div
                                v-if="!assetBalances.length"
                                class="flex items-center justify-between text-sm text-muted-foreground"
                            >
                                <span>No assets yet.</span>
                            </div>
                            <div
                                v-for="asset in assetBalances"
                                :key="asset.symbol"
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="font-medium">{{
                                    asset.symbol
                                }}</span>
                                <div class="text-right">
                                    <div>{{ asset.amount }} available</div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ asset.locked_amount }} locked
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>My Orders</CardTitle>
                        <CardDescription>
                            Open, filled, and canceled orders for
                            {{ selectedSymbol }}.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="loadingOrders" class="text-sm text-muted-foreground">
                            Loading orders...
                        </div>
                        <div
                            v-else-if="!orders.length"
                            class="text-sm text-muted-foreground"
                        >
                            No orders yet.
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-muted-foreground">
                                    <tr class="border-b">
                                        <th class="px-2 py-2 font-medium">
                                            Side
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            Price
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            Amount
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            Status
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            Created
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="order in orders"
                                        :key="order.id"
                                        class="border-b last:border-none"
                                    >
                                        <td class="px-2 py-2 capitalize">
                                            {{ order.side }}
                                        </td>
                                        <td class="px-2 py-2">
                                            ${{ order.price }}
                                        </td>
                                        <td class="px-2 py-2">
                                            {{ order.amount }}
                                        </td>
                                        <td class="px-2 py-2">
                                            <Badge
                                                :variant="statusVariant(order.status) as any"
                                            >
                                                {{ statusLabel(order.status) }}
                                            </Badge>
                                        </td>
                                        <td class="px-2 py-2">
                                            {{
                                                order.created_at
                                                    ? new Date(
                                                          order.created_at,
                                                      ).toLocaleString()
                                                    : '—'
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <div
                            class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
                        >
                            <div>
                                <CardTitle>Orderbook (Open)</CardTitle>
                                <CardDescription>
                                    Live open orders for {{ selectedSymbol }}.
                                </CardDescription>
                            </div>
                            <div class="flex items-center gap-2">
                                <label
                                    class="text-sm font-medium text-muted-foreground"
                                    >Symbol</label
                                >
                                <select
                                    v-model="selectedSymbol"
                                    class="h-10 rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option
                                        v-for="sym in symbols"
                                        :key="sym"
                                        :value="sym"
                                    >
                                        {{ sym }}
                                    </option>
                                </select>
                                <Button size="sm" variant="outline" @click="refreshAll">
                                    Refresh
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="grid gap-4 md:grid-cols-2">
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-muted-foreground">
                                Bids (Buy)
                            </h4>
                            <div
                                v-if="!orderbook.buys.length"
                                class="text-sm text-muted-foreground"
                            >
                                No buy orders.
                            </div>
                            <div
                                v-for="order in orderbook.buys"
                                :key="order.id"
                                class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm"
                            >
                                <span>${{ order.price }}</span>
                                <span>{{ order.amount }}</span>
                            </div>
                        </div>
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-muted-foreground">
                                Asks (Sell)
                            </h4>
                            <div
                                v-if="!orderbook.sells.length"
                                class="text-sm text-muted-foreground"
                            >
                                No sell orders.
                            </div>
                            <div
                                v-for="order in orderbook.sells"
                                :key="order.id"
                                class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm"
                            >
                                <span>${{ order.price }}</span>
                                <span>{{ order.amount }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent matches</CardTitle>
                        <CardDescription>
                            Updated when OrderMatched events arrive.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <div
                            v-if="!recentTrades.length"
                            class="text-sm text-muted-foreground"
                        >
                            No trades yet.
                        </div>
                        <div
                            v-for="trade in recentTrades"
                            :key="trade.matched_at + trade.symbol + trade.amount"
                            class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm"
                        >
                            <div>
                                <div class="font-medium">
                                    {{ trade.symbol }} — {{ trade.side }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        new Date(
                                            trade.matched_at,
                                        ).toLocaleString()
                                    }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold">
                                    ${{ trade.price }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ trade.amount }}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
