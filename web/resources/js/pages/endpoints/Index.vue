<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { create, edit, index } from '@/routes/endpoints';

type Row = {
    id: number;
    kind: 'pbx' | 'device';
    name: string;
    enabled: boolean;
    outbound_enabled: boolean;
    codecs: string[];
    max_inbound: number | null;
    max_outbound: number | null;
    max_total: number | null;
    account: { id: number; name: string; code: string };
    summary: string;
};

const props = defineProps<{
    endpoints: Row[];
    customers: { id: number; name: string; code: string }[];
    filters: { account: number | null };
    codecs: Record<string, string>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'PBX/Devices', href: index() }] } });

const account = ref(props.filters.account ?? '');
const filter = () => router.get(index.url({ query: { account: account.value || undefined } }), {}, { preserveState: true, replace: true });
const limit = (n: number | null) => n ?? '∞';
</script>

<template>
    <Head title="PBX/Devices" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading
                title="PBX/Devices"
                description="Where customers' calls are delivered: a customer's own PBX, or a device (IP/FQDN, up to three addresses) with an optional call flow. DIDs point at these."
            />
            <Button as-child><Link :href="create({ query: { account: account || undefined } })"><Plus class="icon" /> Add PBX/Device</Link></Button>
        </div>

        <div>
            <select v-model="account" :class="selectClass" class="w-auto" aria-label="Customer" @change="filter">
                <option value="">All customers</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
            </select>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Customer</th>
                        <th>Sends calls to</th>
                        <th>Codecs</th>
                        <th title="Inbound / outbound / total concurrent calls">Limits in / out / total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in endpoints" :key="e.id">
                        <td>
                            <Link :href="edit(e.id)" class="fw-medium">{{ e.name }}</Link>
                            <div class="d-flex gap-1 mt-1">
                                <Badge variant="outline">{{ e.kind === 'pbx' ? 'PBX' : 'Device' }}</Badge>
                                <Badge v-if="!e.enabled" variant="secondary">Disabled</Badge>
                                <Badge v-if="!e.outbound_enabled" variant="outline" title="Calls from its addresses are not accepted">Inbound only</Badge>
                            </div>
                        </td>
                        <td><Link :href="showAccount(e.account.id)">{{ e.account.name }}</Link></td>
                        <td class="font-monospace small">{{ e.summary }}</td>
                        <td class="small">{{ e.codecs.map((c) => codecs[c] ?? c).join(', ') }}</td>
                        <td class="text-nowrap">{{ limit(e.max_inbound) }} / {{ limit(e.max_outbound) }} / {{ limit(e.max_total) }}</td>
                    </tr>
                    <tr v-if="endpoints.length === 0">
                        <td colspan="5" class="text-center text-secondary">No PBX/Devices yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
