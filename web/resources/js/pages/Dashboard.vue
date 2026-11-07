<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { date, money } from '@/lib/format';
import { dashboard } from '@/routes';
import { show as showAccount } from '@/routes/accounts';
import { index as alertsIndex } from '@/routes/alerts';
import { index as cdrIndex } from '@/routes/cdrs';
import { edit as editEmail } from '@/routes/email';
import { show as showInvoice } from '@/routes/invoices';
import { show as showVendorInvoice } from '@/routes/vendor-invoices';

defineProps<{
    last24h: { calls: number; answered: number; rejected: number; asr: number | null; acd: number | null; minutes: number; sell: string; buy: string; margin: string };
    activeCalls: number;
    lowBalance: { id: number; code: string; name: string; currency: string; low_balance_threshold: string; low_balance_alerted_at: string; available: string }[];
    unpaid: { id: number; number: string; account_code: string; account_name: string; currency: string; amount_due: string; due_at: string | null }[];
    disputes: { id: number; reference: string; status: string; currency: string; vendor: string; difference: string }[];
    alerts: { id: number; type: string; severity: string; status: string; title: string; action_taken: string | null; opened_at: string }[];
    mailConfigured: boolean;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] } });

const duration = (s: number | null) => (s === null ? '—' : s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
const overdue = (iso: string | null) => iso !== null && new Date(iso) < new Date();
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-8 p-4">
        <p v-if="!mailConfigured" class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
            Email isn't set up, so invoices and low-balance alerts can't be sent.
            <Link :href="editEmail()" class="font-medium text-foreground hover:underline">Set up email</Link>
        </p>

        <section v-if="alerts.length" class="space-y-2">
            <div class="flex items-baseline justify-between">
                <Heading variant="small" title="Alerts" />
                <Link :href="alertsIndex()" class="text-sm hover:underline">All alerts →</Link>
            </div>
            <ul class="divide-y rounded-lg border text-sm" :class="{ 'border-destructive': alerts.some((a) => a.status === 'open' && a.severity === 'critical') }">
                <li v-for="a in alerts" :key="a.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                    <Badge :variant="a.severity === 'critical' ? 'destructive' : 'secondary'">{{ a.status }}</Badge>
                    <span>{{ a.title }}</span>
                    <span v-if="a.action_taken" class="text-destructive">· {{ a.action_taken }}</span>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div class="flex items-baseline justify-between">
                <Heading variant="small" title="Last 24 hours" />
                <Link :href="cdrIndex()" class="text-sm hover:underline">Call records →</Link>
            </div>
            <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">Calls in progress</p>
                    <p class="text-xl font-semibold tabular-nums">{{ activeCalls }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">Calls</p>
                    <p class="text-xl font-semibold tabular-nums">{{ last24h.calls.toLocaleString() }}</p>
                    <p class="text-xs text-muted-foreground">{{ last24h.rejected.toLocaleString() }} rejected</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">ASR</p>
                    <p class="text-xl font-semibold tabular-nums">{{ last24h.asr === null ? '—' : `${last24h.asr}%` }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">ACD</p>
                    <p class="text-xl font-semibold tabular-nums">{{ duration(last24h.acd) }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">Minutes</p>
                    <p class="text-xl font-semibold tabular-nums">{{ last24h.minutes.toLocaleString() }}</p>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="text-xs text-muted-foreground">Margin</p>
                    <p class="text-xl font-semibold tabular-nums" :class="{ 'text-destructive': Number(last24h.margin) < 0 }">{{ money(last24h.margin, currency) }}</p>
                    <p class="text-xs text-muted-foreground">on {{ money(last24h.sell, currency) }} sold</p>
                </div>
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-3">
            <section class="space-y-3">
                <Heading variant="small" title="Low balance" description="Customers below their alert level right now" />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="a in lowBalance" :key="a.id" class="flex justify-between gap-2 px-3 py-2">
                        <Link :href="showAccount(a.id)" class="hover:underline">{{ a.name }} <span class="text-muted-foreground">{{ a.code }}</span></Link>
                        <span class="tabular-nums" :class="{ 'text-destructive': Number(a.available) <= 0 }">{{ money(a.available, a.currency) }}</span>
                    </li>
                    <li v-if="lowBalance.length === 0" class="px-3 py-6 text-center text-muted-foreground">Nobody is low.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <Heading variant="small" title="Unpaid invoices" description="Postpaid amounts due" />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="i in unpaid" :key="i.id" class="flex flex-wrap justify-between gap-2 px-3 py-2">
                        <span>
                            <Link :href="showInvoice(i.id)" class="font-mono hover:underline">{{ i.number }}</Link>
                            <span class="ml-1 text-muted-foreground">{{ i.account_code }}</span>
                            <Badge v-if="overdue(i.due_at)" variant="destructive" class="ml-1">overdue</Badge>
                        </span>
                        <span class="tabular-nums">{{ money(i.amount_due, i.currency) }} <span class="text-xs text-muted-foreground">due {{ date(i.due_at) }}</span></span>
                    </li>
                    <li v-if="unpaid.length === 0" class="px-3 py-6 text-center text-muted-foreground">No amounts due.</li>
                </ul>
            </section>

            <section class="space-y-3">
                <Heading variant="small" title="Open vendor invoices" description="Waiting to be accepted or in dispute" />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="d in disputes" :key="d.id" class="flex justify-between gap-2 px-3 py-2">
                        <span>
                            <Link :href="showVendorInvoice(d.id)" class="font-mono hover:underline">{{ d.reference }}</Link>
                            <span class="ml-1 text-muted-foreground">{{ d.vendor }}</span>
                            <Badge :variant="d.status === 'disputed' ? 'destructive' : 'secondary'" class="ml-1">{{ d.status }}</Badge>
                        </span>
                        <span class="tabular-nums" :class="{ 'text-destructive': Number(d.difference) > 0 }">{{ money(d.difference, d.currency) }}</span>
                    </li>
                    <li v-if="disputes.length === 0" class="px-3 py-6 text-center text-muted-foreground">Nothing open.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
