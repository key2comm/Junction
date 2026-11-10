<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateTime, fromZonedInput, money, timeZone, toZonedInput } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { download, index } from '@/routes/portal/calls';

type Row = {
    id: number;
    started_at: string;
    caller: string | null;
    callee: string;
    destination: string | null;
    billsec: number;
    sip_code: number | null;
    sell_destination: string | null;
    sell_rate: string | null;
    sell_cost: string | null;
    answered: boolean;
    rejected: boolean;
    reason: string | null;
};

const props = defineProps<{
    calls: { data: Row[]; prev_page_url: string | null; next_page_url: string | null };
    summary: { calls: number; answered: number; asr: number | null; acd: number | null; minutes: number; sell: string };
    filters: Record<string, string | number>;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Calls', href: index() }] } });

const f = reactive({
    from: toZonedInput(props.filters.from),
    to: toZonedInput(props.filters.to),
    status: String(props.filters.status ?? ''),
    number: String(props.filters.number ?? ''),
});
const query = computed(() => {
    const q: Record<string, string> = {};
    for (const [k, v] of Object.entries(f)) {
        if (v !== '') q[k] = k === 'from' || k === 'to' ? fromZonedInput(v) : v;
    }
    return q;
});
const apply = () => router.get(index.url({ query: query.value }), {}, { preserveState: true, replace: true });
const m = (v: string | null) => (v === null ? '—' : money(v, props.currency));
const duration = (s: number) => (s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
</script>

<template>
    <Head title="Calls" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="Calls" description="Calls you sent us, with what each one cost" />
            <Button variant="outline" as-child><a :href="download.url({ query })"><Download class="icon" /> Export CSV</a></Button>
        </div>

        <form class="ss-grid gap-2 rounded border p-3 ss-cols-sm-2 ss-cols-lg-5" @submit.prevent="apply">
            <div class="ss-grid gap-1"><Label for="from">From ({{ timeZone }})</Label><Input id="from" v-model="f.from" type="datetime-local" /></div>
            <div class="ss-grid gap-1"><Label for="to">To ({{ timeZone }})</Label><Input id="to" v-model="f.to" type="datetime-local" /></div>
            <div class="ss-grid gap-1">
                <Label for="status">Outcome</Label>
                <select id="status" v-model="f.status" :class="selectClass">
                    <option value="">Any</option>
                    <option value="answered">Answered</option>
                    <option value="failed">Not completed</option>
                    <option value="rejected">Refused</option>
                </select>
            </div>
            <div class="ss-grid gap-1"><Label for="number">Number / prefix</Label><Input id="number" v-model="f.number" class="font-monospace" /></div>
            <div class="d-flex align-items-end"><Button>Apply</Button></div>
        </form>

        <div class="ss-grid gap-3 ss-cols-sm-5">
            <div class="card card-body p-2"><p class="small text-secondary">Calls</p><p class="fs-2 fw-semibold tabular-nums">{{ summary.calls.toLocaleString() }}</p></div>
            <div class="card card-body p-2"><p class="small text-secondary">Answered</p><p class="fs-2 fw-semibold tabular-nums">{{ summary.answered.toLocaleString() }}</p></div>
            <div class="card card-body p-2"><p class="small text-secondary">ASR</p><p class="fs-2 fw-semibold tabular-nums">{{ summary.asr === null ? '—' : `${summary.asr}%` }}</p></div>
            <div class="card card-body p-2"><p class="small text-secondary">Minutes</p><p class="fs-2 fw-semibold tabular-nums">{{ summary.minutes.toLocaleString() }}</p></div>
            <div class="card card-body p-2"><p class="small text-secondary">Cost</p><p class="fs-2 fw-semibold tabular-nums">{{ m(summary.sell) }}</p></div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Started</th>
                        <th>From → To</th>
                        <th>Destination</th>
                        <th>Result</th>
                        <th class="text-end">Duration</th>
                        <th class="text-end">Rate/min</th>
                        <th class="text-end">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in calls.data" :key="r.id">
                        <td class="text-nowrap">{{ dateTime(r.started_at) }}</td>
                        <td class="font-monospace small">{{ r.caller ?? '?' }} → {{ r.destination ?? r.callee }}</td>
                        <td>{{ r.sell_destination ?? '' }}</td>
                        <td class="text-nowrap">
                            <Badge :variant="r.answered ? 'default' : r.rejected ? 'destructive' : 'secondary'">{{ r.answered ? 'answered' : r.rejected ? 'refused' : 'not completed' }}</Badge>
                            <span v-if="!r.answered" class="ms-1 small text-secondary">{{ r.sip_code }} {{ r.reason }}</span>
                        </td>
                        <td class="text-end tabular-nums">{{ r.answered ? duration(r.billsec) : '—' }}</td>
                        <td class="text-end tabular-nums">{{ r.answered ? m(r.sell_rate) : '—' }}</td>
                        <td class="text-end tabular-nums">{{ r.answered ? m(r.sell_cost) : '—' }}</td>
                    </tr>
                    <tr v-if="calls.data.length === 0">
                        <td colspan="7" class="text-center text-secondary">No calls in this range.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="calls.prev_page_url || calls.next_page_url" class="d-flex justify-content-between">
            <Link v-if="calls.prev_page_url" :href="calls.prev_page_url" preserve-scroll class="rounded border px-2 py-1">← Newer</Link>
            <span v-else />
            <Link v-if="calls.next_page_url" :href="calls.next_page_url" preserve-scroll class="rounded border px-2 py-1">Older →</Link>
        </div>
    </div>
</template>
