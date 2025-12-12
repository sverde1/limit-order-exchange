<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Pusher from 'pusher-js';

import { overview } from '@/routes/trade';
import { type AppPageProps, type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
    highlight?: 'open' | 'filled' | 'canceled';
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

const page = usePage<AppPageProps>();
const profile = ref<ProfileResponse | null>(null);
const orders = ref<Order[]>([]);
const orderbook = ref<Orderbook>({
  buys: [],
  sells: [],
});
const recentTrades = ref<Trade[]>([]);
const cancelingOrderIds = ref<number[]>([]);
const currentUserId = computed(() => page.props.auth.user.id);
const pusherInstance = ref<Pusher | null>(null);
const bookChannel = ref<any>(null);
const loadingProfile = ref(false);
const loadingOrders = ref(false);
const loadingOrderbook = ref(false);
const errorMessage = ref('');

const usdBalance = computed(() => profile.value?.user.balance ?? '0.00');
const assetBalances = computed(() => profile.value?.assets ?? []);
const demoMenuOpen = ref(false);
const demoBalance = ref('10000');
const demoAssetName = ref('BTC');
const demoAssetAmount = ref('0.1');
const demoLoadingBalance = ref(false);
const demoLoadingAsset = ref(false);

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
    loadingOrderbook.value = true;
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

const addDemoBalance = async () => {
    demoLoadingBalance.value = true;
    errorMessage.value = '';

    const balanceNum = Number(demoBalance.value);

    if (!Number.isFinite(balanceNum) || balanceNum <= 0) {
        errorMessage.value = 'Enter a valid balance amount.';
        demoLoadingBalance.value = false;
        return;
    }

    const response = await fetch('/api/profile/add-demo-assets', {
        method: 'POST',
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
        body: JSON.stringify({
            balance: balanceNum,
            symbol: null,
            amount: 0,
        }),
    });

    if (!response.ok) {
        errorMessage.value = 'Unable to add demo USD.';
        demoLoadingBalance.value = false;
        return;
    }

    demoBalance.value = '10000';
    demoMenuOpen.value = false;
    demoLoadingBalance.value = false;

    await loadProfile();
};

const addDemoAssets = async () => {
    demoLoadingAsset.value = true;
    errorMessage.value = '';

    const assetAmountNum = Number(demoAssetAmount.value);
    const assetName = demoAssetName.value.trim().toUpperCase();

    if (assetName.length === 0 || !Number.isFinite(assetAmountNum) || assetAmountNum < 0) {
        errorMessage.value = 'Enter a valid asset and amount.';
        demoLoadingAsset.value = false;
        return;
    }

    const response = await fetch('/api/profile/add-demo-assets', {
        method: 'POST',
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
        body: JSON.stringify({
            balance: 0,
            symbol: assetName,
            amount: assetAmountNum,
        }),
    });

    if (!response.ok) {
        errorMessage.value = 'Unable to add demo assets.';
        demoLoadingAsset.value = false;
        return;
    }

    demoAssetAmount.value = '0.00';
    demoMenuOpen.value = false;
    demoLoadingAsset.value = false;

    await loadProfile();
};

const formatPrice = (price: string | number) => {
    const numericPrice = Number(price);
    if (Number.isFinite(numericPrice)) {
        const rounded = Math.round(numericPrice * 100) / 100;
        return rounded.toFixed(2);
    }
    return '0.00';
};

const cancelOrder = async (orderId: number) => {
    if (cancelingOrderIds.value.includes(orderId)) {
        return;
    }

    cancelingOrderIds.value = [...cancelingOrderIds.value, orderId];

    const response = await fetch(`/api/orders/${orderId}/cancel`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
    });

    if (response.ok) {
        const data = await response.json().catch(() => ({}));
        const updatedOrder = data.order as Order | undefined;

        if (updatedOrder) {
            const idx = orders.value.findIndex((o) => o.id === updatedOrder.id);
            if (idx !== -1) {
                orders.value[idx] = { ...orders.value[idx], status: updatedOrder.status };
            }
        }

        errorMessage.value = '';
    } else {
        errorMessage.value = 'Unable to cancel order.';
    }

    cancelingOrderIds.value = cancelingOrderIds.value.filter((id) => id !== orderId);
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
        side: payload.taker_side ?? payload.side ?? 'buy',
        price: payload.price ?? '0',
        amount: payload.amount ?? '0',
        matched_at: payload.matched_at ?? new Date().toISOString(),
    };

    recentTrades.value = [trade, ...recentTrades.value].slice(0, 10);

    const impactedOrders = [
        { id: payload.buy_order_id, status: payload.buy_status },
        { id: payload.sell_order_id, status: payload.sell_status },
    ].filter((o: any) => Boolean(o.id));

    impactedOrders.forEach((item: any) => {
        const idx = orders.value.findIndex((o) => o.id === item.id);
        if (idx !== -1 && typeof item.status === 'number') {
            orders.value[idx] = {
                ...orders.value[idx],
                status: item.status,
            };
        }
    });

};

const handleOrderBookUpdated = (payload: any) => {
    if (!payload?.symbol || payload.symbol !== selectedSymbol.value) {
        return;
    }

    const side = payload.side;
    const action = payload.action;
    const orderId = payload.order_id;
    const price = payload.price;
    const amount = payload.amount;

    if (!side || !action) {
        return;
    }

    const target = side === 'buy' ? orderbook.value.buys : orderbook.value.sells;

    if (action === 'open') {
        const newOrder = {
            id: orderId,
            user_id: 0,
            symbol: payload.symbol,
            side,
            price,
            amount,
            status: 1,
            highlight: 'open',
        } as Order;
        target.push(newOrder);

        window.setTimeout(() => {
            const idx = target.findIndex((o) => o.id === newOrder.id);
            if (idx !== -1) {
                target[idx].highlight = undefined;
            }
        }, 500);
    } else if (action === 'filled' || action === 'canceled') {
        const idx = target.findIndex((o) => o.id === orderId);
        if (idx !== -1) {
            target[idx] = {
                ...target[idx],
                highlight: action === 'filled' ? 'filled' : 'canceled',
            };
            window.setTimeout(() => {
                const removeIdx = target.findIndex((o) => o.id === orderId);
                if (removeIdx !== -1) {
                    target.splice(removeIdx, 1);
                }
            }, 500);
        }
    }
};

const handleBalancesUpdated = (payload: any) => {
    if (!payload?.user_id || payload.user_id !== currentUserId.value) {
        return;
    }

    if (profile.value?.user) {
        profile.value.user.balance = payload.balance ?? profile.value.user.balance;
    }

    if (payload.assets && Array.isArray(payload.assets) && profile.value) {
        profile.value.assets = payload.assets;
    }
};

const initPusher = async () => {
    if (pusherInstance.value || !currentUserId.value) {
        return pusherInstance.value;
    }

    const headers = await withAuthHeaders(false);

    pusherInstance.value = new Pusher(import.meta.env.VITE_PUSHER_APP_KEY ?? '', {
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        authEndpoint: '/broadcasting/auth',
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') !== 'http',
        auth: {
            headers,
        },
    });

    return pusherInstance.value;
};

const setupOrderBookUpdatedListener = async () => {
    const pusher = await initPusher();
    if (!pusher) return null;

    // Clean up previous book channel if present
    if (bookChannel.value) {
        bookChannel.value.unbind('OrderBookUpdated');
        pusher.unsubscribe(bookChannel.value.name);
        bookChannel.value = null;
    }

    const channelName = `orderbook.${selectedSymbol.value}`;
    const channel = pusher.subscribe(channelName);
    channel.bind('OrderBookUpdated', handleOrderBookUpdated);
    bookChannel.value = channel;

    return () => {
        channel.unbind('OrderBookUpdated', handleOrderBookUpdated);
        pusher.unsubscribe(channelName);
        if (bookChannel.value?.name === channelName) {
            bookChannel.value = null;
        }
    };
};

const setupBalancesUpdatedListener = async () => {
    const pusher = await initPusher();
    if (!pusher || !currentUserId.value) return null;

    const privateChannelName = `private-user.${currentUserId.value}`;
    const channel = pusher.subscribe(privateChannelName);
    channel.bind('UserBalancesUpdated', handleBalancesUpdated);

    return () => {
        channel.unbind('UserBalancesUpdated', handleBalancesUpdated);
        pusher.unsubscribe(privateChannelName);
    };
};

const setupOrderMatchedListener = async () => {
    if (!currentUserId.value) {
        return null;
    }

    const pusher = await initPusher();
    if (!pusher) return null;

    const channelName = `private-user.${currentUserId.value}`;
    const channel = pusher.subscribe(channelName);
    const listener = (payload: any) => handleOrderMatched(payload);
    channel.bind('OrderMatched', listener);

    return () => {
        channel.unbind('OrderMatched', listener);
        pusher.unsubscribe(channelName);
    };
};

const cleanup = ref<(() => void) | null>(null);
const bookCleanup = ref<(() => void) | null>(null);
const balancesCleanup = ref<(() => void) | null>(null);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Orders & Wallet',
        href: overview().url,
    },
];

onMounted(async () => {
    await Promise.all([loadProfile(), loadOrders(), loadOrderbook()]);
    cleanup.value = await setupOrderMatchedListener();
    bookCleanup.value = await setupOrderBookUpdatedListener();
    balancesCleanup.value = await setupBalancesUpdatedListener();
});

onBeforeUnmount(() => {
    cleanup.value?.();
    bookCleanup.value?.();
    balancesCleanup.value?.();
    if (pusherInstance.value) {
        pusherInstance.value.disconnect();
        pusherInstance.value = null;
    }
});

watch(selectedSymbol, async () => {
    await loadOrderbook();
    bookCleanup.value?.();
    bookCleanup.value = await setupOrderBookUpdatedListener();
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
                        <div
                            class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
                        >
                            <div>
                                <CardTitle>Wallet</CardTitle>
                                <CardDescription>
                                    USD and asset balances
                                </CardDescription>
                            </div>
                            <div class="relative">
                                <div
                                    class="flex items-center gap-2"
                                    @click="demoMenuOpen = !demoMenuOpen"
                                >
                                    <Button variant="outline" size="sm" title="This is only for demo purposes">
                                        Set DEMO assets
                                    </Button>
                                </div>
                                <div
                                    v-if="demoMenuOpen"
                                    class="absolute right-[-10rem] z-10 mt-2 w-[30rem] rounded-md border border-border bg-popover p-3 shadow-lg"
                                >
                                    <div class="flex items-center justify-end">
                                        <button
                                            type="button"
                                            class="text-xs text-muted-foreground hover:text-foreground"
                                            @click="demoMenuOpen = false"
                                            aria-label="Close"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    <div class="text-xs text-muted-foreground mb-2">
                                        Quickly top up balances for testing.
                                    </div>
                                    <div class="space-y-3">
                                        <div class="flex flex-col gap-2">
                                            <label class="text-sm font-medium text-muted-foreground">
                                                Add USD balance
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <Input
                                                    v-model="demoBalance"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    class="h-9 flex-1 border border-input bg-muted/30 text-foreground shadow-sm focus-visible:ring-2 focus-visible:ring-ring"
                                                />
                                            <Button
                                                size="sm"
                                                :disabled="demoLoadingBalance"
                                                @click="addDemoBalance"
                                            >
                                                {{ demoLoadingBalance ? 'Adding…' : 'Add' }}
                                            </Button>
                                            </div>
                                        </div>
                                        <div class="flex flex-col gap-2">
                                            <label class="text-sm font-medium text-muted-foreground">
                                                Add asset balance
                                            </label>
                                            <div class="flex items-center gap-2">
                                                <select
                                                    v-model="demoAssetName"
                                                    class="h-9 w-24 rounded-md border border-input bg-muted/30 px-2 text-sm text-foreground shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    <option value="BTC">BTC</option>
                                                    <option value="ETH">ETH</option>
                                                </select>
                                                <Input
                                                    v-model="demoAssetAmount"
                                                    type="number"
                                                    step="0.00000001"
                                                    min="0"
                                                    class="h-9 flex-1 border border-input bg-muted/30 text-foreground shadow-sm focus-visible:ring-2 focus-visible:ring-ring"
                                                />
                                                <Button
                                                    size="sm"
                                                    :disabled="demoLoadingAsset"
                                                    @click="addDemoAssets"
                                                >
                                                    {{ demoLoadingAsset ? 'Adding…' : 'Add' }}
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                            <div class="max-h-[500px] overflow-y-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="sticky top-0 bg-card text-left text-muted-foreground">
                                        <tr class="border-b">
                                            <th class="px-2 py-2 font-medium">Side</th>
                                            <th class="px-2 py-2 font-medium">Price</th>
                                            <th class="px-2 py-2 font-medium">Amount</th>
                                            <th class="px-2 py-2 font-medium">Status</th>
                                            <th class="px-2 py-2 font-medium">Created</th>
                                            <th class="px-2 py-2 text-right font-medium">
                                                Actions
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
                                                ${{ formatPrice(order.price) }}
                                            </td>
                                            <td class="px-2 py-2">
                                                {{ order.amount }}
                                            </td>
                                            <td class="px-2 py-2">
                                                <Badge
                                                    :variant="statusVariant(order.status) as any"
                                                    :class="{
                                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-100':
                                                            order.status === 2,
                                                        'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-100':
                                                            order.status === 3,
                                                    }"
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
                                            <td class="px-2 py-2 text-right">
                                                <Button
                                                    v-if="order.status === 1"
                                                    size="sm"
                                                    variant="destructive"
                                                    :disabled="cancelingOrderIds.includes(order.id)"
                                                    @click="cancelOrder(order.id)"
                                                >
                                                    {{
                                                        cancelingOrderIds.includes(order.id)
                                                            ? 'Canceling…'
                                                            : 'Cancel order'
                                                    }}
                                                </Button>
                                                <span v-else class="text-muted-foreground">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
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
                                <Button size="sm" variant="outline" @click="loadOrderbook">
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
                            class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm transition-colors"
                            :class="{
                                'bg-muted/50': order.highlight === 'open',
                                'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-50':
                                    order.highlight === 'filled',
                                'bg-red-100 text-red-900 dark:bg-red-900/50 dark:text-red-50':
                                    order.highlight === 'canceled',
                            }"
                        >
                            <span>${{ formatPrice(order.price) }}</span>
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
                            class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm transition-colors"
                            :class="{
                                'bg-muted/50': order.highlight === 'open',
                                'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-50':
                                    order.highlight === 'filled',
                                'bg-red-100 text-red-900 dark:bg-red-900/50 dark:text-red-50':
                                    order.highlight === 'canceled',
                            }"
                        >
                            <span>${{ formatPrice(order.price) }}</span>
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
                                    ${{ formatPrice(trade.price) }}
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
