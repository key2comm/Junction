<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/rate-decks';

defineProps<{
    decks: {
        id: number;
        name: string;
        currency: string;
        description: string | null;
        current_rates_count: number;
        customers_count: number;
        trunks_count: number;
    }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Rate decks', href: index() }] } });
</script>

<template>
    <Head title="Rate decks" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="Rate decks" description="Price lists by destination prefix: sell decks for customers, buy decks for vendor trunks" />
            <Button as-child><Link :href="create()"><Plus class="icon" /> New rate deck</Link></Button>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">Deck</th>
                        <th class="fw-medium">Currency</th>
                        <th class="text-end fw-medium">Current rates</th>
                        <th class="fw-medium">Used by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in decks" :key="d.id">
                        <td>
                            <Link :href="show(d.id)" class="fw-medium">{{ d.name }}</Link>
                            <div v-if="d.description" class="small text-secondary">{{ d.description }}</div>
                        </td>
                        <td>{{ d.currency }}</td>
                        <td class="text-end tabular-nums">{{ d.current_rates_count.toLocaleString() }}</td>
                        <td class="text-secondary">
                            <span v-if="d.customers_count">{{ d.customers_count }} customer(s)</span>
                            <span v-if="d.customers_count && d.trunks_count">, </span>
                            <span v-if="d.trunks_count">{{ d.trunks_count }} trunk(s)</span>
                            <span v-if="!d.customers_count && !d.trunks_count">Not in use</span>
                        </td>
                    </tr>
                    <tr v-if="decks.length === 0">
                        <td colspan="4" class="text-center text-secondary">No rate decks yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
