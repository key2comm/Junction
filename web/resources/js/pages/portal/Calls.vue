<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateTime, money } from '@/lib/format';
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

const toLocalInput = (iso?: string | number) => {
    if (!iso) return '';
    const d = new Date(String(iso));
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
const f = reactive({
    from: toLocalInput(props.filters.from),
    to: toLocalInput(props.filters.to),
    status: String(props.filters.status ?? ''),
    number: String(props.filters.number ?? ''),
});
const query = computed(() => {
    const q: Record<string, string> = {};
    for (const [k, v] of Object.entries(f)) {
        if (v !== '') q[k] = k === 'from' || k === 'to' ? new Date(v).toISOString() : v;
    }
    return q;
});
const apply = () => router.get(index.url({ query: query.value }), {}, { preserveState: true, replace: true });
const m = (v: string | null) => (v === null ? '—' : money(v, props.currency));
const duration = (s: number) => (s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
</script>

<template>
    <Head title="Calls" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Calls" description="Calls you sent us, with what each one cost" />
            <Button variant="outline" as-child><a :href="download.url({ query })"><Download class="size-4" /> Export CSV</a></Button>
        </div>

        <form class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="apply">
            <div class="grid gap-1"><Label for="from">From</Label><Input id="from" v-model="f.from" type="datetime-local" /></div>
            <div class="grid gap-1"><Label for="to">To</Label><Input id="to" v-model="f.to" type="datetime-local" /></div>
            <div class="grid gap-1">
                <Label for="status">Outcome</Label>
                <select id="status" v-model="f.status" :class="selectClass">
                    <option value="">Any</option>
                    <option value="answered">Answered</option>
                    <option value="failed">Not completed</option>
                    <option value="rejected">Refused</option>
                </select>
            </div>
            <div class="grid gap-1"><Label for="number">Number / prefix</Label><Input id="number" v-model="f.number" class="font-mono" /></div>
            <div class="flex items-end"><Button>Apply</Button></div>
        </form>

        <div class="grid gap-4 sm:grid-cols-5">
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Calls</p><p class="text-xl font-semibold tabular-nums">{{ summary.calls.toLocaleString() }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Answered</p><p class="text-xl font-semibold tabular-nums">{{ summary.answered.toLocaleString() }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">ASR</p><p class="text-xl font-semibold tabular-nums">{{ summary.asr === null ? '—' : `${summary.asr}%` }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Minutes</p><p class="text-xl font-semibold tabular-nums">{{ summary.minutes.toLocaleString() }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Cost</p><p class="text-xl font-semibold tabular-nums">{{ m(summary.sell) }}</p></div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Started</th>
                        <th class="px-3 py-2 font-medium">From → To</th>
                        <th class="px-3 py-2 font-medium">Destination</th>
                        <th class="px-3 py-2 font-medium">Result</th>
                        <th class="px-3 py-2 text-right font-medium">Duration</th>
                        <th class="px-3 py-2 text-right font-medium">Rate/min</th>
                        <th class="px-3 py-2 text-right font-medium">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in calls.data" :key="r.id" class="border-t">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ dateTime(r.started_at) }}</td>
                        <td class="px-3 py-1.5 font-mono text-xs">{{ r.caller ?? '?' }} → {{ r.destination ?? r.callee }}</td>
                        <td class="px-3 py-1.5">{{ r.sell_destination ?? '' }}</td>
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            <Badge :variant="r.answered ? 'default' : r.rejected ? 'destructive' : 'secondary'">{{ r.answered ? 'answered' : r.rejected ? 'refused' : 'not completed' }}</Badge>
                            <span v-if="!r.answered" class="ml-1 text-xs text-muted-foreground">{{ r.sip_code }} {{ r.reason }}</span>
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ r.answered ? duration(r.billsec) : '—' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ r.answered ? m(r.sell_rate) : '—' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ r.answered ? m(r.sell_cost) : '—' }}</td>
                    </tr>
                    <tr v-if="calls.data.length === 0">
                        <td colspan="7" class="px-3 py-10 text-center text-muted-foreground">No calls in this range.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="calls.prev_page_url || calls.next_page_url" class="flex justify-between text-sm">
            <Link v-if="calls.prev_page_url" :href="calls.prev_page_url" preserve-scroll class="rounded-md border px-3 py-1 hover:bg-muted">← Newer</Link>
            <span v-else />
            <Link v-if="calls.next_page_url" :href="calls.next_page_url" preserve-scroll class="rounded-md border px-3 py-1 hover:bg-muted">Older →</Link>
        </div>
    </div>
</template>
