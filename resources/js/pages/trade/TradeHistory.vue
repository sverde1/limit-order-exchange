<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { history } from '@/routes/trade';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

type Trade = {
    id: number;
    symbol: string;
    side: 'buy' | 'sell';
    amount: string;
    price: string;
    value: string;
    fee?: string | null;
    matched_at: string;
};

const trades = ref<Trade[]>([]);
const loading = ref(false);
const errorMessage = ref('');
const currentPage = ref(1);
const perPage = ref(50);
const meta = ref<{ page: number; per_page: number } | null>(null);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Trade History',
        href: history().url,
    },
];

const xsrfToken = () => {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const withAuthHeaders = async () => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });

    const headers: Record<string, string> = {
        Accept: 'application/json',
    };

    const token = xsrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return headers;
};

const loadTrades = async (page?: number) => {
    loading.value = true;
    errorMessage.value = '';
    const targetPage = page ?? currentPage.value;

    const response = await fetch(`/api/trades?page=${targetPage}&per_page=${perPage.value}`, {
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
    });

    if (!response.ok) {
        errorMessage.value = 'Unable to load trade history.';
        trades.value = [];
        loading.value = false;
        return;
    }

    const data = await response.json();
    trades.value = data.trades ?? [];
    meta.value = data.meta ?? null;
    currentPage.value = meta.value?.page ?? targetPage;
    loading.value = false;
};

const goToPage = async (page: number) => {
    if (page < 1) return;
    currentPage.value = page;
    await loadTrades(page);
};

const canGoPrev = () => currentPage.value > 1;
const canGoNext = () => trades.value.length >= perPage.value;

const formatPrice = (price: string | number) => {
    const numericPrice = Number(price);
    if (Number.isFinite(numericPrice)) {
        const rounded = Math.round(numericPrice * 100) / 100;
        return rounded.toFixed(2);
    }
    return '0.00';
};

const formatAmount = (amount: string | number) => {
    const numericAmount = Number(amount);
    if (Number.isFinite(numericAmount)) {
        return numericAmount.toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
    }
    return '0';
};

const formatDate = (value: string) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleString();
};

onMounted(async () => {
    await loadTrades();
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Trade History" />

        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <HeadingSmall
                    title="Trade History"
                    description="Completed trades for your account."
                />
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="loadTrades">
                        Refresh
                    </Button>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Your trades</CardTitle>
                    <CardDescription>
                        Shows matched orders where you were the buyer or seller.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="loading" class="text-sm text-muted-foreground">
                        Loading trades...
                    </div>
                    <div
                        v-else-if="errorMessage"
                        class="text-sm text-destructive"
                    >
                        {{ errorMessage }}
                    </div>
                    <div
                        v-else-if="!trades.length"
                        class="text-sm text-muted-foreground"
                    >
                        No trades yet.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <div class="max-h-[600px] overflow-y-auto">
                            <table class="min-w-full text-sm">
                                <thead class="sticky top-0 bg-card text-left text-muted-foreground">
                                    <tr class="border-b">
                                        <th class="px-2 py-2 font-medium">Time</th>
                                        <th class="px-2 py-2 font-medium">Symbol</th>
                                        <th class="px-2 py-2 font-medium">Side</th>
                                        <th class="px-2 py-2 font-medium">Amount</th>
                                        <th class="px-2 py-2 font-medium">Price</th>
                                        <th class="px-2 py-2 font-medium">Value</th>
                                        <th class="px-2 py-2 font-medium">Fee</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="trade in trades"
                                        :key="trade.id"
                                        class="border-b last:border-none"
                                    >
                                        <td class="px-2 py-2">
                                            {{ formatDate(trade.matched_at) }}
                                        </td>
                                        <td class="px-2 py-2 uppercase">
                                            {{ trade.symbol }}
                                        </td>
                                        <td class="px-2 py-2 capitalize">
                                            {{ trade.side }}
                                        </td>
                                        <td class="px-2 py-2">
                                            {{ formatAmount(trade.amount) }}
                                        </td>
                                        <td class="px-2 py-2">
                                            ${{ formatPrice(trade.price) }}
                                        </td>
                                        <td class="px-2 py-2">
                                            ${{ formatPrice(trade.value) }}
                                        </td>
                                        <td class="px-2 py-2">
                                            <span v-if="trade.fee !== null && trade.fee !== undefined">
                                                ${{ formatPrice(trade.fee) }}
                                            </span>
                                            <span v-else class="text-muted-foreground">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 flex items-center justify-end gap-2 text-sm text-muted-foreground">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="loading || !canGoPrev()"
                                @click="goToPage(currentPage - 1)"
                            >
                                Previous
                            </Button>
                            <span class="px-2">
                                Page {{ currentPage }} ({{ trades.length }} / {{ perPage }})
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="loading || !canGoNext()"
                                @click="goToPage(currentPage + 1)"
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
