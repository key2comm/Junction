<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { billingMonth, date, money } from '@/lib/format';
import type { Paginated } from '@/lib/ui';
import { index as invoicesRoute, show as showInvoice } from '@/routes/portal/invoices';

defineProps<{
    invoices: Paginated<{ id: number; number: string; period_start: string; timezone: string; currency: string; usage_total: string; closing_balance: string; amount_due: string; issued_at: string; due_at: string | null }>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Invoices', href: invoicesRoute() }] } });
</script>

<template>
    <Head title="Invoices" />

    <div class="space-y-6 p-4">
        <Heading title="Invoices" description="Your monthly statements" />
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Invoice</th>
                        <th class="px-4 py-2 font-medium">Period</th>
                        <th class="px-4 py-2 text-right font-medium">Usage</th>
                        <th class="px-4 py-2 text-right font-medium">Amount due</th>
                        <th class="px-4 py-2 font-medium">Due</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in invoices.data" :key="i.id" class="border-t hover:bg-muted/30">
                        <td class="px-4 py-2"><Link :href="showInvoice(i.id)" class="font-mono font-medium hover:underline">{{ i.number }}</Link></td>
                        <td class="px-4 py-2">{{ billingMonth(i.period_start, i.timezone) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ money(i.usage_total, i.currency) }}</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ Number(i.amount_due) ? money(i.amount_due, i.currency) : '—' }}</td>
                        <td class="px-4 py-2">{{ date(i.due_at) }}</td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">No invoices yet. They're issued after each month ends.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="invoices.links" :from="invoices.from" :to="invoices.to" :total="invoices.total" />
    </div>
</template>
