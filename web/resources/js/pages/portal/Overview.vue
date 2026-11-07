<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { billingMonth, date, money, titleCase } from '@/lib/format';
import { home } from '@/routes/portal';
import { index as calls } from '@/routes/portal/calls';
import { show as showInvoice } from '@/routes/portal/invoices';

const props = defineProps<{
    account: {
        code: string;
        name: string;
        currency: string;
        balance: string;
        credit_limit: string;
        available_now: string;
        status: string;
        billing_type: string;
        timezone: string;
        low_balance_threshold: string | null;
        low_balance_alerted_at: string | null;
        max_channels: number | null;
        max_cps: string | null;
    };
    last24h: { calls: number; answered: number; asr: number | null; acd: number | null; minutes: number; sell: string };
    payments: { id: number; type: string; amount: string; description: string; effective_at: string }[];
    invoices: { id: number; number: string; period_start: string; timezone: string; currency: string; usage_total: string; amount_due: string; due_at: string | null }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Overview', href: home() }] } });

const m = (v: string) => money(v, props.account.currency);
const duration = (s: number | null) => (s === null ? '—' : s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
</script>

<template>
    <Head title="Overview" />

    <div class="space-y-8 p-4">
        <div class="space-y-1">
            <Heading :title="account.name" :description="`Account ${account.code}`" />
            <div class="flex flex-wrap gap-1">
                <Badge :variant="account.status === 'active' ? 'default' : 'destructive'">{{ titleCase(account.status) }}</Badge>
                <Badge variant="secondary">{{ titleCase(account.billing_type) }}</Badge>
            </div>
        </div>

        <p v-if="account.status !== 'active'" class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive">
            Your account is {{ account.status }}: calls are not being accepted. Please contact us.
        </p>
        <p v-else-if="account.low_balance_alerted_at" class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive">
            Your available funds are below {{ m(account.low_balance_threshold ?? '0') }}. Calls are refused when funds run out.
        </p>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <p class="text-xs text-muted-foreground">Available for calls</p>
                <p class="text-2xl font-semibold tabular-nums" :class="{ 'text-destructive': Number(account.available_now) <= 0 }">{{ m(account.available_now) }}</p>
                <p class="text-xs text-muted-foreground">Includes calls in progress and recent usage</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-xs text-muted-foreground">Balance</p>
                <p class="text-2xl font-semibold tabular-nums">{{ m(account.balance) }}</p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-xs text-muted-foreground">Credit limit</p>
                <p class="text-2xl font-semibold tabular-nums">{{ m(account.credit_limit) }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ account.max_channels ?? 'Unlimited' }} concurrent calls · {{ account.max_cps ?? 'unlimited' }} calls/second
                </p>
            </div>
        </div>

        <section class="space-y-3">
            <div class="flex items-baseline justify-between">
                <Heading variant="small" title="Last 24 hours" />
                <Link :href="calls()" class="text-sm hover:underline">All calls →</Link>
            </div>
            <div class="grid gap-4 sm:grid-cols-5">
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Calls</p><p class="text-xl font-semibold tabular-nums">{{ last24h.calls.toLocaleString() }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Answered</p><p class="text-xl font-semibold tabular-nums">{{ last24h.answered.toLocaleString() }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">ACD</p><p class="text-xl font-semibold tabular-nums">{{ duration(last24h.acd) }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Minutes</p><p class="text-xl font-semibold tabular-nums">{{ last24h.minutes.toLocaleString() }}</p></div>
                <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Spend</p><p class="text-xl font-semibold tabular-nums">{{ m(last24h.sell) }}</p></div>
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-2">
            <section class="space-y-3">
                <Heading variant="small" title="Recent payments and credits" />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="p in payments" :key="p.id" class="flex justify-between gap-2 px-3 py-2">
                        <span><span class="text-muted-foreground">{{ date(p.effective_at, account.timezone) }}</span> {{ p.description }}</span>
                        <span class="tabular-nums">{{ m(p.amount) }}</span>
                    </li>
                    <li v-if="payments.length === 0" class="px-3 py-6 text-center text-muted-foreground">None yet.</li>
                </ul>
            </section>
            <section class="space-y-3">
                <Heading variant="small" title="Latest invoices" />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="i in invoices" :key="i.id" class="flex justify-between gap-2 px-3 py-2">
                        <span><Link :href="showInvoice(i.id)" class="font-mono hover:underline">{{ i.number }}</Link> <span class="text-muted-foreground">{{ billingMonth(i.period_start, i.timezone) }}</span></span>
                        <span class="tabular-nums">{{ Number(i.amount_due) ? `${money(i.amount_due, i.currency)} due ${date(i.due_at)}` : money(i.usage_total, i.currency) }}</span>
                    </li>
                    <li v-if="invoices.length === 0" class="px-3 py-6 text-center text-muted-foreground">No invoices yet.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
