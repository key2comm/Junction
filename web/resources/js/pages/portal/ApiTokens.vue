<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PortalController from '@/actions/App/Http/Controllers/Portal/PortalController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date, dateTime } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { index } from '@/routes/portal/api-tokens';

type Token = {
    id: number;
    name: string;
    token_prefix: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    created_at: string;
    usable: boolean;
};

const props = defineProps<{
    tokens: Token[];
    abilities: Record<string, string>;
    docsUrl: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'API tokens', href: index() }] } });

const page = usePage();
const newToken = computed(() => (page.flash as Record<string, unknown> | undefined)?.newToken as string | undefined);


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

    <div class="space-y-6 p-4">
        <Heading title="API tokens" description="Read your account, calls and invoices from your own systems" />
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
                        <Badge v-if="t.revoked_at" variant="destructive" class="ml-1">revoked</Badge>
                        <Badge v-else-if="!t.usable" variant="secondary" class="ml-1">expired</Badge>
                    </p>
                    <p class="flex flex-wrap gap-1">
                        <Badge v-for="a in t.abilities" :key="a" variant="secondary" class="font-mono">{{ a }}</Badge>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Created {{ date(t.created_at) }} ·
                        {{ t.last_used_at ? `last used ${dateTime(t.last_used_at)}` : 'never used' }}
                        <span v-if="t.expires_at"> · expires {{ date(t.expires_at) }}</span>
                    </p>
                </div>
                <Form v-if="!t.revoked_at" v-bind="PortalController.destroyToken.form(t.id)" v-slot="{ processing }">
                    <Button size="sm" variant="outline" :disabled="processing">Revoke</Button>
                </Form>
            </div>
            <p v-if="tokens.length === 0" class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">No tokens yet.</p>
        </div>
    </div>

    <div class="space-y-4 p-4">
        <Heading variant="small" title="Create a token" />
        <Form v-bind="PortalController.storeToken.form()" class="space-y-4" v-slot="{ errors, processing }">
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
            </div>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Abilities</legend>
                <label v-for="(label, key) in abilities" :key="key" class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="abilities[]" :value="key" class="mt-0.5 size-4" checked />
                    <span><code class="font-mono">{{ key }}</code> <span class="text-muted-foreground">— {{ label }}</span></span>
                </label>
                <InputError :message="errors.abilities" />
            </fieldset>
            <Button :disabled="processing">Create token</Button>
        </Form>
    </div>
</template>
