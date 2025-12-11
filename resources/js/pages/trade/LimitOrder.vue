<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { limitOrder } from '@/routes/trade';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Errors = Record<string, string>;

const symbols = ['BTC', 'ETH'];
const sides = ['buy', 'sell'];

const form = reactive({
    symbol: symbols[0],
    side: sides[0],
    price: '',
    amount: '',
});

const loading = ref(false);
const message = ref('');
const errors = ref<Errors>({});

const xsrfToken = () => {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const withAuthHeaders = async () => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
    };
};

const placeOrder = async () => {
    loading.value = true;
    errors.value = {};
    message.value = '';

    const response = await fetch('/api/orders', {
        method: 'POST',
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
        body: JSON.stringify(form),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        errors.value = {
            ...(data?.errors ?? {}),
            ...(data?.message && !data?.errors ? { form: data.message } : {}),
        } as Errors;
        loading.value = false;
        return;
    }

    message.value = 'Order placed successfully.';
    form.price = '';
    form.amount = '';
    loading.value = false;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Limit Order',
        href: limitOrder().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Limit Order" />

        <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
            <HeadingSmall
                title="Limit Order"
                description="Place a limit order for BTC or ETH."
            />

            <Card class="max-w-2xl">
                <CardHeader>
                    <CardTitle>Order details</CardTitle>
                    <CardDescription>
                        Choose a symbol and side, then set your price and
                        amount.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="symbol">Symbol</Label>
                            <select
                                id="symbol"
                                v-model="form.symbol"
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
                            <p
                                v-if="errors.symbol"
                                class="text-sm text-destructive"
                            >
                                {{ errors.symbol }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label>Side</Label>
                            <div class="flex gap-3">
                                <label
                                    v-for="side in sides"
                                    :key="side"
                                    class="flex cursor-pointer items-center gap-2 rounded-md border border-input px-3 py-2 text-sm shadow-sm hover:border-ring"
                                >
                                    <input
                                        type="radio"
                                        class="h-4 w-4 border-input text-primary focus:ring-ring"
                                        name="side"
                                        :value="side"
                                        v-model="form.side"
                                    />
                                    <span class="capitalize">{{
                                        side
                                    }}</span>
                                </label>
                            </div>
                            <p
                                v-if="errors.side"
                                class="text-sm text-destructive"
                            >
                                {{ errors.side }}
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="price">Price (USD)</Label>
                            <Input
                                id="price"
                                v-model="form.price"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="25000"
                            />
                            <p
                                v-if="errors.price"
                                class="text-sm text-destructive"
                            >
                                {{ errors.price }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="amount">Amount</Label>
                            <Input
                                id="amount"
                                v-model="form.amount"
                                type="number"
                                step="0.00000001"
                                min="0"
                                placeholder="0.1"
                            />
                            <p
                                v-if="errors.amount"
                                class="text-sm text-destructive"
                            >
                                {{ errors.amount }}
                            </p>
                        </div>
                    </div>

                    <div v-if="errors.form" class="text-sm text-destructive">
                        {{ errors.form }}
                    </div>

                    <div v-if="message" class="text-sm text-emerald-600">
                        {{ message }}
                    </div>

                    <div class="flex justify-end">
                        <Button :disabled="loading" @click="placeOrder">
                            {{ loading ? 'Placing…' : 'Place Order' }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
