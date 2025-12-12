<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';

type TokenSummary = {
    id: number;
    name: string;
    abilities?: string[];
    created_at: string;
    last_used_at: string | null;
    expires_at: string | null;
};

const tokens = ref<TokenSummary[]>([]);
const newTokenName = ref('');
const newPlainTextToken = ref<string | null>(null);
const loading = ref(true);
const creating = ref(false);
const revokeId = ref<number | null>(null);
const errorMessage = ref<string | null>(null);

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

const loadTokens = async () => {
    loading.value = true;
    errorMessage.value = null;

    const response = await fetch('/settings/api-keys/tokens', {
        credentials: 'same-origin',
        headers: await withAuthHeaders(false),
    });

    if (!response.ok) {
        errorMessage.value = 'Unable to load API keys.';
        loading.value = false;
        return;
    }

    const data = await response.json();
    tokens.value = data.tokens ?? [];
    loading.value = false;
};

const createToken = async () => {
    creating.value = true;
    errorMessage.value = null;
    newPlainTextToken.value = null;

    const name = newTokenName.value.trim() || 'New API Key';

    if (!/^[A-Za-z0-9 _-]+$/.test(name)) {
        errorMessage.value = 'Name must be alphanumeric and may include spaces, dashes, or underscores.';
        creating.value = false;
        return;
    }

    const response = await fetch('/settings/api-keys', {
        method: 'POST',
        credentials: 'same-origin',
        headers: await withAuthHeaders(),
        body: JSON.stringify({
            name,
            abilities: ['*'],
        }),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        errorMessage.value =
            error?.message ||
            error?.errors?.name?.[0] ||
            'Unable to create API key.';
        creating.value = false;
        return;
    }

    const data = await response.json();
    newPlainTextToken.value = data.token;
    newTokenName.value = '';
    creating.value = false;
    await loadTokens();
};

const revokeToken = async (id: number) => {
    revokeId.value = id;
    errorMessage.value = null;

    const response = await fetch(`/settings/api-keys/tokens/${id}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: await withAuthHeaders(false),
    });

    if (!response.ok) {
        errorMessage.value = 'Unable to revoke API key.';
        revokeId.value = null;
        return;
    }

    revokeId.value = null;
    await loadTokens();
};

const hasTokens = computed(() => tokens.value.length > 0);

const copyToken = async () => {
    if (!newPlainTextToken.value) return;

    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(newPlainTextToken.value);
        return;
    }

    // Fallback for environments without Clipboard API
    const textarea = document.createElement('textarea');
    textarea.value = newPlainTextToken.value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
};

onMounted(() => {
    loadTokens();
});
</script>

<template>
    <AppLayout>
        <Head title="API Keys" />

        <SettingsLayout>
            <div class="flex flex-col space-y-10">
                <HeadingSmall
                    title="API keys"
                    description="Issue and revoke personal access tokens for programmatic access."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Create new key</CardTitle>
                        <CardDescription>
                            Name your key and copy the token immediately after
                            creation. Tokens are shown only once.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <Label for="token-name">Key name</Label>
                            <Input
                                id="token-name"
                                placeholder="Trading bot"
                                v-model="newTokenName"
                            />
                        </div>
                        <div v-if="newPlainTextToken" class="space-y-2">
                            <Label>New token (copy now)</Label>
                            <div
                                class="flex flex-col gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
                            >
                                <code
                                    class="break-all text-foreground"
                                    data-test="new-api-token"
                                >
                                    {{ newPlainTextToken }}
                                </code>
                                <div class="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        @click="copyToken"
                                    >
                                        Copy
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="newPlainTextToken = null"
                                    >
                                        Hide
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="errorMessage"
                            class="rounded border border-destructive/70 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {{ errorMessage }}
                        </div>
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button :disabled="creating" @click="createToken">
                            {{ creating ? 'Creating…' : 'Create key' }}
                        </Button>
                    </CardFooter>
                </Card>

                <Separator />

                <Card>
                    <CardHeader>
                        <CardTitle>Existing keys</CardTitle>
                        <CardDescription>
                            Active personal access tokens for this account.
                            Revoke any key you no longer use.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="loading" class="text-sm text-muted-foreground">
                            Loading keys…
                        </div>

                        <div
                            v-else-if="!hasTokens"
                            class="rounded border border-dashed border-muted-foreground/30 bg-muted/20 p-4 text-sm text-muted-foreground"
                        >
                            No API keys yet. Create one above to get started.
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-muted-foreground">
                                    <tr class="border-b">
                                        <th class="px-2 py-2 font-medium">Name</th>
                                        <th class="px-2 py-2 font-medium">Abilities</th>
                                        <th class="px-2 py-2 font-medium">Created</th>
                                        <th class="px-2 py-2 font-medium">Last used</th>
                                        <th class="px-2 py-2 font-medium">Expires</th>
                                        <th class="px-2 py-2 font-medium text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="token in tokens"
                                        :key="token.id"
                                        class="border-b last:border-none"
                                    >
                                        <td class="px-2 py-2">{{ token.name }}</td>
                                        <td class="px-2 py-2">
                                            {{ token.abilities?.join(', ') ?? '*' }}
                                        </td>
                                        <td class="px-2 py-2">
                                            {{ new Date(token.created_at).toLocaleString() }}
                                        </td>
                                        <td class="px-2 py-2">
                                            {{
                                                token.last_used_at
                                                    ? new Date(
                                                          token.last_used_at,
                                                      ).toLocaleString()
                                                    : 'Never'
                                            }}
                                        </td>
                                        <td class="px-2 py-2">
                                            {{
                                                token.expires_at
                                                    ? new Date(
                                                          token.expires_at,
                                                      ).toLocaleString()
                                                    : 'No expiry'
                                            }}
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                :disabled="revokeId === token.id"
                                                @click="revokeToken(token.id)"
                                            >
                                                {{
                                                    revokeId === token.id
                                                        ? 'Revoking…'
                                                        : 'Revoke'
                                                }}
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
