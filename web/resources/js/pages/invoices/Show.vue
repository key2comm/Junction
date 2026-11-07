<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import InvoiceController from '@/actions/App/Http/Controllers/Admin/InvoiceController';
import InputError from '@/components/InputError.vue';
import InvoiceDocument from '@/components/invoices/InvoiceDocument.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { date } from '@/lib/format';
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
    emailed_at: string | null;
    emailed_to: string | null;
};

const props = defineProps<{
    invoice: Invoice;
    lines: { position: number; prefix: string | null; destination: string | null; calls: number; billsec: number; rate: string | null; amount: string }[];
    issuer: { name: string; host: string | null };
    mailConfigured: boolean;
}>();

defineOptions({
    layout: (props: { invoice: { id: number; number: string } }) => ({
        breadcrumbs: [
            { title: 'Invoices', href: index() },
            { title: props.invoice.number, href: show(props.invoice.id) },
        ],
    }),
});

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

        <InvoiceDocument :invoice="invoice" :lines="lines" :issuer="issuer" />

        <!-- Email (not printed) -->
        <section class="mx-auto max-w-3xl print:hidden">
            <div class="flex flex-wrap items-start gap-2 rounded-lg border p-4 text-sm">
                <div class="grow">
                    <p class="font-medium">Email this {{ invoice.billing_type === 'postpaid' ? 'invoice' : 'statement' }}</p>
                    <p class="text-muted-foreground">
                        <span v-if="invoice.emailed_at">Last sent to {{ invoice.emailed_to }} on {{ date(invoice.emailed_at) }}.</span>
                        <span v-else-if="!mailConfigured">Set up email in Settings → Email first.</span>
                        <span v-else>Not sent yet. Includes the summary and the destination CSV.</span>
                    </p>
                </div>
                <Form v-bind="InvoiceController.email.form(invoice.id)" class="flex flex-wrap items-start gap-2" v-slot="{ errors, processing }">
                    <div class="grid gap-1">
                        <Input name="to" type="email" :default-value="invoice.billing_email ?? ''" placeholder="billing@customer.example" class="w-64" aria-label="Send to" />
                        <InputError :message="errors.to" />
                    </div>
                    <Button variant="outline" :disabled="processing || !mailConfigured">Send</Button>
                </Form>
            </div>
        </section>

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
