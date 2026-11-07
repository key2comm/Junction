<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import type { InvoiceDoc } from '@/components/invoices/InvoiceDocument.vue';
import InvoiceDocument from '@/components/invoices/InvoiceDocument.vue';
import { Button } from '@/components/ui/button';
import { download as invoiceCsv, index as invoices, show as showInvoice } from '@/routes/portal/invoices';

const props = defineProps<{
    invoice: InvoiceDoc & { id: number };
    lines: { position: number; prefix: string | null; destination: string | null; calls: number; billsec: number; rate: string | null; amount: string }[];
    issuer: { name: string; host: string | null };
}>();

defineOptions({
    layout: (props: { invoice: { id: number; number: string } }) => ({
        breadcrumbs: [
            { title: 'Invoices', href: invoices() },
            { title: props.invoice.number, href: showInvoice(props.invoice.id) },
        ],
    }),
});

const print = () => window.print();
const csvUrl = invoiceCsv.url(props.invoice.id);
</script>

<template>
    <Head :title="invoice.number" />

    <div class="space-y-4 p-4 print:p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <Link :href="invoices()" class="text-sm hover:underline">← All invoices</Link>
            <div class="flex gap-2">
                <Button variant="outline" @click="print"><Printer class="size-4" /> Print / Save as PDF</Button>
                <Button variant="outline" as-child><a :href="csvUrl"><Download class="size-4" /> CSV</a></Button>
            </div>
        </div>
        <InvoiceDocument :invoice="invoice" :lines="lines" :issuer="issuer" />
    </div>
</template>
