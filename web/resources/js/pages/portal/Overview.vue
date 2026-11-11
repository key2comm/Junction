<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, CalendarDays, PhoneCall, PhoneIncoming } from '@lucide/vue';
import CallBars from '@/components/dashboard/CallBars.vue';
import CallLegend from '@/components/dashboard/CallLegend.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { useBilling } from '@/composables/useBilling';
import { dayBars, hourBars } from '@/lib/callBars';
import { billingMonth, date, money, titleCase } from '@/lib/format';
import { home } from '@/routes/portal';
import { index as calls } from '@/routes/portal/calls';
import { show as showInvoice } from '@/routes/portal/invoices';

type Counts = { calls: number; answered: number; minutes: number };

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
    activeCalls: number;
    today: Counts;
    month: Counts;
    week: { day: string; calls: number; answered: number }[];
    hours: { hour: string; calls: number; answered: number }[];
    last24h: { calls: number; answered: number; rejected: number; asr: number | null; acd: number | null; minutes: number; sell: string };
    payments: { id: number; type: string; amount: string; description: string; effective_at: string }[];
    invoices: { id: number; number: string; period_start: string; timezone: string; currency: string; usage_total: string; amount_due: string; due_at: string | null }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Overview', href: home() }] } });

const billing = useBilling();
const m = (v: string) => money(v, props.account.currency);
const duration = (s: number | null) => (s === null ? '—' : s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
const n = (v: number) => v.toLocaleString();
const pct = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 100) : 0);

// Call figures refresh on their own.
usePoll(30000, { only: ['activeCalls', 'today', 'month', 'week', 'hours', 'last24h'] });

const weekBars = computed(() => dayBars(props.week));
const hourChart = computed(() => hourBars(props.hours));
const weekTotal = computed(() => props.week.reduce((t, d) => t + d.calls, 0));
const failed24h = computed(() => props.last24h.calls - props.last24h.answered);
</script>

<template>
    <Head title="Overview" />

    <div class="ss-stack-4">
        <div class="ss-stack-1">
            <Heading :title="account.name" :description="`Account ${account.code}`" />
            <div class="d-flex flex-wrap gap-1">
                <Badge :variant="account.status === 'active' ? 'default' : 'destructive'">{{ titleCase(account.status) }}</Badge>
                <Badge v-if="billing" variant="secondary">{{ titleCase(account.billing_type) }}</Badge>
            </div>
        </div>

        <p v-if="account.status !== 'active'" class="rounded border border-danger p-2 text-danger">
            Your account is {{ account.status }}: calls are not being accepted. Please contact us.
        </p>
        <p v-else-if="billing && account.low_balance_alerted_at" class="rounded border border-danger p-2 text-danger">
            Your available funds are below {{ m(account.low_balance_threshold ?? '0') }}. Calls are refused when funds run out.
        </p>

        <p v-if="!billing" class="text-secondary">
            Limits: {{ account.max_channels ?? 'unlimited' }} concurrent calls · {{ account.max_cps ?? 'unlimited' }} calls/second
        </p>
        <div v-else class="ss-grid gap-3 ss-cols-sm-3">
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

        <!-- Headline numbers -->
        <div class="ss-grid gap-3 ss-cols-sm-2 ss-cols-xl-4">
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-green-lt"><Activity class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">Calls in progress</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ n(activeCalls) }}</p>
                        <p v-if="account.max_channels" class="small text-secondary mb-0">of {{ n(account.max_channels) }} allowed</p>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-primary-lt"><PhoneCall class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">Calls today</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ n(today.calls) }}</p>
                        <p class="small text-secondary mb-0">{{ n(today.answered) }} answered · {{ n(today.minutes) }} min</p>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-azure-lt"><CalendarDays class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">Calls this month</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ n(month.calls) }}</p>
                        <p class="small text-secondary mb-0">{{ n(month.answered) }} answered · {{ n(month.minutes) }} min</p>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-purple-lt"><PhoneIncoming class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1" title="Answer Seizure Ratio: the share of calls that were answered">ASR, last 24 hours</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ last24h.asr === null ? '—' : `${last24h.asr}%` }}</p>
                        <p class="small text-secondary mb-0" title="Average Call Duration of answered calls">ACD {{ duration(last24h.acd) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Last 24 hours -->
        <section class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Last 24 hours</h3>
                    <p class="card-subtitle mb-0"><Link :href="calls()">All calls →</Link></p>
                </div>
                <CallLegend class="card-actions" />
            </div>
            <div class="card-body ss-stack-3">
                <div class="ss-grid gap-3 ss-cols-2 ss-cols-md-4">
                    <div class="rounded border p-2">
                        <p class="small text-secondary mb-1">Total calls</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-0">{{ n(last24h.calls) }}</p>
                    </div>
                    <div class="rounded border p-2">
                        <p class="small text-secondary mb-1">Answered</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-1">{{ n(last24h.answered) }}</p>
                        <p class="small text-secondary mb-0">{{ pct(last24h.answered, last24h.calls) }}% of calls · {{ n(last24h.minutes) }} min</p>
                    </div>
                    <div class="rounded border p-2">
                        <p class="small text-secondary mb-1">Failed</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-1">{{ n(failed24h) }}</p>
                        <p class="small text-secondary mb-0">{{ n(last24h.rejected) }} refused before routing</p>
                    </div>
                    <div v-if="billing" class="rounded border p-2">
                        <p class="small text-secondary mb-1">Spend</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-0">{{ m(last24h.sell) }}</p>
                    </div>
                    <div v-else class="rounded border p-2">
                        <p class="small text-secondary mb-1">ACD</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-0">{{ duration(last24h.acd) }}</p>
                    </div>
                </div>
                <CallBars :items="hourChart" title="Calls per hour, last 24 hours" :height="180" :label-every="3" />
            </div>
        </section>

        <!-- Last 7 days -->
        <section class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Calls, last 7 days</h3>
                    <p class="card-subtitle mb-0">{{ n(weekTotal) }} calls</p>
                </div>
                <CallLegend class="card-actions" />
            </div>
            <div class="card-body">
                <CallBars :items="weekBars" title="Calls per day, last 7 days" />
            </div>
        </section>

        <div v-if="billing" class="ss-grid gap-4 ss-cols-lg-2">
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
