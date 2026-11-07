<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { billingMonth, date, money } from '@/lib/format';
import type { Paginated } from '@/lib/ui';
import { selectClass } from '@/lib/ui';
import { index, show } from '@/routes/invoices';

type Row = {
    id: number;
    number: string;
    account_code: string;
    account_name: string;
    status: string;
    period_start: string;
    timezone: string;
    currency: string;
    usage_total: string;
    closing_balance: string;
    amount_due: string;
    issued_at: string;
    due_at: string | null;
};

const props = defineProps<{
    invoices: Paginated<Row>;
    filters: { account?: number; status?: string };
    customers: { id: number; code: string; name: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Invoices', href: index() }] } });

const f = reactive({ account: String(props.filters.account ?? ''), status: props.filters.status ?? '' });
function apply() {
    const query = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''));
    router.get(index.url({ query }), {}, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Invoices" />

    <div class="ss-stack-4">
        <Heading
            title="Invoices"
            description="Monthly customer statements, issued automatically two hours after each customer's month ends (in their time zone)"
        />

        <div class="d-flex flex-wrap gap-2">
            <select v-model="f.account" :class="selectClass" class="w-auto" aria-label="Customer" @change="apply">
                <option value="">All customers</option>
                <option v-for="c in customers" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
            </select>
            <select v-model="f.status" :class="selectClass" class="w-auto" aria-label="Status" @change="apply">
                <option value="">Any status</option>
                <option value="issued">Issued</option>
                <option value="void">Void</option>
            </select>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">Invoice</th>
                        <th class="fw-medium">Customer</th>
                        <th class="fw-medium">Period</th>
                        <th class="text-end fw-medium">Usage</th>
                        <th class="text-end fw-medium">Amount due</th>
                        <th class="fw-medium">Due</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in invoices.data" :key="i.id" :class="{ 'opacity-50': i.status === 'void' }">
                        <td>
                            <Link :href="show(i.id)" class="font-monospace fw-medium">{{ i.number }}</Link>
                            <Badge v-if="i.status === 'void'" variant="secondary" class="ms-2">void</Badge>
                        </td>
                        <td>{{ i.account_name }} <span class="text-secondary">{{ i.account_code }}</span></td>
                        <td>{{ billingMonth(i.period_start, i.timezone) }}</td>
                        <td class="text-end tabular-nums">{{ money(i.usage_total, i.currency) }}</td>
                        <td class="text-end fw-medium tabular-nums">{{ Number(i.amount_due) ? money(i.amount_due, i.currency) : '—' }}</td>
                        <td>{{ date(i.due_at) }}</td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td colspan="6" class="text-center text-secondary">No invoices yet. The first ones are issued after the current month ends.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="invoices.links" :from="invoices.from" :to="invoices.to" :total="invoices.total" />
    </div>
</template>
