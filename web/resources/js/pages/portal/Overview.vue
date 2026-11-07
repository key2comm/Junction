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

    <div class="ss-stack-4">
        <div class="ss-stack-1">
            <Heading :title="account.name" :description="`Account ${account.code}`" />
            <div class="d-flex flex-wrap gap-1">
                <Badge :variant="account.status === 'active' ? 'default' : 'destructive'">{{ titleCase(account.status) }}</Badge>
                <Badge variant="secondary">{{ titleCase(account.billing_type) }}</Badge>
            </div>
        </div>

        <p v-if="account.status !== 'active'" class="rounded border border-danger p-2 text-danger">
            Your account is {{ account.status }}: calls are not being accepted. Please contact us.
        </p>
        <p v-else-if="account.low_balance_alerted_at" class="rounded border border-danger p-2 text-danger">
            Your available funds are below {{ m(account.low_balance_threshold ?? '0') }}. Calls are refused when funds run out.
        </p>

        <div class="ss-grid gap-3 ss-cols-sm-3">
            <div class="card card-body">
                <p class="small text-secondary">Available for calls</p>
                <p class="fs-1 fw-semibold tabular-nums" :class="{ 'text-danger': Number(account.available_now) <= 0 }">{{ m(account.available_now) }}</p>
                <p class="small text-secondary">Includes calls in progress and recent usage</p>
            </div>
            <div class="card card-body">
                <p class="small text-secondary">Balance</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ m(account.balance) }}</p>
            </div>
            <div class="card card-body">
                <p class="small text-secondary">Credit limit</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ m(account.credit_limit) }}</p>
                <p class="small text-secondary">
                    {{ account.max_channels ?? 'Unlimited' }} concurrent calls · {{ account.max_cps ?? 'unlimited' }} calls/second
                </p>
            </div>
        </div>

        <section class="ss-stack-2">
            <div class="d-flex align-items-baseline justify-content-between">
                <Heading variant="small" title="Last 24 hours" />
                <Link :href="calls()">All calls →</Link>
            </div>
            <div class="ss-grid gap-3 ss-cols-sm-5">
                <div class="card card-body p-2"><p class="small text-secondary">Calls</p><p class="fs-2 fw-semibold tabular-nums">{{ last24h.calls.toLocaleString() }}</p></div>
                <div class="card card-body p-2"><p class="small text-secondary">Answered</p><p class="fs-2 fw-semibold tabular-nums">{{ last24h.answered.toLocaleString() }}</p></div>
                <div class="card card-body p-2"><p class="small text-secondary">ACD</p><p class="fs-2 fw-semibold tabular-nums">{{ duration(last24h.acd) }}</p></div>
                <div class="card card-body p-2"><p class="small text-secondary">Minutes</p><p class="fs-2 fw-semibold tabular-nums">{{ last24h.minutes.toLocaleString() }}</p></div>
                <div class="card card-body p-2"><p class="small text-secondary">Spend</p><p class="fs-2 fw-semibold tabular-nums">{{ m(last24h.sell) }}</p></div>
            </div>
        </section>

        <div class="ss-grid gap-4 ss-cols-lg-2">
            <section class="ss-stack-2">
                <Heading variant="small" title="Recent payments and credits" />
                <ul class="list-group">
                    <li v-for="p in payments" :key="p.id" class="list-group-item d-flex justify-content-between gap-2 px-2 py-2">
                        <span><span class="text-secondary">{{ date(p.effective_at, account.timezone) }}</span> {{ p.description }}</span>
                        <span class="tabular-nums">{{ m(p.amount) }}</span>
                    </li>
                    <li v-if="payments.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">None yet.</li>
                </ul>
            </section>
            <section class="ss-stack-2">
                <Heading variant="small" title="Latest invoices" />
                <ul class="list-group">
                    <li v-for="i in invoices" :key="i.id" class="list-group-item d-flex justify-content-between gap-2 px-2 py-2">
                        <span><Link :href="showInvoice(i.id)" class="font-monospace">{{ i.number }}</Link> <span class="text-secondary">{{ billingMonth(i.period_start, i.timezone) }}</span></span>
                        <span class="tabular-nums">{{ Number(i.amount_due) ? `${money(i.amount_due, i.currency)} due ${date(i.due_at)}` : money(i.usage_total, i.currency) }}</span>
                    </li>
                    <li v-if="invoices.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">No invoices yet.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
