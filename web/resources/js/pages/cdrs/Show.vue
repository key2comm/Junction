<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { dateTime, money } from '@/lib/format';
import { show as showAccount } from '@/routes/accounts';
import { index, show } from '@/routes/cdrs';
import { show as showTrunk } from '@/routes/trunks';

type Cdr = {
    id: number;
    uuid: string;
    direction: string;
    caller: string | null;
    callee: string;
    destination: string | null;
    source_ip: string | null;
    started_at: string;
    answered_at: string | null;
    ended_at: string | null;
    billsec: number;
    sip_code: number | null;
    hangup_cause: string | null;
    reason: string | null;
    attempts: number;
    sell_prefix: string | null;
    sell_rate: string | null;
    sell_cost: string | null;
    buy_prefix: string | null;
    buy_rate: string | null;
    buy_cost: string | null;
    margin: string | null;
    currency: string;
    rated_at: string | null;
    posted_at: string | null;
    customer: { id: number; code: string; name: string } | null;
    vendor: { id: number; code: string; name: string } | null;
    trunk: { id: number; name: string } | null;
    gateway: string | null;
};

const props = defineProps<{
    cdr: Cdr;
    attempts: {
        attempt: number;
        trunk: string | null;
        trunk_id: number | null;
        gateway: string | null;
        buy_prefix: string | null;
        buy_rate: string | null;
        started_at: string;
        ended_at: string | null;
        sip_code: number | null;
        hangup_cause: string | null;
        answered: boolean;
    }[];
}>();

defineOptions({
    layout: (props: { cdr: { id: number } }) => ({
        breadcrumbs: [
            { title: 'Call records', href: index() },
            { title: `Call #${props.cdr.id}`, href: show(props.cdr.id) },
        ],
    }),
});

const c = props.cdr;
const outcome = c.answered_at ? 'Answered' : c.attempts === 0 ? 'Rejected before routing' : 'Not completed';
</script>

<template>
    <Head :title="`Call #${cdr.id}`" />

    <div class="ss-maxw-4xl ss-stack-4">
        <div class="ss-stack-2">
            <Heading :title="`${cdr.caller ?? 'Unknown'} → ${cdr.destination ?? cdr.callee}`" :description="dateTime(cdr.started_at)" />
            <div class="d-flex flex-wrap align-items-center gap-2">
                <Badge :variant="cdr.answered_at ? 'default' : cdr.attempts === 0 ? 'destructive' : 'secondary'">{{ outcome }}</Badge>
                <Badge v-if="cdr.direction !== 'carrier'" variant="outline">{{ { pbx_out: 'PBX outbound', pbx_in: 'PBX inbound (DID)', pbx_local: 'PBX internal' }[cdr.direction] ?? cdr.direction }}</Badge>
                <span v-if="cdr.sip_code" class="font-monospace">SIP {{ cdr.sip_code }}</span>
                <span class="text-secondary">{{ cdr.hangup_cause }}</span>
            </div>
            <p v-if="cdr.reason" class="text-danger">{{ cdr.reason }}</p>
        </div>

        <div class="ss-grid gap-4 ss-cols-sm-2">
            <dl class="ss-grid ss-gtc-auto-1fr column-gap-3 row-gap-1">
                <dt class="text-secondary">Customer</dt>
                <dd>
                    <Link v-if="cdr.customer" :href="showAccount(cdr.customer.id)">{{ cdr.customer.name }} ({{ cdr.customer.code }})</Link>
                    <span v-else>Unidentified</span>
                </dd>
                <dt class="text-secondary">Source IP</dt>
                <dd class="font-monospace">{{ cdr.source_ip ?? '—' }}</dd>
                <dt class="text-secondary">Dialled</dt>
                <dd class="font-monospace">{{ cdr.callee }}</dd>
                <dt class="text-secondary">Answered</dt>
                <dd>{{ dateTime(cdr.answered_at) }}</dd>
                <dt class="text-secondary">Ended</dt>
                <dd>{{ dateTime(cdr.ended_at) }}</dd>
                <dt class="text-secondary">Billed time</dt>
                <dd>{{ cdr.billsec }}s</dd>
            </dl>

            <dl class="ss-grid ss-gtc-auto-1fr column-gap-3 row-gap-1">
                <dt class="text-secondary">Sell</dt>
                <dd>{{ cdr.sell_rate ? `${money(cdr.sell_rate, cdr.currency)}/min (prefix ${cdr.sell_prefix})` : '—' }}</dd>
                <dt class="text-secondary">Charged</dt>
                <dd class="tabular-nums">{{ cdr.sell_cost !== null ? money(cdr.sell_cost, cdr.currency) : '—' }}</dd>
                <dt class="text-secondary">Carrier</dt>
                <dd>
                    <Link v-if="cdr.vendor" :href="showAccount(cdr.vendor.id)">{{ cdr.vendor.code }}</Link>
                    <span v-if="cdr.trunk"> / <Link :href="showTrunk(cdr.trunk.id)">{{ cdr.trunk.name }}</Link></span>
                    <span v-if="cdr.gateway" class="font-monospace small text-secondary"> ({{ cdr.gateway }})</span>
                    <span v-if="!cdr.vendor">—</span>
                </dd>
                <dt class="text-secondary">Buy</dt>
                <dd>{{ cdr.buy_rate ? `${money(cdr.buy_rate, cdr.currency)}/min (prefix ${cdr.buy_prefix})` : '—' }}</dd>
                <dt class="text-secondary">Cost</dt>
                <dd class="tabular-nums">{{ cdr.buy_cost !== null ? money(cdr.buy_cost, cdr.currency) : '—' }}</dd>
                <dt class="text-secondary">Margin</dt>
                <dd class="fw-semibold tabular-nums" :class="{ 'text-danger': Number(cdr.margin) < 0 }">{{ cdr.margin !== null ? money(cdr.margin, cdr.currency) : '—' }}</dd>
                <dt class="text-secondary">Billing</dt>
                <dd>{{ cdr.posted_at ? `posted to ledger ${dateTime(cdr.posted_at)}` : cdr.rated_at ? 'rated, posts within the hour' : 'not rated' }}</dd>
            </dl>
        </div>

        <section class="ss-stack-2">
            <Heading variant="small" title="Carrier attempts" description="Every carrier tried for this call, in order." />
            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Trunk</th>
                            <th>Gateway</th>
                            <th class="text-end">Buy rate</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in attempts" :key="a.attempt">
                            <td>{{ a.attempt }}</td>
                            <td>
                                <Link v-if="a.trunk_id && a.trunk" :href="showTrunk(a.trunk_id)">{{ a.trunk }}</Link>
                                <span v-else class="text-secondary">(deleted)</span>
                            </td>
                            <td class="font-monospace small">{{ a.gateway ?? '—' }}</td>
                            <td class="text-end tabular-nums">{{ a.buy_rate ? money(a.buy_rate, cdr.currency) : '—' }}</td>
                            <td>
                                <Badge :variant="a.answered ? 'default' : 'secondary'">{{ a.answered ? 'answered' : a.sip_code ?? 'failed' }}</Badge>
                                <span v-if="!a.answered" class="ms-1 small text-secondary">{{ a.hangup_cause }}</span>
                            </td>
                        </tr>
                        <tr v-if="attempts.length === 0">
                            <td colspan="5" class="text-center text-secondary">No carrier was tried: the call was refused before routing.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="font-monospace small text-secondary">UUID {{ cdr.uuid }}</p>
    </div>
</template>
