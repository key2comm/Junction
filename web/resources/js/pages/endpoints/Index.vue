<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SearchSelect } from '@/components/ui/search-select';
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
const customerOptions = [{ value: '' as const, label: 'Any customer' }, ...props.customers.map((c) => ({ value: c.id, label: c.name, hint: c.code }))];
// Narrow the list as you type: every word must be in the name or the customer's name or code.
const q = ref('');
const shown = computed(() => {
    const words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    if (!words.length) return props.endpoints;
    return props.endpoints.filter((e) => {
        const text = `${e.name} ${e.account?.name ?? 'shared'} ${e.account?.code ?? ''}`.toLowerCase();
        return words.every((w) => text.includes(w));
    });
});
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
            <SearchSelect
                v-model="account"
                :options="customerOptions"
                class="ss-w-64"
                placeholder="Any customer"
                search-placeholder="Search by name or account code…"
                @update:model-value="filter"
            />
            <Input v-model="q" type="search" placeholder="Search name or customer" aria-label="Search PBX/Devices" class="ss-maxw-xs" />
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
                    <tr v-for="e in shown" :key="e.id">
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
                    <tr v-if="shown.length === 0">
                        <td colspan="6" class="text-center text-secondary">{{ endpoints.length ? 'No PBX/Devices match.' : 'No PBX/Devices yet.' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
