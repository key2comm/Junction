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

    <div class="max-w-4xl space-y-8 p-4">
        <div class="space-y-2">
            <Heading :title="`${cdr.caller ?? 'Unknown'} → ${cdr.destination ?? cdr.callee}`" :description="dateTime(cdr.started_at)" />
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <Badge :variant="cdr.answered_at ? 'default' : cdr.attempts === 0 ? 'destructive' : 'secondary'">{{ outcome }}</Badge>
                <span v-if="cdr.sip_code" class="font-mono">SIP {{ cdr.sip_code }}</span>
                <span class="text-muted-foreground">{{ cdr.hangup_cause }}</span>
            </div>
            <p v-if="cdr.reason" class="text-sm text-destructive">{{ cdr.reason }}</p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Customer</dt>
                <dd>
                    <Link v-if="cdr.customer" :href="showAccount(cdr.customer.id)" class="hover:underline">{{ cdr.customer.name }} ({{ cdr.customer.code }})</Link>
                    <span v-else>Unidentified</span>
                </dd>
                <dt class="text-muted-foreground">Source IP</dt>
                <dd class="font-mono">{{ cdr.source_ip ?? '—' }}</dd>
                <dt class="text-muted-foreground">Dialled</dt>
                <dd class="font-mono">{{ cdr.callee }}</dd>
                <dt class="text-muted-foreground">Answered</dt>
                <dd>{{ dateTime(cdr.answered_at) }}</dd>
                <dt class="text-muted-foreground">Ended</dt>
                <dd>{{ dateTime(cdr.ended_at) }}</dd>
                <dt class="text-muted-foreground">Billed time</dt>
                <dd>{{ cdr.billsec }}s</dd>
            </dl>

            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Sell</dt>
                <dd>{{ cdr.sell_rate ? `${money(cdr.sell_rate, cdr.currency)}/min (prefix ${cdr.sell_prefix})` : '—' }}</dd>
                <dt class="text-muted-foreground">Charged</dt>
                <dd class="tabular-nums">{{ cdr.sell_cost !== null ? money(cdr.sell_cost, cdr.currency) : '—' }}</dd>
                <dt class="text-muted-foreground">Vendor</dt>
                <dd>
                    <Link v-if="cdr.vendor" :href="showAccount(cdr.vendor.id)" class="hover:underline">{{ cdr.vendor.code }}</Link>
                    <span v-if="cdr.trunk"> / <Link :href="showTrunk(cdr.trunk.id)" class="hover:underline">{{ cdr.trunk.name }}</Link></span>
                    <span v-if="cdr.gateway" class="font-mono text-xs text-muted-foreground"> ({{ cdr.gateway }})</span>
                    <span v-if="!cdr.vendor">—</span>
                </dd>
                <dt class="text-muted-foreground">Buy</dt>
                <dd>{{ cdr.buy_rate ? `${money(cdr.buy_rate, cdr.currency)}/min (prefix ${cdr.buy_prefix})` : '—' }}</dd>
                <dt class="text-muted-foreground">Cost</dt>
                <dd class="tabular-nums">{{ cdr.buy_cost !== null ? money(cdr.buy_cost, cdr.currency) : '—' }}</dd>
                <dt class="text-muted-foreground">Margin</dt>
                <dd class="font-semibold tabular-nums" :class="{ 'text-destructive': Number(cdr.margin) < 0 }">{{ cdr.margin !== null ? money(cdr.margin, cdr.currency) : '—' }}</dd>
                <dt class="text-muted-foreground">Billing</dt>
                <dd>{{ cdr.posted_at ? `posted to ledger ${dateTime(cdr.posted_at)}` : cdr.rated_at ? 'rated, posts within the hour' : 'not rated' }}</dd>
            </dl>
        </div>

        <section class="space-y-3">
            <Heading variant="small" title="Vendor attempts" description="Every vendor tried for this call, in order." />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">#</th>
                            <th class="px-3 py-2 font-medium">Trunk</th>
                            <th class="px-3 py-2 font-medium">Gateway</th>
                            <th class="px-3 py-2 text-right font-medium">Buy rate</th>
                            <th class="px-3 py-2 font-medium">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in attempts" :key="a.attempt" class="border-t">
                            <td class="px-3 py-1.5">{{ a.attempt }}</td>
                            <td class="px-3 py-1.5">
                                <Link v-if="a.trunk_id && a.trunk" :href="showTrunk(a.trunk_id)" class="hover:underline">{{ a.trunk }}</Link>
                                <span v-else class="text-muted-foreground">(deleted)</span>
                            </td>
                            <td class="px-3 py-1.5 font-mono text-xs">{{ a.gateway ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ a.buy_rate ? money(a.buy_rate, cdr.currency) : '—' }}</td>
                            <td class="px-3 py-1.5">
                                <Badge :variant="a.answered ? 'default' : 'secondary'">{{ a.answered ? 'answered' : a.sip_code ?? 'failed' }}</Badge>
                                <span v-if="!a.answered" class="ml-1 text-xs text-muted-foreground">{{ a.hangup_cause }}</span>
                            </td>
                        </tr>
                        <tr v-if="attempts.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">No vendor was tried: the call was refused before routing.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="font-mono text-xs text-muted-foreground">UUID {{ cdr.uuid }}</p>
    </div>
</template>
