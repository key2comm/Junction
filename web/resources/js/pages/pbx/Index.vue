<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { show as showPbx } from '@/routes/accounts/pbx';
import { index } from '@/routes/pbx';

defineProps<{
    accounts: { id: number; name: string; code: string; status: string; extensions: number; ivrs: number; dids: number }[];
    connection: { host: string; sip: string; wss: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'PBX', href: index() }] } });
</script>

<template>
    <Head title="PBX" />

    <div class="space-y-6 p-4">
        <Heading
            title="PBX"
            description="Extensions, IVRs and DIDs belong to a customer account. Calls from extensions to outside numbers use that account's routing plan, rates and funds, like its carrier traffic."
        />

        <div class="grid gap-2 rounded-lg border p-4 text-sm sm:grid-cols-2">
            <div>
                <div class="text-muted-foreground">SIP phones (UDP/TCP)</div>
                <div class="font-mono">{{ connection.sip }}</div>
            </div>
            <div>
                <div class="text-muted-foreground">WebRTC phones (SIP over WebSocket)</div>
                <div class="font-mono">{{ connection.wss }}</div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Customer</th>
                        <th class="px-3 py-2 text-right font-medium">Extensions</th>
                        <th class="px-3 py-2 text-right font-medium">IVRs</th>
                        <th class="px-3 py-2 text-right font-medium">DIDs</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.id" class="border-t">
                        <td class="px-3 py-2">
                            <Link :href="showPbx(a.id)" class="font-medium hover:underline">{{ a.name }}</Link>
                            <span class="ml-2 font-mono text-xs text-muted-foreground">{{ a.code }}</span>
                            <Badge v-if="a.status !== 'active'" variant="secondary" class="ml-2">{{ a.status }}</Badge>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ a.extensions }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ a.ivrs }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ a.dids }}</td>
                    </tr>
                    <tr v-if="accounts.length === 0">
                        <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">No customer accounts yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
