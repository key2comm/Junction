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

    <div class="ss-stack-4">
        <Heading
            title="PBX"
            description="Extensions, IVRs and DIDs belong to a customer account. Calls from extensions to outside numbers use that account's routing plan, rates and funds, like its carrier traffic."
        />

        <div class="card card-body ss-grid gap-2 ss-cols-sm-2">
            <div>
                <div class="text-secondary">SIP phones (UDP/TCP)</div>
                <div class="font-monospace">{{ connection.sip }}</div>
            </div>
            <div>
                <div class="text-secondary">WebRTC phones (SIP over WebSocket)</div>
                <div class="font-monospace">{{ connection.wss }}</div>
            </div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th class="text-end">Extensions</th>
                        <th class="text-end">IVRs</th>
                        <th class="text-end">DIDs</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.id">
                        <td>
                            <Link :href="showPbx(a.id)" class="fw-medium">{{ a.name }}</Link>
                            <span class="ms-2 font-monospace small text-secondary">{{ a.code }}</span>
                            <Badge v-if="a.status !== 'active'" variant="secondary" class="ms-2">{{ a.status }}</Badge>
                        </td>
                        <td class="text-end tabular-nums">{{ a.extensions }}</td>
                        <td class="text-end tabular-nums">{{ a.ivrs }}</td>
                        <td class="text-end tabular-nums">{{ a.dids }}</td>
                    </tr>
                    <tr v-if="accounts.length === 0">
                        <td colspan="4" class="py-3 text-center text-secondary">No customer accounts yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
