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

    <div class="ss-stack-4">
        <p v-if="!mailConfigured" class="rounded border ss-border-dashed p-2 text-secondary">
            Email isn't set up, so invoices and low-balance alerts can't be sent.
            <Link :href="editEmail()" class="fw-medium text-body">Set up email</Link>
        </p>

        <section v-if="alerts.length" class="ss-stack-2">
            <div class="d-flex align-items-baseline justify-content-between">
                <Heading variant="small" title="Alerts" />
                <Link :href="alertsIndex()">All alerts →</Link>
            </div>
            <ul class="list-group" :class="{ 'border-danger': alerts.some((a) => a.status === 'open' && a.severity === 'critical') }">
                <li v-for="a in alerts" :key="a.id" class="d-flex flex-wrap align-items-center gap-2 px-2 py-2">
                    <Badge :variant="a.severity === 'critical' ? 'destructive' : 'secondary'">{{ a.status }}</Badge>
                    <span>{{ a.title }}</span>
                    <span v-if="a.action_taken" class="text-danger">· {{ a.action_taken }}</span>
                </li>
            </ul>
        </section>

        <section class="ss-stack-2">
            <div class="d-flex align-items-baseline justify-content-between">
                <Heading variant="small" title="Last 24 hours" />
                <Link :href="cdrIndex()">Call records →</Link>
            </div>
            <div class="ss-grid gap-3 ss-cols-sm-3 ss-cols-lg-6">
                <div class="card card-body p-2">
                    <p class="small text-secondary">Calls in progress</p>
                    <p class="fs-2 fw-semibold tabular-nums">{{ activeCalls }}</p>
                </div>
                <div class="card card-body p-2">
                    <p class="small text-secondary">Calls</p>
                    <p class="fs-2 fw-semibold tabular-nums">{{ last24h.calls.toLocaleString() }}</p>
                    <p class="small text-secondary">{{ last24h.rejected.toLocaleString() }} rejected</p>
                </div>
                <div class="card card-body p-2">
                    <p class="small text-secondary">ASR</p>
                    <p class="fs-2 fw-semibold tabular-nums">{{ last24h.asr === null ? '—' : `${last24h.asr}%` }}</p>
                </div>
                <div class="card card-body p-2">
                    <p class="small text-secondary">ACD</p>
                    <p class="fs-2 fw-semibold tabular-nums">{{ duration(last24h.acd) }}</p>
                </div>
                <div class="card card-body p-2">
                    <p class="small text-secondary">Minutes</p>
                    <p class="fs-2 fw-semibold tabular-nums">{{ last24h.minutes.toLocaleString() }}</p>
                </div>
                <div class="card card-body p-2">
                    <p class="small text-secondary">Margin</p>
                    <p class="fs-2 fw-semibold tabular-nums" :class="{ 'text-danger': Number(last24h.margin) < 0 }">{{ money(last24h.margin, currency) }}</p>
                    <p class="small text-secondary">on {{ money(last24h.sell, currency) }} sold</p>
                </div>
            </div>
        </section>

        <div class="ss-grid gap-4 ss-cols-lg-3">
            <section class="ss-stack-2">
                <Heading variant="small" title="Low balance" description="Customers below their alert level right now" />
                <ul class="list-group">
                    <li v-for="a in lowBalance" :key="a.id" class="d-flex justify-content-between gap-2 px-2 py-2">
                        <Link :href="showAccount(a.id)">{{ a.name }} <span class="text-secondary">{{ a.code }}</span></Link>
                        <span class="tabular-nums" :class="{ 'text-danger': Number(a.available) <= 0 }">{{ money(a.available, a.currency) }}</span>
                    </li>
                    <li v-if="lowBalance.length === 0" class="px-2 py-4 text-center text-secondary">Nobody is low.</li>
                </ul>
            </section>

            <section class="ss-stack-2">
                <Heading variant="small" title="Unpaid invoices" description="Postpaid amounts due" />
                <ul class="list-group">
                    <li v-for="i in unpaid" :key="i.id" class="d-flex flex-wrap justify-content-between gap-2 px-2 py-2">
                        <span>
                            <Link :href="showInvoice(i.id)" class="font-monospace">{{ i.number }}</Link>
                            <span class="ms-1 text-secondary">{{ i.account_code }}</span>
                            <Badge v-if="overdue(i.due_at)" variant="destructive" class="ms-1">overdue</Badge>
                        </span>
                        <span class="tabular-nums">{{ money(i.amount_due, i.currency) }} <span class="small text-secondary">due {{ date(i.due_at) }}</span></span>
                    </li>
                    <li v-if="unpaid.length === 0" class="px-2 py-4 text-center text-secondary">No amounts due.</li>
                </ul>
            </section>

            <section class="ss-stack-2">
                <Heading variant="small" title="Open vendor invoices" description="Waiting to be accepted or in dispute" />
                <ul class="list-group">
                    <li v-for="d in disputes" :key="d.id" class="d-flex justify-content-between gap-2 px-2 py-2">
                        <span>
                            <Link :href="showVendorInvoice(d.id)" class="font-monospace">{{ d.reference }}</Link>
                            <span class="ms-1 text-secondary">{{ d.vendor }}</span>
                            <Badge :variant="d.status === 'disputed' ? 'destructive' : 'secondary'" class="ms-1">{{ d.status }}</Badge>
                        </span>
                        <span class="tabular-nums" :class="{ 'text-danger': Number(d.difference) > 0 }">{{ money(d.difference, d.currency) }}</span>
                    </li>
                    <li v-if="disputes.length === 0" class="px-2 py-4 text-center text-secondary">Nothing open.</li>
                </ul>
            </section>
        </div>
    </div>
</template>
