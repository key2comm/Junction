<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { billingMonth, date, money } from '@/lib/format';

/** The printable invoice/statement, shared by the admin and the customer portal. */
export type InvoiceDoc = {
    number: string;
    account_code: string;
    account_name: string;
    billing_email: string | null;
    billing_type: string;
    status: string;
    period_start: string;
    period_end: string;
    timezone: string;
    currency: string;
    opening_balance: string;
    usage_total: string;
    payments_total: string;
    adjustments_total: string;
    charges_total: string;
    included_seconds: number;
    closing_balance: string;
    amount_due: string;
    calls: number;
    billsec: number;
    issued_at: string;
    due_at: string | null;
    void_reason: string | null;
    voided_at: string | null;
};

export type InvoiceCharge = { position: number; description: string; quantity: string; unit_price: string; amount: string };

const props = defineProps<{
    invoice: InvoiceDoc;
    lines: { position: number; prefix: string | null; destination: string | null; calls: number; billsec: number; rate: string | null; amount: string }[];
    issuer: { name: string; host: string | null };
    charges?: InvoiceCharge[];
}>();

const m = (v: string) => money(v, props.invoice.currency);
const minutes = (s: number) => (s / 60).toLocaleString(undefined, { maximumFractionDigits: 2 });
// The period end is exclusive: show the last day of the month.
const lastDay = new Date(new Date(props.invoice.period_end).getTime() - 1000).toISOString();
</script>

<template>
<article class="invoice mx-auto ss-maxw-3xl ss-stack-4 rounded border bg-body p-4 ss-print-maxw-none ss-print-border-0 ss-print-p-0">
    <header class="d-flex flex-wrap align-items-start justify-content-between gap-4">
        <div>
            <p class="fs-3 fw-semibold">{{ issuer.name }}</p>
            <p v-if="issuer.host" class="text-secondary">{{ issuer.host }}</p>
        </div>
        <div class="text-end">
            <p class="fs-1 fw-semibold">{{ invoice.billing_type === 'postpaid' ? 'Invoice' : 'Statement' }}</p>
            <p class="font-monospace">{{ invoice.number }}</p>
            <Badge v-if="invoice.status === 'void'" variant="destructive" class="mt-1">VOID</Badge>
        </div>
    </header>

    <p v-if="invoice.status === 'void'" class="rounded border border-danger p-2 text-danger">
        Voided {{ date(invoice.voided_at) }}: {{ invoice.void_reason }}
    </p>

    <section class="ss-grid gap-4 ss-cols-sm-2">
        <div>
            <p class="small text-uppercase text-secondary">Bill to</p>
            <p class="fw-medium">{{ invoice.account_name }}</p>
            <p class="text-secondary">Account {{ invoice.account_code }}</p>
            <p v-if="invoice.billing_email" class="text-secondary">{{ invoice.billing_email }}</p>
        </div>
        <dl class="ss-grid ss-gtc-auto-1fr column-gap-3 row-gap-1 ss-justify-self-sm-end">
            <dt class="text-secondary">Period</dt>
            <dd>{{ billingMonth(invoice.period_start, invoice.timezone) }}</dd>
            <dt class="text-secondary"></dt>
            <dd class="small text-secondary">{{ date(invoice.period_start, invoice.timezone) }} – {{ date(lastDay, invoice.timezone) }} ({{ invoice.timezone }})</dd>
            <dt class="text-secondary">Issued</dt>
            <dd>{{ date(invoice.issued_at, invoice.timezone) }}</dd>
            <template v-if="invoice.due_at">
                <dt class="text-secondary">Due</dt>
                <dd class="fw-medium">{{ date(invoice.due_at, invoice.timezone) }}</dd>
            </template>
        </dl>
    </section>

    <section v-if="charges?.length">
        <h2 class="mb-2 fw-semibold">Plan charges</h2>
        <table class="w-100">
            <thead>
                <tr>
                    <th class="fw-medium">Item</th>
                    <th class="text-end fw-medium">Qty</th>
                    <th class="text-end fw-medium">Price</th>
                    <th class="text-end fw-medium">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in charges" :key="c.position" class="border-bottom ss-border-dashed">
                    <td>{{ c.description }}</td>
                    <td class="text-end tabular-nums">{{ Number(c.quantity).toLocaleString() }}</td>
                    <td class="text-end tabular-nums">{{ m(c.unit_price) }}</td>
                    <td class="text-end tabular-nums">{{ m(c.amount) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="fw-medium">
                    <td class="pt-2" colspan="3">Total plan charges</td>
                    <td class="pt-2 text-end tabular-nums">{{ m(invoice.charges_total) }}</td>
                </tr>
            </tfoot>
        </table>
        <p class="mt-1 small text-secondary">Monthly items are billed in advance, so next month's plan appears on this invoice.</p>
    </section>

    <section>
        <h2 class="mb-2 fw-semibold">Usage by destination</h2>
        <table class="w-100">
            <thead>
                <tr>
                    <th class="fw-medium">Destination</th>
                    <th class="fw-medium">Prefix</th>
                    <th class="text-end fw-medium">Calls</th>
                    <th class="text-end fw-medium">Minutes</th>
                    <th class="text-end fw-medium">Rate/min</th>
                    <th class="text-end fw-medium">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="l in lines" :key="l.position" class="border-bottom ss-border-dashed">
                    <td>{{ l.destination ?? '—' }}</td>
                    <td class="font-monospace">{{ l.prefix }}</td>
                    <td class="text-end tabular-nums">{{ l.calls.toLocaleString() }}</td>
                    <td class="text-end tabular-nums">{{ minutes(l.billsec) }}</td>
                    <td class="text-end tabular-nums">{{ l.rate ? m(l.rate) : '—' }}</td>
                    <td class="text-end tabular-nums">{{ m(l.amount) }}</td>
                </tr>
                <tr v-if="lines.length === 0">
                    <td colspan="6" class="text-center text-secondary">No billable calls in this period.</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="fw-medium">
                    <td class="pt-2" colspan="2">Total usage</td>
                    <td class="pt-2 text-end tabular-nums">{{ invoice.calls.toLocaleString() }}</td>
                    <td class="pt-2 text-end tabular-nums">{{ minutes(invoice.billsec) }}</td>
                    <td></td>
                    <td class="pt-2 text-end tabular-nums">{{ m(invoice.usage_total) }}</td>
                </tr>
            </tfoot>
        </table>
        <p v-if="invoice.included_seconds > 0" class="mt-1 small text-secondary">
            Includes {{ minutes(invoice.included_seconds) }} Canada/USA minutes covered by your plan at no charge.
        </p>
    </section>

    <section class="ms-sm-auto ss-w-sm-80">
        <h2 class="mb-2 fw-semibold">Account summary</h2>
        <dl class="ss-grid ss-gtc-1fr-auto row-gap-1">
            <dt class="text-secondary">Opening balance</dt>
            <dd class="text-end tabular-nums">{{ m(invoice.opening_balance) }}</dd>
            <dt class="text-secondary">Payments received</dt>
            <dd class="text-end tabular-nums">{{ m(invoice.payments_total) }}</dd>
            <template v-if="Number(invoice.adjustments_total)">
                <dt class="text-secondary">Adjustments</dt>
                <dd class="text-end tabular-nums">{{ m(invoice.adjustments_total) }}</dd>
            </template>
            <template v-if="Number(invoice.charges_total)">
                <dt class="text-secondary">Plan charges</dt>
                <dd class="text-end tabular-nums">−{{ m(invoice.charges_total) }}</dd>
            </template>
            <dt class="text-secondary">Usage charges</dt>
            <dd class="text-end tabular-nums">−{{ m(invoice.usage_total) }}</dd>
            <dt class="border-top pt-1 fw-medium">Closing balance</dt>
            <dd class="border-top pt-1 text-end fw-medium tabular-nums" :class="{ 'text-danger': Number(invoice.closing_balance) < 0 }">{{ m(invoice.closing_balance) }}</dd>
            <template v-if="invoice.billing_type === 'postpaid'">
                <dt class="pt-2 fs-4 fw-semibold">Amount due</dt>
                <dd class="pt-2 text-end fs-4 fw-semibold tabular-nums">{{ m(invoice.amount_due) }}</dd>
            </template>
        </dl>
        <p v-if="invoice.billing_type === 'prepaid'" class="mt-2 small text-secondary">Prepaid account: usage and plan charges are deducted from your balance; nothing is due.</p>
    </section>
</article>
</template>

<style>
@media print {
    /* Print only the invoice, whatever the surrounding app layout is. */
    body * {
        visibility: hidden;
    }
    article.invoice,
    article.invoice * {
        visibility: visible;
    }
    article.invoice {
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
    }
    @page {
        margin: 16mm;
    }
}
</style>
