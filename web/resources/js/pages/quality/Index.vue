<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { index } from '@/routes/quality';
import { show as showTrunk } from '@/routes/trunks';

type Row = {
    vendor_id: number;
    vendor_code: string;
    vendor_name: string;
    trunk_id: number | null;
    trunk_name: string | null;
    prefix: string | null;
    attempts: number;
    answered: number;
    minutes: number;
    asr: number | null;
    acd: number | null;
    pdd_ms: number | null;
    busy_pct: number | null;
    no_answer_pct: number | null;
    congestion_pct: number | null;
    rejected_pct: number | null;
};

const props = defineProps<{
    rows: Row[];
    series: Record<number, { hour: string; attempts: number; asr: number | null }[]>;
    filters: { from: string; to: string; group: string; vendor: number | null; min: number };
    vendors: { id: number; code: string; name: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Carrier quality', href: index() }] } });

const f = reactive({ group: props.filters.group, vendor: String(props.filters.vendor ?? ''), min: String(props.filters.min), hours: '' });

function apply(hours?: number) {
    const to = new Date();
    const query: Record<string, string> = { group: f.group, min: f.min };
    if (f.vendor) query.vendor = f.vendor;
    if (hours) {
        query.to = to.toISOString();
        query.from = new Date(to.getTime() - hours * 3600_000).toISOString();
    } else {
        query.from = props.filters.from;
        query.to = props.filters.to;
    }
    router.get(index.url({ query }), {}, { preserveState: true, replace: true });
}

const rangeHours = Math.round((new Date(props.filters.to).getTime() - new Date(props.filters.from).getTime()) / 3600_000);
const pct = (v: number | null) => (v === null ? '—' : `${v}%`);
const secs = (s: number | null) => (s === null ? '—' : s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
const pdd = (ms: number | null) => (ms === null ? '—' : `${(ms / 1000).toFixed(1)}s`);
// Colour bands chosen for wholesale traffic; adjust to your routes.
const asrClass = (v: number | null) => (v === null ? '' : v < 20 ? 'text-destructive font-semibold' : v < 35 ? 'text-amber-600 dark:text-amber-400' : '');
const pddClass = (ms: number | null) => (ms !== null && ms > 8000 ? 'text-destructive font-semibold' : ms !== null && ms > 5000 ? 'text-amber-600 dark:text-amber-400' : '');
const cell = (asr: number | null, attempts: number) =>
    attempts === 0 || asr === null ? 'bg-muted' : asr < 20 ? 'bg-red-500' : asr < 35 ? 'bg-amber-400' : asr < 50 ? 'bg-lime-500' : 'bg-green-600';
</script>

<template>
    <Head title="Carrier quality" />

    <div class="ss-stack-4">
        <Heading title="Carrier quality" :description="`Per carrier attempt over the last ${rangeHours} hours. Updated every 5 minutes.`" />

        <div class="card card-body d-flex flex-wrap align-items-end gap-2 flex-row">
            <div class="ss-grid gap-1">
                <Label for="group">Group by</Label>
                <select id="group" v-model="f.group" :class="selectClass" class="w-auto" @change="apply()">
                    <option value="vendor">Carrier</option>
                    <option value="trunk">Trunk</option>
                    <option value="prefix">Trunk and destination</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="vendor">Carrier</Label>
                <select id="vendor" v-model="f.vendor" :class="selectClass" class="w-auto" @change="apply()">
                    <option value="">All</option>
                    <option v-for="v in vendors" :key="v.id" :value="String(v.id)">{{ v.code }} — {{ v.name }}</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="min">Min. attempts</Label>
                <Input id="min" v-model="f.min" type="number" min="1" class="ss-w-24" @change="apply()" />
            </div>
            <div class="d-flex gap-1">
                <Button variant="ghost" @click="apply(1)">1h</Button>
                <Button variant="ghost" @click="apply(24)">24h</Button>
                <Button variant="ghost" @click="apply(24 * 7)">7 days</Button>
                <Button variant="ghost" @click="apply(24 * 30)">30 days</Button>
            </div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Carrier</th>
                        <th v-if="filters.group !== 'vendor'">Trunk</th>
                        <th v-if="filters.group === 'prefix'">Prefix</th>
                        <th class="text-end">Attempts</th>
                        <th class="text-end">ASR</th>
                        <th class="text-end">ACD</th>
                        <th class="text-end">PDD</th>
                        <th class="text-end">Minutes</th>
                        <th class="text-end" title="Busy / no answer / congestion (5xx, timeouts) / rejected (4xx, 6xx)">Busy · NoAns · Cong · Rej</th>
                        <th v-if="filters.group === 'trunk'">ASR by hour</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="`${r.vendor_id}-${r.trunk_id}-${r.prefix}`">
                        <td><Link :href="showAccount(r.vendor_id)">{{ r.vendor_code }}</Link></td>
                        <td v-if="filters.group !== 'vendor'">
                            <Link v-if="r.trunk_id" :href="showTrunk(r.trunk_id)">{{ r.trunk_name }}</Link>
                        </td>
                        <td v-if="filters.group === 'prefix'" class="font-monospace">{{ r.prefix || '—' }}</td>
                        <td class="text-end tabular-nums">{{ r.attempts.toLocaleString() }}</td>
                        <td class="text-end tabular-nums" :class="asrClass(r.asr)">{{ pct(r.asr) }}</td>
                        <td class="text-end tabular-nums">{{ secs(r.acd) }}</td>
                        <td class="text-end tabular-nums" :class="pddClass(r.pdd_ms)">{{ pdd(r.pdd_ms) }}</td>
                        <td class="text-end tabular-nums">{{ r.minutes.toLocaleString() }}</td>
                        <td class="text-end small text-nowrap text-secondary tabular-nums">
                            {{ pct(r.busy_pct) }} · {{ pct(r.no_answer_pct) }} · {{ pct(r.congestion_pct) }} · {{ pct(r.rejected_pct) }}
                        </td>
                        <td v-if="filters.group === 'trunk'">
                            <div class="d-flex align-items-end ss-gap-px">
                                <span
                                    v-for="h in (r.trunk_id && series[r.trunk_id]) || []"
                                    :key="h.hour"
                                    class="d-inline-block ss-w-1-5 rounded-1"
                                    :class="cell(h.asr, h.attempts)"
                                    :title="`${new Date(h.hour).toLocaleString()}: ${h.attempts} attempts, ASR ${h.asr ?? '—'}%`"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td colspan="10" class="text-center text-secondary">No carrier attempts in this range.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="small text-secondary">
            ASR here is per carrier attempt (a call that fails over counts against each carrier tried). PDD is the time from our INVITE to the carrier's first ringing or answer.
        </p>
    </div>
</template>
