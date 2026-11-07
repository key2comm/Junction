<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as showAccount } from '@/routes/accounts';
import { show as showDeck } from '@/routes/rate-decks';
import { create, index, show } from '@/routes/trunks';

defineProps<{
    trunks: {
        id: number;
        name: string;
        enabled: boolean;
        max_channels: number | null;
        max_cps: string | null;
        gateways_count: number;
        routes_count: number;
        vendor: { id: number; name: string; code: string };
        rate_deck: { id: number; name: string } | null;
    }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Trunks', href: index() }] } });
</script>

<template>
    <Head title="Trunks" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="Carrier trunks" description="Where traffic goes: each trunk is a carrier product with its carrier rates and one or more gateways" />
            <Button as-child><Link :href="create()"><Plus class="icon" /> New trunk</Link></Button>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">Carrier</th>
                        <th class="fw-medium">Trunk</th>
                        <th class="fw-medium">Carrier rates</th>
                        <th class="text-end fw-medium">Gateways</th>
                        <th class="text-end fw-medium">Routes</th>
                        <th class="fw-medium">Limits</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in trunks" :key="t.id" :class="{ 'opacity-50': !t.enabled }">
                        <td><Link :href="showAccount(t.vendor.id)">{{ t.vendor.name }}</Link></td>
                        <td>
                            <Link :href="show(t.id)" class="fw-medium">{{ t.name }}</Link>
                            <Badge v-if="!t.enabled" variant="secondary" class="ms-2">Disabled</Badge>
                        </td>
                        <td>
                            <Link v-if="t.rate_deck" :href="showDeck(t.rate_deck.id)">{{ t.rate_deck.name }}</Link>
                            <span v-else class="text-danger">None — can't be used for LCR</span>
                        </td>
                        <td class="text-end tabular-nums" :class="{ 'text-danger': t.gateways_count === 0 }">{{ t.gateways_count }}</td>
                        <td class="text-end tabular-nums">{{ t.routes_count }}</td>
                        <td class="text-secondary">{{ t.max_channels ?? '∞' }} ch, {{ t.max_cps ?? '∞' }} CPS</td>
                    </tr>
                    <tr v-if="trunks.length === 0">
                        <td colspan="6" class="text-center text-secondary">No trunks yet. Add a carrier under Routing → Carriers first, then add its trunks.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
