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

    <div class="ss-stack-4">
        <Heading title="Invoices" description="Your monthly statements" />
        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">Invoice</th>
                        <th class="fw-medium">Period</th>
                        <th class="text-end fw-medium">Usage</th>
                        <th class="text-end fw-medium">Amount due</th>
                        <th class="fw-medium">Due</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in invoices.data" :key="i.id">
                        <td><Link :href="showInvoice(i.id)" class="font-monospace fw-medium">{{ i.number }}</Link></td>
                        <td>{{ billingMonth(i.period_start, i.timezone) }}</td>
                        <td class="text-end tabular-nums">{{ money(i.usage_total, i.currency) }}</td>
                        <td class="text-end fw-medium tabular-nums">{{ Number(i.amount_due) ? money(i.amount_due, i.currency) : '—' }}</td>
                        <td>{{ date(i.due_at) }}</td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td colspan="5" class="text-center text-secondary">No invoices yet. They're issued after each month ends.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="invoices.links" :from="invoices.from" :to="invoices.to" :total="invoices.total" />
    </div>
</template>
