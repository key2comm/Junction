<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { reactive } from 'vue';
import DidController from '@/actions/App/Http/Controllers/Admin/DidController';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Paginated } from '@/lib/ui';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { create, edit, index } from '@/routes/dids';

type Account = { id: number; name: string; code: string };
type Did = {
    id: number;
    number: string;
    language: string;
    status: 'active' | 'deactivated' | 'banned';
    status_reason: string | null;
    description: string | null;
    account: Account;
    provider: Account | null;
    destination: string;
};

const props = defineProps<{
    dids: Paginated<Did>;
    filters: { q: string; account: number | null; provider: number | null; status: string; endpoint: { id: number; kind: string; name: string } | null };
    customers: Account[];
    providers: Account[];
    statuses: Record<string, string>;
    counts: Record<string, number>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'DIDs', href: index() }] } });

const f = reactive({ q: props.filters.q, account: props.filters.account ?? '', provider: props.filters.provider ?? '', status: props.filters.status });
let timer: ReturnType<typeof setTimeout> | undefined;
function apply(delay = 0) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = { q: f.q || undefined, account: f.account || undefined, provider: f.provider || undefined, status: f.status || undefined, endpoint: props.filters.endpoint?.id };
        router.get(index.url({ query }), {}, { preserveState: true, replace: true });
    }, delay);
}
function remove(d: Did) {
    if (confirm(`Delete DID ${d.number}? Calls to it will no longer be delivered.`)) {
        router.delete(DidController.destroy.url(d.id), { preserveScroll: true });
    }
}
const badge = (s: string) => (s === 'banned' ? 'destructive' : s === 'active' ? 'default' : 'secondary');
</script>

<template>
    <Head title="DIDs" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="DIDs" description="Numbers providers deliver to this switch, and where each one goes. Inbound calls to DIDs are free for the customer." />
            <Button as-child><Link :href="create({ query: { account: f.account || undefined } })"><Plus class="icon" /> Add DID</Link></Button>
        </div>

        <p v-if="filters.endpoint" class="mb-0">
            Showing DIDs sent to <strong>{{ filters.endpoint.name }}</strong>.
            <Link :href="index()" class="ms-1">Show all</Link>
        </p>
        <div class="d-flex flex-wrap gap-2">
            <Input v-model="f.q" placeholder="Search number or comment" class="ss-maxw-xs" @input="apply(300)" />
            <select v-model="f.account" :class="selectClass" class="w-auto" aria-label="Customer" @change="apply()">
                <option value="">All customers</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
            <select v-model="f.provider" :class="selectClass" class="w-auto" aria-label="Provider" @change="apply()">
                <option value="">All providers</option>
                <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select v-model="f.status" :class="selectClass" class="w-auto" aria-label="Status" @change="apply()">
                <option value="">Any status</option>
                <option v-for="(label, s) in statuses" :key="s" :value="s">{{ label }} ({{ counts[s] ?? 0 }})</option>
            </select>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Customer</th>
                        <th>Goes to</th>
                        <th>Provider</th>
                        <th>Lang</th>
                        <th>Status</th>
                        <th>Comment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in dids.data" :key="d.id">
                        <td class="font-monospace fw-medium"><Link :href="edit(d.id)">{{ d.number }}</Link></td>
                        <td><Link :href="showAccount(d.account.id)">{{ d.account.name }}</Link></td>
                        <td>{{ d.destination }}</td>
                        <td>{{ d.provider?.name ?? '—' }}</td>
                        <td class="text-uppercase">{{ d.language }}</td>
                        <td>
                            <Badge :variant="badge(d.status)">{{ statuses[d.status] }}</Badge>
                            <div v-if="d.status_reason" class="small text-secondary">{{ d.status_reason }}</div>
                        </td>
                        <td>{{ d.description }}</td>
                        <td class="text-end">
                            <Button size="sm" variant="ghost" :aria-label="`Delete ${d.number}`" @click="remove(d)"><Trash2 class="icon" /></Button>
                        </td>
                    </tr>
                    <tr v-if="dids.data.length === 0">
                        <td colspan="8" class="text-center text-secondary">No DIDs{{ f.q || f.account || f.provider || f.status ? ' match' : ' yet' }}.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="dids.links" :from="dids.from" :to="dids.to" :total="dids.total" />
    </div>
</template>
