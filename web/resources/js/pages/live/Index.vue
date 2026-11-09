<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PhoneOff } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import LiveCallsController from '@/actions/App/Http/Controllers/Admin/LiveCallsController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as showAccount } from '@/routes/accounts';
import { index } from '@/routes/live';
import { show as showTrunk } from '@/routes/trunks';

type Call = {
    uuid: string;
    started_at: string;
    state: 'routing' | 'ringing' | 'answered';
    caller: string | null;
    dialled: string | null;
    sent_as: string | null;
    direction: string | null;
    direction_label: string | null;
    source: string | null;
    customer: { id: number; code: string; name: string } | null;
    endpoint: { id: number; name: string } | null;
    matched_by: string | null;
    did: string | null;
    codec: string | null;
    vendor: string | null;
    vendor_name: string | null;
    trunk: { id: number; name: string } | null;
    gateway: string | null;
    talking_seconds: number | null;
    max_duration: number | null;
    remaining_seconds: number | null;
};

const props = defineProps<{ snapshot: { at: string | null; calls: Call[] } }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Live calls', href: index() }] } });

// Reload every 5 s by default (the server refreshes its snapshot every 5 s
// while someone watches); the choice is remembered in this browser.
const RATES = [5, 25, 40, 60];
const STORE = 'softswitch.live.refresh';
const stored = (() => {
    try {
        return Number(localStorage.getItem(STORE));
    } catch {
        return 0;
    }
})();
const refreshEvery = ref(RATES.includes(stored) ? stored : 5);
let poller: ReturnType<typeof setInterval> | undefined;
function startPolling() {
    clearInterval(poller);
    poller = setInterval(() => router.reload({ only: ['snapshot'] }), refreshEvery.value * 1000);
}
watch(refreshEvery, (s) => {
    try {
        localStorage.setItem(STORE, String(s));
    } catch {
        // not remembered (private window etc.)
    }
    startPolling();
});
onMounted(startPolling);
onBeforeUnmount(() => clearInterval(poller));

// Tick durations locally between snapshots.
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(timer));
const age = computed(() => (props.snapshot.at ? Math.max(0, Math.round((now.value - new Date(props.snapshot.at).getTime()) / 1000)) : null));
const since = (base: number | null) => (base === null ? null : base + (age.value ?? 0));
const fmt = (s: number | null) => {
    if (s === null) return '—';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return h ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}` : `${m}:${String(sec).padStart(2, '0')}`;
};
const elapsed = (iso: string) => Math.max(0, Math.round((now.value - new Date(iso).getTime()) / 1000));

const counts = computed(() => ({
    total: props.snapshot.calls.length,
    answered: props.snapshot.calls.filter((c) => c.state === 'answered').length,
    ringing: props.snapshot.calls.filter((c) => c.state !== 'answered').length,
}));

// Two-step hang up: first click arms, second click (within 5 s) hangs up.
const armed = ref<string | null>(null);
function hangup(uuid: string) {
    if (armed.value !== uuid) {
        armed.value = uuid;
        setTimeout(() => armed.value === uuid && (armed.value = null), 5000);
        return;
    }
    armed.value = null;
    router.post(LiveCallsController.hangup.url(uuid), {}, { preserveScroll: true, only: ['snapshot'] });
}
</script>

<template>
    <Head title="Live calls" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
            <Heading title="Live calls" description="Calls in progress through the switch" />
            <div class="d-flex align-items-center gap-3 small text-secondary">
                <span v-if="snapshot.at">Updated {{ age }}s ago</span>
                <span v-else>Waiting for the first snapshot…</span>
                <label class="d-flex align-items-center gap-2 mb-0">
                    Refresh every
                    <select v-model.number="refreshEvery" class="form-select form-select-sm w-auto">
                        <option v-for="s in RATES" :key="s" :value="s">{{ s }} seconds</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="ss-grid gap-3 ss-cols-sm-3">
            <div class="card card-body p-2"><p class="small text-secondary">In progress</p><p class="fs-1 fw-semibold tabular-nums">{{ counts.total }}</p></div>
            <div class="card card-body p-2"><p class="small text-secondary">Answered</p><p class="fs-1 fw-semibold tabular-nums">{{ counts.answered }}</p></div>
            <div class="card card-body p-2"><p class="small text-secondary">Routing / ringing</p><p class="fs-1 fw-semibold tabular-nums">{{ counts.ringing }}</p></div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>From → To</th>
                        <th>State</th>
                        <th>Carrier</th>
                        <th class="text-end">Talking</th>
                        <th class="text-end">Cut-off in</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in snapshot.calls" :key="c.uuid">
                        <td>
                            <Link v-if="c.customer" :href="showAccount(c.customer.id)">{{ c.customer.code }}</Link>
                            <span v-else class="text-secondary">unidentified</span>
                        </td>
                        <td class="small">
                            <span class="font-monospace">{{ c.caller ?? '?' }} → {{ c.dialled }}</span>
                            <div class="text-secondary">
                                {{ c.direction_label ?? '' }}<template v-if="c.did"> · DID {{ c.did }}</template>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <Badge :variant="c.state === 'answered' ? 'default' : 'secondary'">{{ c.state }}</Badge>
                            <span v-if="c.state !== 'answered'" class="ms-1 small text-secondary tabular-nums">{{ fmt(elapsed(c.started_at)) }}</span>
                        </td>
                        <td class="small">
                            <Link v-if="c.trunk" :href="showTrunk(c.trunk.id)">{{ c.vendor_name ?? c.vendor }}</Link>
                            <span v-else class="text-secondary">—</span>
                        </td>
                        <td class="text-end tabular-nums">{{ fmt(since(c.talking_seconds)) }}</td>
                        <td class="text-end tabular-nums text-secondary">
                            {{ c.remaining_seconds !== null ? fmt(Math.max(0, c.remaining_seconds - (age ?? 0))) : '—' }}
                        </td>
                        <td class="text-end">
                            <Button size="sm" :variant="armed === c.uuid ? 'destructive' : 'ghost'" @click="hangup(c.uuid)" :aria-label="`Hang up call from ${c.caller}`">
                                <PhoneOff class="icon" /><span v-if="armed === c.uuid">Confirm</span>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="snapshot.calls.length === 0">
                        <td colspan="7" class="text-center text-secondary">No calls in progress.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
