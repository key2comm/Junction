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
import { download as exportCsv, index, show } from '@/routes/cdrs';

type Row = {
    id: number;
    started_at: string;
    caller: string | null;
    callee: string;
    destination: string | null;
    billsec: number;
    sip_code: number | null;
    hangup_cause: string | null;
    attempts: number;
    sell_cost: string | null;
    buy_cost: string | null;
    margin: string | null;
    answered: boolean;
    customer: string | null;
    vendor: string | null;
    trunk: string | null;
};

const props = defineProps<{
    cdrs: { data: Row[]; prev_page_url: string | null; next_page_url: string | null; from: number | null; to: number | null };
    summary: { calls: number; answered: number; rejected: number; asr: number | null; acd: number | null; minutes: number; sell: string; buy: string; margin: string };
    filters: Record<string, string | number>;
    currency: string;
    options: {
        customers: { id: number; code: string; name: string }[];
        vendors: { id: number; code: string; name: string }[];
        trunks: { id: number; label: string }[];
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Call records', href: index() }] } });

// <input type="datetime-local"> works in the browser's local time; the server gets ISO UTC.
const toLocalInput = (iso?: string | number) => {
    if (!iso) return '';
    const d = new Date(String(iso));
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const f = reactive({
    from: toLocalInput(props.filters.from),
    to: toLocalInput(props.filters.to),
    customer: String(props.filters.customer ?? ''),
    vendor: String(props.filters.vendor ?? ''),
    trunk: String(props.filters.trunk ?? ''),
    status: String(props.filters.status ?? ''),
    number: String(props.filters.number ?? ''),
    caller: String(props.filters.caller ?? ''),
    sip: String(props.filters.sip ?? ''),
});

const query = computed(() => {
    const q: Record<string, string> = {};
    for (const [k, v] of Object.entries(f)) {
        if (v === '') continue;
        q[k] = k === 'from' || k === 'to' ? new Date(v).toISOString() : v;
    }
    return q;
});

function apply() {
    router.get(index.url({ query: query.value }), {}, { preserveState: true, replace: true });
}

function preset(hours: number) {
    const now = new Date();
    f.to = toLocalInput(now.toISOString());
    f.from = toLocalInput(new Date(now.getTime() - hours * 3600_000).toISOString());
    apply();
}

const currency = props.currency;
const outcome = (r: Row) => (r.answered ? 'answered' : r.attempts === 0 ? 'rejected' : 'failed');
const variant = (r: Row) => (r.answered ? 'default' : r.attempts === 0 ? 'destructive' : 'secondary');
const duration = (s: number) => (s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
</script>

<template>
    <Head title="Call records" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="Call records" description="Every call, with where it went and what it earned" />
            <Button variant="outline" as-child>
                <a :href="exportCsv.url({ query })"><Download class="icon" /> Export CSV</a>
            </Button>
        </div>

        <form class="ss-grid gap-2 rounded border p-3 ss-cols-sm-2 ss-cols-lg-4" @submit.prevent="apply">
            <div class="ss-grid gap-1">
                <Label for="from">From</Label>
                <Input id="from" v-model="f.from" type="datetime-local" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="to">To</Label>
                <Input id="to" v-model="f.to" type="datetime-local" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="customer">Customer</Label>
                <select id="customer" v-model="f.customer" :class="selectClass">
                    <option value="">Any</option>
                    <option v-for="c in options.customers" :key="c.id" :value="String(c.id)">{{ c.code }} — {{ c.name }}</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="vendor">Vendor</Label>
                <select id="vendor" v-model="f.vendor" :class="selectClass">
                    <option value="">Any</option>
                    <option v-for="v in options.vendors" :key="v.id" :value="String(v.id)">{{ v.code }} — {{ v.name }}</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="trunk">Trunk</Label>
                <select id="trunk" v-model="f.trunk" :class="selectClass">
                    <option value="">Any</option>
                    <option v-for="t in options.trunks" :key="t.id" :value="String(t.id)">{{ t.label }}</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="status">Outcome</Label>
                <select id="status" v-model="f.status" :class="selectClass">
                    <option value="">Any</option>
                    <option value="answered">Answered</option>
                    <option value="failed">Failed (vendors tried)</option>
                    <option value="rejected">Rejected (not routed)</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="number">Number / prefix</Label>
                <Input id="number" v-model="f.number" placeholder="e.g. 4420" class="font-monospace" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="caller">Caller ID</Label>
                <Input id="caller" v-model="f.caller" class="font-monospace" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="sip">SIP code</Label>
                <Input id="sip" v-model="f.sip" type="number" min="100" max="699" placeholder="e.g. 503" />
            </div>
            <div class="d-flex flex-wrap align-items-end gap-2 ss-span-sm-2 ss-span-lg-3">
                <Button>Apply</Button>
                <Button type="button" variant="ghost" @click="preset(1)">Last hour</Button>
                <Button type="button" variant="ghost" @click="preset(24)">24 hours</Button>
                <Button type="button" variant="ghost" @click="preset(24 * 7)">7 days</Button>
            </div>
        </form>

        <div class="ss-grid gap-3 ss-cols-sm-3 ss-cols-lg-6">
            <div class="card card-body p-2">
                <p class="small text-secondary">Calls</p>
                <p class="fs-2 fw-semibold tabular-nums">{{ summary.calls.toLocaleString() }}</p>
                <p class="small text-secondary">{{ summary.rejected.toLocaleString() }} rejected</p>
            </div>
            <div class="card card-body p-2">
                <p class="small text-secondary">ASR</p>
                <p class="fs-2 fw-semibold tabular-nums">{{ summary.asr === null ? '—' : `${summary.asr}%` }}</p>
                <p class="small text-secondary">of routed calls</p>
            </div>
            <div class="card card-body p-2">
                <p class="small text-secondary">ACD</p>
                <p class="fs-2 fw-semibold tabular-nums">{{ summary.acd === null ? '—' : duration(summary.acd) }}</p>
            </div>
            <div class="card card-body p-2">
                <p class="small text-secondary">Minutes</p>
                <p class="fs-2 fw-semibold tabular-nums">{{ summary.minutes.toLocaleString() }}</p>
            </div>
            <div class="card card-body p-2">
                <p class="small text-secondary">Sell / buy</p>
                <p class="fw-semibold tabular-nums">{{ money(summary.sell, currency) }}</p>
                <p class="tabular-nums text-secondary">{{ money(summary.buy, currency) }}</p>
            </div>
            <div class="card card-body p-2">
                <p class="small text-secondary">Margin</p>
                <p class="fs-2 fw-semibold tabular-nums" :class="{ 'text-danger': Number(summary.margin) < 0 }">{{ money(summary.margin, currency) }}</p>
            </div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Started</th>
                        <th>Customer</th>
                        <th>From → To</th>
                        <th>Outcome</th>
                        <th>Vendor</th>
                        <th class="text-end">Duration</th>
                        <th class="text-end">Sell</th>
                        <th class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in cdrs.data" :key="r.id">
                        <td class="px-2 py-1 text-nowrap">
                            <Link :href="show(r.id)">{{ dateTime(r.started_at) }}</Link>
                        </td>
                        <td class="px-2 py-1">{{ r.customer ?? '—' }}</td>
                        <td class="px-2 py-1 font-monospace small">{{ r.caller ?? '?' }} → {{ r.destination ?? r.callee }}</td>
                        <td class="px-2 py-1 text-nowrap">
                            <Badge :variant="variant(r)">{{ outcome(r) }}</Badge>
                            <span class="ms-1 small text-secondary">{{ r.sip_code ?? '' }} {{ r.answered ? '' : r.hangup_cause }}</span>
                        </td>
                        <td class="px-2 py-1 small">
                            <span v-if="r.vendor">{{ r.vendor }} / {{ r.trunk }}</span>
                            <span v-else class="text-secondary">{{ r.attempts ? `${r.attempts} tried` : '—' }}</span>
                        </td>
                        <td class="px-2 py-1 text-end tabular-nums">{{ r.answered ? duration(r.billsec) : '—' }}</td>
                        <td class="px-2 py-1 text-end tabular-nums">{{ r.answered ? money(r.sell_cost, currency) : '—' }}</td>
                        <td class="px-2 py-1 text-end tabular-nums" :class="{ 'text-danger': Number(r.margin) < 0 }">{{ r.margin !== null ? money(r.margin, currency) : '—' }}</td>
                    </tr>
                    <tr v-if="cdrs.data.length === 0">
                        <td colspan="8" class="px-2 py-5 text-center text-secondary">No calls in this range.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="cdrs.prev_page_url || cdrs.next_page_url" class="d-flex justify-content-between">
            <Link v-if="cdrs.prev_page_url" :href="cdrs.prev_page_url" preserve-scroll class="rounded border px-2 py-1">← Newer</Link>
            <span v-else />
            <Link v-if="cdrs.next_page_url" :href="cdrs.next_page_url" preserve-scroll class="rounded border px-2 py-1">Older →</Link>
        </div>
    </div>
</template>
