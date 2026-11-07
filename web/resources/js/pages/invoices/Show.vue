<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import InvoiceController from '@/actions/App/Http/Controllers/Admin/InvoiceController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { billingMonth, date, money } from '@/lib/format';
import { show as showAccount } from '@/routes/accounts';
import { csv, index, show } from '@/routes/invoices';

type Invoice = {
    id: number;
    number: string;
    account_id: number;
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
    closing_balance: string;
    amount_due: string;
    calls: number;
    billsec: number;
    issued_at: string;
    due_at: string | null;
    void_reason: string | null;
    voided_at: string | null;
};

const props = defineProps<{
    invoice: Invoice;
    lines: { position: number; prefix: string | null; destination: string | null; calls: number; billsec: number; rate: string | null; amount: string }[];
    issuer: { name: string; host: string | null };
}>();

defineOptions({
    layout: (props: { invoice: { id: number; number: string } }) => ({
        breadcrumbs: [
            { title: 'Invoices', href: index() },
            { title: props.invoice.number, href: show(props.invoice.id) },
        ],
    }),
});

const inv = props.invoice;
const m = (v: string) => money(v, inv.currency);
const minutes = (s: number) => (s / 60).toLocaleString(undefined, { maximumFractionDigits: 2 });
// The period end is exclusive: show the last day of the month.
const lastDay = new Date(new Date(inv.period_end).getTime() - 1000).toISOString();
const print = () => window.print();
</script>

<template>
    <Head :title="invoice.number" />

    <div class="space-y-4 p-4 print:p-0">
        <!-- Actions (not printed) -->
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <Link :href="showAccount(invoice.account_id)" class="text-sm hover:underline">← {{ invoice.account_name }}</Link>
            <div class="flex gap-2">
                <Button variant="outline" @click="print"><Printer class="size-4" /> Print / Save as PDF</Button>
                <Button variant="outline" as-child><a :href="csv.url(invoice.id)"><Download class="size-4" /> CSV</a></Button>
            </div>
        </div>

        <article class="invoice mx-auto max-w-3xl space-y-8 rounded-lg border bg-background p-8 text-sm print:max-w-none print:rounded-none print:border-0 print:p-0">
            <header class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <p class="text-lg font-semibold">{{ issuer.name }}</p>
                    <p v-if="issuer.host" class="text-muted-foreground">{{ issuer.host }}</p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-semibold">{{ invoice.billing_type === 'postpaid' ? 'Invoice' : 'Statement' }}</p>
                    <p class="font-mono">{{ invoice.number }}</p>
                    <Badge v-if="invoice.status === 'void'" variant="destructive" class="mt-1">VOID</Badge>
                </div>
            </header>

            <p v-if="invoice.status === 'void'" class="rounded-md border border-destructive/50 p-3 text-destructive">
                Voided {{ date(invoice.voided_at) }}: {{ invoice.void_reason }}
            </p>

            <section class="grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs uppercase text-muted-foreground">Bill to</p>
                    <p class="font-medium">{{ invoice.account_name }}</p>
                    <p class="text-muted-foreground">Account {{ invoice.account_code }}</p>
                    <p v-if="invoice.billing_email" class="text-muted-foreground">{{ invoice.billing_email }}</p>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 sm:justify-self-end">
                    <dt class="text-muted-foreground">Period</dt>
                    <dd>{{ billingMonth(invoice.period_start, invoice.timezone) }}</dd>
                    <dt class="text-muted-foreground"></dt>
                    <dd class="text-xs text-muted-foreground">{{ date(invoice.period_start, invoice.timezone) }} – {{ date(lastDay, invoice.timezone) }} ({{ invoice.timezone }})</dd>
                    <dt class="text-muted-foreground">Issued</dt>
                    <dd>{{ date(invoice.issued_at, invoice.timezone) }}</dd>
                    <template v-if="invoice.due_at">
                        <dt class="text-muted-foreground">Due</dt>
                        <dd class="font-medium">{{ date(invoice.due_at, invoice.timezone) }}</dd>
                    </template>
                </dl>
            </section>

            <section>
                <h2 class="mb-2 font-semibold">Usage by destination</h2>
                <table class="w-full">
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="py-1.5 font-medium">Destination</th>
                            <th class="py-1.5 font-medium">Prefix</th>
                            <th class="py-1.5 text-right font-medium">Calls</th>
                            <th class="py-1.5 text-right font-medium">Minutes</th>
                            <th class="py-1.5 text-right font-medium">Rate/min</th>
                            <th class="py-1.5 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in lines" :key="l.position" class="border-b border-dashed">
                            <td class="py-1">{{ l.destination ?? '—' }}</td>
                            <td class="py-1 font-mono">{{ l.prefix }}</td>
                            <td class="py-1 text-right tabular-nums">{{ l.calls.toLocaleString() }}</td>
                            <td class="py-1 text-right tabular-nums">{{ minutes(l.billsec) }}</td>
                            <td class="py-1 text-right tabular-nums">{{ l.rate ? m(l.rate) : '—' }}</td>
                            <td class="py-1 text-right tabular-nums">{{ m(l.amount) }}</td>
                        </tr>
                        <tr v-if="lines.length === 0">
                            <td colspan="6" class="py-4 text-center text-muted-foreground">No billable calls in this period.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="font-medium">
                            <td class="pt-2" colspan="2">Total usage</td>
                            <td class="pt-2 text-right tabular-nums">{{ invoice.calls.toLocaleString() }}</td>
                            <td class="pt-2 text-right tabular-nums">{{ minutes(invoice.billsec) }}</td>
                            <td></td>
                            <td class="pt-2 text-right tabular-nums">{{ m(invoice.usage_total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <section class="sm:ml-auto sm:w-80">
                <h2 class="mb-2 font-semibold">Account summary</h2>
                <dl class="grid grid-cols-[1fr_auto] gap-y-1">
                    <dt class="text-muted-foreground">Opening balance</dt>
                    <dd class="text-right tabular-nums">{{ m(invoice.opening_balance) }}</dd>
                    <dt class="text-muted-foreground">Payments received</dt>
                    <dd class="text-right tabular-nums">{{ m(invoice.payments_total) }}</dd>
                    <template v-if="Number(invoice.adjustments_total)">
                        <dt class="text-muted-foreground">Adjustments</dt>
                        <dd class="text-right tabular-nums">{{ m(invoice.adjustments_total) }}</dd>
                    </template>
                    <dt class="text-muted-foreground">Usage charges</dt>
                    <dd class="text-right tabular-nums">−{{ m(invoice.usage_total) }}</dd>
                    <dt class="border-t pt-1 font-medium">Closing balance</dt>
                    <dd class="border-t pt-1 text-right font-medium tabular-nums" :class="{ 'text-destructive': Number(invoice.closing_balance) < 0 }">{{ m(invoice.closing_balance) }}</dd>
                    <template v-if="invoice.billing_type === 'postpaid'">
                        <dt class="pt-2 text-base font-semibold">Amount due</dt>
                        <dd class="pt-2 text-right text-base font-semibold tabular-nums">{{ m(invoice.amount_due) }}</dd>
                    </template>
                </dl>
                <p v-if="invoice.billing_type === 'prepaid'" class="mt-2 text-xs text-muted-foreground">Prepaid account: usage is deducted from your balance; nothing is due.</p>
            </section>
        </article>

        <!-- Void (not printed) -->
        <section v-if="invoice.status === 'issued'" class="mx-auto max-w-3xl space-y-2 print:hidden">
            <details class="rounded-lg border p-4 text-sm">
                <summary class="cursor-pointer font-medium">Void this invoice</summary>
                <p class="mt-2 text-muted-foreground">Voiding doesn't change any balance (invoices are statements). You can then issue a corrected one from the account page.</p>
                <Form v-bind="InvoiceController.void.form(invoice.id)" class="mt-3 flex flex-wrap items-start gap-2" v-slot="{ errors, processing }">
                    <div class="grid gap-1">
                        <Input name="reason" placeholder="Reason" required class="w-72" />
                        <InputError :message="errors.reason" />
                    </div>
                    <Button variant="destructive" :disabled="processing">Void</Button>
                </Form>
            </details>
        </section>
    </div>
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
