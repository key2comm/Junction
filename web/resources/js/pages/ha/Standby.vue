<script setup lang="ts">
/**
 * High availability: the backup server's only page. Shows how in sync it is
 * with the main server; Owners and Admins can make it the main server.
 */
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { logout } from '@/routes';
import { promote as promoteRoute } from '@/routes/ha';

const props = defineProps<{
    main: { streaming: boolean; main: string | null; behind: number | null; last_message: string | null; healthy: boolean };
    files: { at: string; changed: number } | null;
    promotion: { status: 'requested' | 'running' | 'done'; at?: string } | null;
    canPromote: boolean;
    maxLag: number;
}>();

defineOptions({
    layout: { title: 'Backup server', description: "This server keeps a live copy of the main server's database and files. It doesn't take calls." },
});

usePoll(10000);

const ago = (s: number | null) => (s === null ? 'unknown' : s < 120 ? `${s} seconds` : s < 7200 ? `${Math.round(s / 60)} minutes` : `${Math.round(s / 3600)} hours`);
const busy = computed(() => props.promotion?.status === 'requested' || props.promotion?.status === 'running');

function promote() {
    const message =
        'Make this the main server?\n\n' +
        "It stops copying from the main server and starts taking calls. Do this only if the main server is down or you're retiring it: " +
        'if both run as the main server, they will disagree. Afterwards, point your DNS name at this server.';
    if (confirm(message)) {
        router.post(promoteRoute.url());
    }
}
</script>

<template>
    <Head title="Backup server" />

    <div class="ss-stack-3">
        <dl class="ss-grid ss-gtc-auto-1fr gap-2 column-gap-3 mb-0">
            <dt class="text-secondary fw-normal">Main server</dt>
            <dd class="mb-0 font-monospace">{{ main.main ?? '—' }}</dd>
            <dt class="text-secondary fw-normal">Sync</dt>
            <dd class="mb-0">
                <Badge v-if="main.healthy" variant="outline" class="text-success">In sync</Badge>
                <Badge v-else-if="main.streaming" variant="outline" class="text-warning">Behind</Badge>
                <Badge v-else variant="destructive">Can't reach the main server</Badge>
            </dd>
            <dt class="text-secondary fw-normal">Newest data</dt>
            <dd class="mb-0">{{ main.behind === null ? 'none yet' : `${ago(main.behind)} old` }}</dd>
            <dt class="text-secondary fw-normal">Files</dt>
            <dd class="mb-0">{{ files ? `checked ${dateTime(files.at)}` : 'not checked yet' }}</dd>
        </dl>

        <div v-if="promotion?.status === 'done'" class="alert alert-success mb-0">
            This server is now the main server. Point your DNS name at it, then <a href="/dashboard">open the dashboard</a>.
        </div>
        <div v-else-if="busy" class="alert alert-info mb-0">Making this the main server… This takes about a minute.</div>
        <template v-else-if="canPromote">
            <p class="text-secondary small mb-0">
                If the main server is down, make this the main server. It starts taking calls straight away; then point your DNS name at this
                server's IP. Later, turn the old main server into the new backup with <code>sudo softswitch-ha clone</code> on it.
            </p>
            <Button :variant="main.healthy ? 'outline' : 'default'" class="w-100" @click="promote">Make this the main server</Button>
        </template>
        <p v-else class="text-secondary small mb-0">An Owner or Admin can make this backup the main server.</p>

        <Link :href="logout()" as="button" class="btn btn-link w-100">Log out</Link>
    </div>
</template>
