<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { PhoneOff } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    customer: { id: number; code: string; name: string } | null;
    vendor: string | null;
    trunk: { id: number; name: string } | null;
    gateway: string | null;
    talking_seconds: number | null;
    max_duration: number | null;
    remaining_seconds: number | null;
};

const props = defineProps<{ snapshot: { at: string | null; calls: Call[] } }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Live calls', href: index() }] } });

// Reload every 3 s; the server refreshes its snapshot every 5 s while we watch.
usePoll(3000, { only: ['snapshot'] });

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

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading title="Live calls" description="Calls in progress through the switch" />
            <p class="text-xs text-muted-foreground">
                <span v-if="snapshot.at">Updated {{ age }}s ago</span>
                <span v-else>Waiting for the first snapshot…</span>
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">In progress</p><p class="text-2xl font-semibold tabular-nums">{{ counts.total }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Answered</p><p class="text-2xl font-semibold tabular-nums">{{ counts.answered }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Routing / ringing</p><p class="text-2xl font-semibold tabular-nums">{{ counts.ringing }}</p></div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 font-medium">From → To</th>
                        <th class="px-3 py-2 font-medium">State</th>
                        <th class="px-3 py-2 font-medium">Vendor</th>
                        <th class="px-3 py-2 text-right font-medium">Talking</th>
                        <th class="px-3 py-2 text-right font-medium">Cut-off in</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in snapshot.calls" :key="c.uuid" class="border-t">
                        <td class="px-3 py-1.5">
                            <Link v-if="c.customer" :href="showAccount(c.customer.id)" class="hover:underline">{{ c.customer.code }}</Link>
                            <span v-else class="text-muted-foreground">unidentified</span>
                        </td>
                        <td class="px-3 py-1.5 font-mono text-xs">{{ c.caller ?? '?' }} → {{ c.dialled }}</td>
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            <Badge :variant="c.state === 'answered' ? 'default' : 'secondary'">{{ c.state }}</Badge>
                            <span v-if="c.state !== 'answered'" class="ml-1 text-xs text-muted-foreground tabular-nums">{{ fmt(elapsed(c.started_at)) }}</span>
                        </td>
                        <td class="px-3 py-1.5 text-xs">
                            <template v-if="c.trunk">
                                {{ c.vendor }} / <Link :href="showTrunk(c.trunk.id)" class="hover:underline">{{ c.trunk.name }}</Link>
                                <span class="font-mono text-muted-foreground"> {{ c.gateway }}</span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ fmt(since(c.talking_seconds)) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-muted-foreground">
                            {{ c.remaining_seconds !== null ? fmt(Math.max(0, c.remaining_seconds - (age ?? 0))) : '—' }}
                        </td>
                        <td class="px-3 py-1.5 text-right">
                            <Button size="sm" :variant="armed === c.uuid ? 'destructive' : 'ghost'" @click="hangup(c.uuid)" :aria-label="`Hang up call from ${c.caller}`">
                                <PhoneOff class="size-4" /><span v-if="armed === c.uuid">Confirm</span>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="snapshot.calls.length === 0">
                        <td colspan="7" class="px-3 py-10 text-center text-muted-foreground">No calls in progress.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
