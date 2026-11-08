<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { index as didsIndex } from '@/routes/dids';
import { create, edit, index } from '@/routes/endpoints';

type Row = {
    id: number;
    kind: 'pbx' | 'device';
    name: string;
    enabled: boolean;
    sip_username: string | null;
    rules_count: number;
    mode: 'sending' | 'receiving';
    match_dids: boolean;
    dids: number;
    codecs: string[];
    max_inbound: number | null;
    max_outbound: number | null;
    max_total: number | null;
    account: { id: number; name: string; code: string } | null;
    summary: string;
};

const props = defineProps<{
    endpoints: Row[];
    customers: { id: number; name: string; code: string }[];
    filters: { account: number | null; kind: string | null };
    codecs: Record<string, string>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'PBX/Devices', href: index() }] } });

const account = ref(props.filters.account ?? '');
const kind = ref(props.filters.kind ?? '');
const filter = () => router.get(index.url({ query: { account: account.value || undefined, kind: kind.value || undefined } }), {}, { preserveState: true, replace: true });
const limit = (n: number | null) => n ?? '∞';
</script>

<template>
    <Head title="PBX/Devices" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading
                title="PBX/Devices"
                description="Where DIDs are delivered. A PBX is shared (e.g. a multi-tenant PBX): DIDs of any customer can go to it. A device belongs to one customer, with its own call flow and rules for recognising the calls it sends."
            />
            <Button as-child><Link :href="create({ query: { account: account || undefined, kind: kind || undefined } })"><Plus class="icon" /> Add PBX/Device</Link></Button>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <select v-model="kind" :class="selectClass" class="w-auto" aria-label="Type" @change="filter">
                <option value="">PBXs and devices</option>
                <option value="pbx">PBXs</option>
                <option value="device">Devices</option>
            </select>
            <select v-model="account" :class="selectClass" class="w-auto" aria-label="Customer" @change="filter">
                <option value="">Any customer</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
            </select>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Customer</th>
                        <th class="text-end">DIDs</th>
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
                                <Badge v-if="e.sip_username" variant="outline">SIP trunk</Badge>
                                <Badge v-if="e.kind === 'device' && e.mode === 'receiving'" variant="outline" title="It sends calls to the switch, recognised by its rules">
                                    Receiving calls · {{ e.rules_count }} rule(s){{ e.match_dids ? ' + DIDs' : '' }}
                                </Badge>
                                <Badge v-else-if="e.kind === 'device'" variant="outline" title="The switch sends calls (DIDs) to it">Sending calls</Badge>
                            </div>
                        </td>
                        <td>
                            <Link v-if="e.account" :href="showAccount(e.account.id)">{{ e.account.name }}</Link>
                            <span v-else class="text-secondary">Shared</span>
                        </td>
                        <td class="text-end"><Link v-if="e.dids" :href="didsIndex({ query: { endpoint: e.id } })">{{ e.dids }}</Link><span v-else class="text-secondary">0</span></td>
                        <td class="font-monospace small">{{ e.summary }}</td>
                        <td class="small">{{ e.codecs.map((c) => codecs[c] ?? c).join(', ') }}</td>
                        <td class="text-nowrap">{{ limit(e.max_inbound) }} / {{ limit(e.max_outbound) }} / {{ limit(e.max_total) }}</td>
                    </tr>
                    <tr v-if="endpoints.length === 0">
                        <td colspan="6" class="text-center text-secondary">No PBX/Devices yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
