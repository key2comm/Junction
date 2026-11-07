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

    <div class="space-y-6 p-4">
        <Heading
            title="Invoices"
            description="Monthly customer statements, issued automatically two hours after each customer's month ends (in their time zone)"
        />

        <div class="flex flex-wrap gap-3">
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

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Invoice</th>
                        <th class="px-4 py-2 font-medium">Customer</th>
                        <th class="px-4 py-2 font-medium">Period</th>
                        <th class="px-4 py-2 text-right font-medium">Usage</th>
                        <th class="px-4 py-2 text-right font-medium">Amount due</th>
                        <th class="px-4 py-2 font-medium">Due</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in invoices.data" :key="i.id" class="border-t hover:bg-muted/30" :class="{ 'opacity-60': i.status === 'void' }">
                        <td class="px-4 py-2">
                            <Link :href="show(i.id)" class="font-mono font-medium hover:underline">{{ i.number }}</Link>
                            <Badge v-if="i.status === 'void'" variant="secondary" class="ml-2">void</Badge>
                        </td>
                        <td class="px-4 py-2">{{ i.account_name }} <span class="text-muted-foreground">{{ i.account_code }}</span></td>
                        <td class="px-4 py-2">{{ billingMonth(i.period_start, i.timezone) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ money(i.usage_total, i.currency) }}</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ Number(i.amount_due) ? money(i.amount_due, i.currency) : '—' }}</td>
                        <td class="px-4 py-2">{{ date(i.due_at) }}</td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">No invoices yet. The first ones are issued after the current month ends.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="invoices.links" :from="invoices.from" :to="invoices.to" :total="invoices.total" />
    </div>
</template>
