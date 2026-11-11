<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, CalendarDays, Cpu, HardDrive, MemoryStick, PhoneCall, PhoneIncoming } from '@lucide/vue';
import CallBars from '@/components/dashboard/CallBars.vue';
import type { CallBar } from '@/components/dashboard/CallBars.vue';
import CallLegend from '@/components/dashboard/CallLegend.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { useBilling } from '@/composables/useBilling';
import { date, money } from '@/lib/format';
import { dashboard } from '@/routes';
import { show as showAccount } from '@/routes/accounts';
import { index as alertsIndex } from '@/routes/alerts';
import { index as cdrIndex } from '@/routes/cdrs';
import { edit as editEmail } from '@/routes/email';
import { show as showInvoice } from '@/routes/invoices';
import { show as showVendorInvoice } from '@/routes/vendor-invoices';

type Counts = { calls: number; answered: number; minutes: number };
type System = {
    at: string;
    cpu: number | null;
    cpu_history: [number, number][];
    cores: number;
    load: [number, number, number];
    memory: { total: number; used: number };
    swap: { total: number; used: number };
    disk: { total: number; used: number };
    uptime: number;
};

const props = defineProps<{
    today: Counts;
    month: Counts;
    week: { day: string; calls: number; answered: number }[];
    hours: { hour: string; calls: number; answered: number }[];
    system: System | null;
    last24h: { calls: number; answered: number; rejected: number; asr: number | null; acd: number | null; minutes: number; sell: string; buy: string; margin: string };
    activeCalls: number;
    lowBalance: { id: number; code: string; name: string; currency: string; low_balance_threshold: string; low_balance_alerted_at: string; available: string }[];
    unpaid: { id: number; number: string; account_code: string; account_name: string; currency: string; amount_due: string; due_at: string | null }[];
    disputes: { id: number; reference: string; status: string; currency: string; vendor: string; difference: string }[];
    alerts: { id: number; type: string; severity: string; status: string; title: string; action_taken: string | null; opened_at: string }[];
    mailConfigured: boolean;
    currency: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] } });

const billing = useBilling();
const duration = (s: number | null) => (s === null ? '—' : s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
const overdue = (iso: string | null) => iso !== null && new Date(iso) < new Date();
const n = (v: number) => v.toLocaleString();
const pct = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 100) : 0);

// Live figures refresh on their own; system stats are sampled once a minute.
usePoll(30000, { only: ['activeCalls', 'today', 'month', 'week', 'hours', 'last24h', 'system'] });

// --- Charts: days and hours in the viewer's own time zone (the server buckets them). ---
const weekTotal = computed(() => props.week.reduce((t, d) => t + d.calls, 0));
const weekBars = computed<CallBar[]>(() =>
    props.week.map((d, i) => {
        const day = new Date(`${d.day}T12:00:00Z`);
        const today = i === props.week.length - 1;

        return {
            key: d.day,
            calls: d.calls,
            answered: d.answered,
            label: today ? 'Today' : day.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' }),
            long: day.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', timeZone: 'UTC' }),
            highlight: today,
        };
    }),
);
const hourBars = computed<CallBar[]>(() =>
    props.hours.map((d, i) => {
        const [day, hh] = d.hour.split(' ');
        const now = i === props.hours.length - 1;
        const at = new Date(`${day}T${hh}:00:00Z`);
        const time = at.toLocaleTimeString(undefined, { hour: 'numeric', timeZone: 'UTC' });

        return {
            key: d.hour,
            calls: d.calls,
            answered: d.answered,
            label: now ? 'Now' : time,
            long: `${at.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' })} ${time} – ${new Date(at.getTime() + 3600000).toLocaleTimeString(undefined, { hour: 'numeric', timeZone: 'UTC' })}`,
            highlight: now,
        };
    }),
);
const failed24h = computed(() => props.last24h.calls - props.last24h.answered);

// --- Server ---
const bytes = (b: number) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1024 && i < units.length - 1) {
        b /= 1024;
        i++;
    }

    return `${b.toFixed(i >= 3 ? 1 : 0)} ${units[i]}`;
};
const uptime = (s: number) => {
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);

    return d > 0 ? `${d}d ${h}h` : `${h}h ${Math.floor((s % 3600) / 60)}m`;
};
/** Bar colour and word for a usage level; colour is never the only signal. */
const level = (p: number) => (p >= 90 ? { bar: 'bg-danger', word: 'High', text: 'text-danger' } : p >= 75 ? { bar: 'bg-warning', word: 'Busy', text: 'text-warning' } : { bar: 'bg-primary', word: '', text: '' });
const meters = computed(() => {
    const s = props.system;
    if (!s) {
        return [];
    }

    return [
        { key: 'cpu', icon: Cpu, label: 'CPU', value: s.cpu ?? 0, pending: s.cpu === null, detail: s.cpu === null ? 'Measuring…' : `${s.cores} core${s.cores === 1 ? '' : 's'}, average over the last minute` },
        { key: 'mem', icon: MemoryStick, label: 'Memory', value: pct(s.memory.used, s.memory.total), pending: false, detail: `${bytes(s.memory.used)} of ${bytes(s.memory.total)}` },
        { key: 'disk', icon: HardDrive, label: 'Disk', value: pct(s.disk.used, s.disk.total), pending: false, detail: `${bytes(s.disk.used)} of ${bytes(s.disk.total)}` },
    ];
});
const spark = computed(() => {
    const h = props.system?.cpu_history ?? [];
    if (h.length < 2) {
        return null;
    }
    const w = 240;
    const ht = 40;
    const pts = h.map(([, v], i) => `${((i / (h.length - 1)) * w).toFixed(1)},${(ht - (v / 100) * ht).toFixed(1)}`).join(' ');

    return { w, h: ht, line: pts, area: `0,${ht} ${pts} ${w},${ht}`, peak: Math.max(...h.map(([, v]) => v)) };
});
// Stale when the scheduler hasn't sampled for 5 minutes.
const stale = computed(() => (props.system ? Date.now() - new Date(props.system.at).getTime() > 5 * 60 * 1000 : false));
</script>

<template>
    <Head title="Dashboard" />

    <div class="ss-stack-4">
        <p v-if="!mailConfigured" class="rounded border ss-border-dashed p-2 text-secondary">
            Email isn't set up, so {{ billing ? 'invoices and low-balance alerts' : 'alerts' }} can't be sent.
            <Link :href="editEmail()" class="fw-medium text-body">Set up email</Link>
        </p>

        <section v-if="alerts.length" class="ss-stack-2">
            <div class="d-flex align-items-baseline justify-content-between">
                <Heading variant="small" title="Alerts" />
                <Link :href="alertsIndex()">All alerts →</Link>
            </div>
            <ul class="list-group" :class="{ 'border-danger': alerts.some((a) => a.status === 'open' && a.severity === 'critical') }">
                <li v-for="a in alerts" :key="a.id" class="list-group-item d-flex flex-wrap align-items-center gap-2 px-2 py-2">
                    <Badge :variant="a.severity === 'critical' ? 'destructive' : 'secondary'">{{ a.status }}</Badge>
                    <span>{{ a.title }}</span>
                    <span v-if="a.action_taken" class="text-danger">· {{ a.action_taken }}</span>
                </li>
            </ul>
        </section>

        <!-- Headline numbers -->
        <div class="ss-grid gap-3 ss-cols-sm-2 ss-cols-xl-4">
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-green-lt"><Activity class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">Calls in progress</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-0">{{ n(activeCalls) }}</p>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-primary-lt"><PhoneCall class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">Calls today</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ n(today.calls) }}</p>
                        <p class="small text-secondary mb-0">{{ n(today.answered) }} answered · {{ n(today.minutes) }} min</p>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-azure-lt"><CalendarDays class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">Calls this month</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ n(month.calls) }}</p>
                        <p class="small text-secondary mb-0">{{ n(month.answered) }} answered · {{ n(month.minutes) }} min</p>
                    </div>
                </div>
            </div>
            <div class="card card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="avatar avatar-md bg-purple-lt"><PhoneIncoming class="icon" /></span>
                    <div>
                        <p class="small text-secondary mb-1">ASR, last 24 hours</p>
                        <p class="fs-1 fw-semibold tabular-nums lh-1 mb-1">{{ last24h.asr === null ? '—' : `${last24h.asr}%` }}</p>
                        <p class="small text-secondary mb-0">ACD {{ duration(last24h.acd) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="ss-grid gap-3 ss-gtc-lg-2fr-1fr">
            <!-- Last 7 days -->
            <section class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Calls, last 7 days</h3>
                        <p class="card-subtitle mb-0">{{ n(weekTotal) }} calls</p>
                    </div>
                    <CallLegend class="card-actions" />
                </div>
                <div class="card-body">
                    <CallBars :items="weekBars" title="Calls per day, last 7 days" />
                </div>
            </section>

            <!-- Server -->
            <section class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Server</h3>
                        <p class="card-subtitle mb-0">
                            <template v-if="system">Up {{ uptime(system.uptime) }} · load {{ system.load.map((l) => l.toFixed(2)).join(' / ') }}</template>
                            <template v-else>Waiting for the first reading…</template>
                        </p>
                    </div>
                    <div v-if="stale" class="card-actions"><Badge variant="destructive">Not updating</Badge></div>
                </div>
                <div class="card-body ss-stack-3">
                    <p v-if="!system" class="text-secondary mb-0">The scheduler reads the server's stats once a minute; they'll appear shortly.</p>
                    <div v-for="m in meters" :key="m.key">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <component :is="m.icon" class="icon text-secondary" />
                            <span class="fw-medium">{{ m.label }}</span>
                            <span v-if="!m.pending && level(m.value).word" class="small" :class="level(m.value).text">{{ level(m.value).word }}</span>
                            <span class="ms-auto fw-semibold tabular-nums">{{ m.pending ? '—' : `${m.value}%` }}</span>
                        </div>
                        <div class="progress progress-sm" role="progressbar" :aria-label="`${m.label} usage`" :aria-valuenow="m.value" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" :class="level(m.value).bar" :style="{ width: `${m.value}%` }"></div>
                        </div>
                        <p class="small text-secondary mb-0 mt-1">{{ m.detail }}</p>
                    </div>
                    <div v-if="spark">
                        <p class="small text-secondary mb-1 d-flex justify-content-between"><span>CPU, last hour</span><span>peak {{ spark.peak }}%</span></p>
                        <svg
                            :viewBox="`0 0 ${spark.w} ${spark.h}`"
                            preserveAspectRatio="none"
                            class="w-100 d-block ss-spark"
                            role="img"
                            :aria-label="`CPU over the last hour, peak ${spark.peak}%`"
                        >
                            <polygon :points="spark.area" class="ss-spark-area" />
                            <polyline :points="spark.line" class="ss-spark-line" vector-effect="non-scaling-stroke" />
                        </svg>
                    </div>
                    <p v-if="system && system.swap.total > 0" class="small text-secondary mb-0">Swap {{ bytes(system.swap.used) }} of {{ bytes(system.swap.total) }}</p>
                </div>
            </section>
        </div>

        <!-- Last 24 hours -->
        <section class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Last 24 hours</h3>
                    <p class="card-subtitle mb-0"><Link :href="cdrIndex()">Call records →</Link></p>
                </div>
                <CallLegend class="card-actions" />
            </div>
            <div class="card-body ss-stack-3">
                <div class="ss-grid gap-3 ss-cols-2 ss-cols-md-4">
                    <div class="rounded border p-2">
                        <p class="small text-secondary mb-1">Total calls</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-0">{{ n(last24h.calls) }}</p>
                    </div>
                    <div class="rounded border p-2">
                        <p class="small text-secondary mb-1">Answered</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-1">{{ n(last24h.answered) }}</p>
                        <p class="small text-secondary mb-0">{{ pct(last24h.answered, last24h.calls) }}% of calls · {{ n(last24h.minutes) }} min</p>
                    </div>
                    <div class="rounded border p-2">
                        <p class="small text-secondary mb-1">Failed</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-1" :class="{ 'text-danger': failed24h > 0 && pct(failed24h, last24h.calls) >= 50 }">{{ n(failed24h) }}</p>
                        <p class="small text-secondary mb-0">{{ n(last24h.rejected) }} rejected before routing</p>
                    </div>
                    <div v-if="billing" class="rounded border p-2">
                        <p class="small text-secondary mb-1">Margin</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-1" :class="{ 'text-danger': Number(last24h.margin) < 0 }">{{ money(last24h.margin, currency) }}</p>
                        <p class="small text-secondary mb-0">on {{ money(last24h.sell, currency) }} sold</p>
                    </div>
                    <div v-else class="rounded border p-2">
                        <p class="small text-secondary mb-1">ACD</p>
                        <p class="fs-2 fw-semibold tabular-nums lh-1 mb-0">{{ duration(last24h.acd) }}</p>
                    </div>
                </div>
                <CallBars :items="hourBars" title="Calls per hour, last 24 hours" :height="180" :label-every="3" />
            </div>
        </section>

        <div v-if="billing" class="ss-grid gap-4 ss-cols-lg-3">
            <section class="ss-stack-2">
                <Heading variant="small" title="Low balance" description="Customers below their alert level right now" />
                <ul class="list-group">
                    <li v-for="a in lowBalance" :key="a.id" class="list-group-item d-flex justify-content-between gap-2 px-2 py-2">
                        <Link :href="showAccount(a.id)">{{ a.name }} <span class="text-secondary">{{ a.code }}</span></Link>
                        <span class="tabular-nums" :class="{ 'text-danger': Number(a.available) <= 0 }">{{ money(a.available, a.currency) }}</span>
                    </li>
                    <li v-if="lowBalance.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">Nobody is low.</li>
                </ul>
            </section>

            <section class="ss-stack-2">
                <Heading variant="small" title="Unpaid invoices" description="Postpaid amounts due" />
                <ul class="list-group">
                    <li v-for="i in unpaid" :key="i.id" class="list-group-item d-flex flex-wrap justify-content-between gap-2 px-2 py-2">
                        <span>
                            <Link :href="showInvoice(i.id)" class="font-monospace">{{ i.number }}</Link>
                            <span class="ms-1 text-secondary">{{ i.account_code }}</span>
                            <Badge v-if="overdue(i.due_at)" variant="destructive" class="ms-1">overdue</Badge>
                        </span>
                        <span class="tabular-nums">{{ money(i.amount_due, i.currency) }} <span class="small text-secondary">due {{ date(i.due_at) }}</span></span>
                    </li>
                    <li v-if="unpaid.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">No amounts due.</li>
                </ul>
            </section>

            <section class="ss-stack-2">
                <Heading variant="small" title="Open carrier invoices" description="Waiting to be accepted or in dispute" />
                <ul class="list-group">
                    <li v-for="d in disputes" :key="d.id" class="list-group-item d-flex justify-content-between gap-2 px-2 py-2">
                        <span>
                            <Link :href="showVendorInvoice(d.id)" class="font-monospace">{{ d.reference }}</Link>
                            <span class="ms-1 text-secondary">{{ d.vendor }}</span>
                            <Badge :variant="d.status === 'disputed' ? 'destructive' : 'secondary'" class="ms-1">{{ d.status }}</Badge>
                        </span>
                        <span class="tabular-nums" :class="{ 'text-danger': Number(d.difference) > 0 }">{{ money(d.difference, d.currency) }}</span>
                    </li>
                    <li v-if="disputes.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">Nothing open.</li>
                </ul>
            </section>
        </div>
    </div>
</template>

<style scoped>
.ss-spark {
    height: 40px;
}
.ss-spark-line {
    fill: none;
    stroke: var(--tblr-primary);
    stroke-width: 2;
}
.ss-spark-area {
    fill: rgba(var(--tblr-primary-rgb), 0.12);
}
</style>
