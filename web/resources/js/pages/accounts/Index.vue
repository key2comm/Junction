<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { money, titleCase } from '@/lib/format';
import { create, index, show } from '@/routes/accounts';

type Row = {
    id: number;
    name: string;
    code: string;
    is_customer: boolean;
    is_vendor: boolean;
    status: string;
    billing_type: string;
    currency: string;
    balance: string;
    available: string;
};

const props = defineProps<{
    accounts: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { q?: string; role?: string; status?: string };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Accounts', href: index() }] },
});

const filters = reactive({ q: props.filters.q ?? '', role: props.filters.role ?? '', status: props.filters.status ?? '' });

let timer: ReturnType<typeof setTimeout> | undefined;
function apply(delay = 0) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        router.get(index.url({ query }), {}, { preserveState: true, replace: true });
    }, delay);
}

const select =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
const statusVariant = (s: string) => (s === 'active' ? 'default' : s === 'suspended' ? 'destructive' : 'secondary');
</script>

<template>
    <Head title="Accounts" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Accounts" description="Customers and vendors you exchange traffic with" />
            <Button as-child>
                <Link :href="create()"><Plus class="size-4" /> New account</Link>
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Input v-model="filters.q" placeholder="Search name or code" class="max-w-xs" @input="apply(300)" />
            <select v-model="filters.role" :class="select" aria-label="Role" @change="apply()">
                <option value="">All roles</option>
                <option value="customer">Customers</option>
                <option value="vendor">Vendors</option>
            </select>
            <select v-model="filters.status" :class="select" aria-label="Status" @change="apply()">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="closed">Closed</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Account</th>
                        <th class="px-4 py-2 font-medium">Role</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Billing</th>
                        <th class="px-4 py-2 text-right font-medium">Balance</th>
                        <th class="px-4 py-2 text-right font-medium">Available</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts.data" :key="a.id" class="border-t hover:bg-muted/30">
                        <td class="px-4 py-2">
                            <Link :href="show(a.id)" class="font-medium hover:underline">{{ a.name }}</Link>
                            <div class="font-mono text-xs text-muted-foreground">{{ a.code }}</div>
                        </td>
                        <td class="space-x-1 px-4 py-2">
                            <Badge v-if="a.is_customer" variant="outline">Customer</Badge>
                            <Badge v-if="a.is_vendor" variant="outline">Vendor</Badge>
                        </td>
                        <td class="px-4 py-2"><Badge :variant="statusVariant(a.status)">{{ titleCase(a.status) }}</Badge></td>
                        <td class="px-4 py-2">{{ titleCase(a.billing_type) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums" :class="{ 'text-destructive': Number(a.balance) < 0 }">
                            {{ money(a.balance, a.currency) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ money(a.available, a.currency) }}</td>
                    </tr>
                    <tr v-if="accounts.data.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                            No accounts{{ filters.q || filters.role || filters.status ? ' match these filters' : ' yet' }}.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="accounts.links" :from="accounts.from" :to="accounts.to" :total="accounts.total" />
    </div>
</template>
