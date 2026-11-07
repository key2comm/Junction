<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date, dateTime } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { index } from '@/routes/api-tokens';

type Token = {
    id: number;
    name: string;
    token_prefix: string;
    abilities: string[];
    account: string | null;
    created_by: string | null;
    last_used_at: string | null;
    last_used_ip: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    created_at: string;
    usable: boolean;
};

const props = defineProps<{
    tokens: Token[];
    abilities: Record<string, string>;
    customerAbilities: string[];
    customers: { id: number; code: string; name: string }[];
    docsUrl: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'API tokens', href: index() }] } });

const page = usePage();
const newToken = computed(() => (page.flash as Record<string, unknown> | undefined)?.newToken as string | undefined);

// A customer-scoped token can only have the customer abilities.
const customer = ref('');
const allowed = computed(() => (customer.value ? props.customerAbilities : Object.keys(props.abilities)));

const copied = ref(false);
async function copy() {
    if (!newToken.value) return;
    try {
        await navigator.clipboard.writeText(newToken.value);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-6">
        <Heading variant="small" title="API tokens" description="Bearer tokens for the REST API at /api/v1" />
        <p class="text-sm text-muted-foreground">
            Send <code class="rounded bg-muted px-1">Authorization: Bearer ss_…</code> with each request. The OpenAPI specification is at
            <a :href="docsUrl" class="font-mono text-foreground hover:underline">{{ docsUrl }}</a>.
        </p>

        <Alert v-if="newToken">
            <AlertTitle>Copy your new token now</AlertTitle>
            <AlertDescription class="space-y-2">
                <p>It won't be shown again. Store it like a password.</p>
                <div class="flex flex-wrap items-center gap-2">
                    <code class="rounded bg-muted px-2 py-1 font-mono text-xs break-all">{{ newToken }}</code>
                    <Button size="sm" variant="outline" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</Button>
                </div>
            </AlertDescription>
        </Alert>

        <div class="space-y-2">
            <div v-for="t in tokens" :key="t.id" class="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-3 text-sm" :class="{ 'opacity-60': !t.usable }">
                <div class="space-y-1">
                    <p class="font-medium">
                        {{ t.name }}
                        <span class="font-mono text-xs text-muted-foreground">{{ t.token_prefix }}…</span>
                        <Badge v-if="t.account" variant="outline" class="ml-1">customer {{ t.account }}</Badge>
                        <Badge v-if="t.revoked_at" variant="destructive" class="ml-1">revoked</Badge>
                        <Badge v-else-if="!t.usable" variant="secondary" class="ml-1">expired</Badge>
                    </p>
                    <p class="flex flex-wrap gap-1">
                        <Badge v-for="a in t.abilities" :key="a" variant="secondary" class="font-mono">{{ a }}</Badge>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Created {{ date(t.created_at) }}{{ t.created_by ? ` by ${t.created_by}` : '' }} ·
                        {{ t.last_used_at ? `last used ${dateTime(t.last_used_at)} from ${t.last_used_ip}` : 'never used' }}
                        <span v-if="t.expires_at"> · expires {{ date(t.expires_at) }}</span>
                    </p>
                </div>
                <Form v-if="!t.revoked_at" v-bind="ApiTokenController.destroy.form(t.id)" v-slot="{ processing }">
                    <Button size="sm" variant="outline" :disabled="processing">Revoke</Button>
                </Form>
            </div>
            <p v-if="tokens.length === 0" class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">No tokens yet.</p>
        </div>
    </div>

    <div class="space-y-4">
        <Heading variant="small" title="Create a token" />
        <Form v-bind="ApiTokenController.store.form()" class="space-y-4" v-slot="{ errors, processing }">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" required placeholder="Billing integration" />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1">
                    <Label for="expires_in_days">Expires after (days)</Label>
                    <Input id="expires_in_days" name="expires_in_days" type="number" min="1" max="3650" placeholder="Never" />
                    <InputError :message="errors.expires_in_days" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="account_id">Limit to one customer</Label>
                    <select id="account_id" v-model="customer" name="account_id" :class="selectClass">
                        <option value="">No: full admin API access (as granted below)</option>
                        <option v-for="c in customers" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}: only their own data</option>
                    </select>
                    <InputError :message="errors.account_id" />
                </div>
            </div>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Abilities</legend>
                <label v-for="(label, key) in abilities" :key="key" class="flex items-start gap-2 text-sm" :class="{ 'opacity-40': !allowed.includes(String(key)) }">
                    <input type="checkbox" name="abilities[]" :value="key" class="mt-0.5 size-4" :disabled="!allowed.includes(String(key))" />
                    <span><code class="font-mono">{{ key }}</code> <span class="text-muted-foreground">— {{ label }}</span></span>
                </label>
                <InputError :message="errors.abilities" />
            </fieldset>
            <Button :disabled="processing">Create token</Button>
        </Form>
    </div>
</template>
