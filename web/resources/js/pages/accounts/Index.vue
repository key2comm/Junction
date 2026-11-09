<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useBilling } from '@/composables/useBilling';
import { money, titleCase } from '@/lib/format';
import { create, index, show } from '@/routes/accounts';
import { create as createCarrier, index as carriersIndex } from '@/routes/carriers';

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
    gateways_count: number;
};

const props = defineProps<{
    /** "carrier" at Routing → Carriers; "all" at Billing → Accounts. */
    scope: 'all' | 'carrier';
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
    layout: (props: { scope: 'all' | 'carrier' }) => ({
        breadcrumbs: [props.scope === 'carrier' ? { title: 'Carriers', href: carriersIndex() } : { title: 'Accounts', href: index() }],
    }),
});

const billing = useBilling();
const carriers = computed(() => props.scope === 'carrier');
const listUrl = (query: Record<string, string>) => (carriers.value ? carriersIndex.url({ query }) : index.url({ query }));
const columns = computed(() => (carriers.value ? 4 : billing.value ? 6 : 3));

const filters = reactive({ q: props.filters.q ?? '', role: props.filters.role ?? '', status: props.filters.status ?? '' });

let timer: ReturnType<typeof setTimeout> | undefined;
function apply(delay = 0) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        router.get(listUrl(query), {}, { preserveState: true, replace: true });
    }, delay);
}

const select = 'form-select w-auto';
const statusVariant = (s: string) => (s === 'active' ? 'default' : s === 'suspended' ? 'destructive' : 'secondary');
</script>

<template>
    <Head :title="carriers ? 'Carriers' : 'Accounts'" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading v-if="carriers" title="Carriers" description="The carriers you send traffic to. Each has its SIP trunks (primary and failovers); LCR groups choose between carriers." />
            <Heading v-else title="Accounts" description="Your customers, and the carriers you send traffic to (managed under Routing → Carriers)" />
            <Button as-child>
                <Link v-if="carriers" :href="createCarrier()"><Plus class="icon" /> New carrier</Link>
                <Link v-else :href="create()"><Plus class="icon" /> New customer</Link>
            </Button>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <Input v-model="filters.q" placeholder="Search name or code" class="ss-maxw-xs" @input="apply(300)" />
            <select v-if="!carriers" v-model="filters.role" :class="select" aria-label="Role" @change="apply()">
                <option value="">All roles</option>
                <option value="customer">Customers</option>
                <option value="vendor">Carriers</option>
            </select>
            <select v-model="filters.status" :class="select" aria-label="Status" @change="apply()">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="closed">Closed</option>
            </select>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">{{ carriers ? 'Carrier' : 'Account' }}</th>
                        <th v-if="!carriers" class="fw-medium">Role</th>
                        <th class="fw-medium">Status</th>
                        <template v-if="carriers">
                            <th class="fw-medium">Currency</th>
                            <th class="text-end fw-medium">SIP trunks</th>
                        </template>
                        <template v-else-if="billing">
                            <th class="fw-medium">Billing</th>
                            <th class="text-end fw-medium">Balance</th>
                            <th class="text-end fw-medium">Available</th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts.data" :key="a.id">
                        <td>
                            <Link :href="show(a.id)" class="fw-medium">{{ a.name }}</Link>
                            <div class="font-monospace small text-secondary">{{ a.code }}</div>
                        </td>
                        <td v-if="!carriers" class="ss-row-1">
                            <Badge v-if="a.is_customer" variant="outline">Customer</Badge>
                            <Badge v-if="a.is_vendor" variant="outline">Carrier</Badge>
                        </td>
                        <td><Badge :variant="statusVariant(a.status)">{{ titleCase(a.status) }}</Badge></td>
                        <template v-if="carriers">
                            <td>{{ a.currency }}</td>
                            <td class="text-end tabular-nums">{{ a.gateways_count }}</td>
                        </template>
                        <template v-else-if="billing && a.is_customer">
                            <td>{{ titleCase(a.billing_type) }}</td>
                            <td class="text-end tabular-nums" :class="{ 'text-danger': Number(a.balance) < 0 }">
                                {{ money(a.balance, a.currency) }}
                            </td>
                            <td class="text-end tabular-nums">{{ money(a.available, a.currency) }}</td>
                        </template>
                        <template v-else-if="billing">
                            <td class="text-secondary" colspan="3">Carrier: see its payables</td>
                        </template>
                    </tr>
                    <tr v-if="accounts.data.length === 0">
                        <td :colspan="columns" class="text-center text-secondary">
                            No {{ carriers ? 'carriers' : 'accounts' }}{{ filters.q || filters.role || filters.status ? ' match these filters' : ' yet' }}.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="accounts.links" :from="accounts.from" :to="accounts.to" :total="accounts.total" />
    </div>
</template>
