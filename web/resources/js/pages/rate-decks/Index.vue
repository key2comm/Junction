<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { deckKind } from '@/lib/rateDecks';
import { create, show } from '@/routes/rate-decks';

const props = defineProps<{
    kind: 'tariff' | 'carrier';
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

defineOptions({
    layout: (props: { kind: 'tariff' | 'carrier' }) => ({ breadcrumbs: [{ title: deckKind(props.kind).plural, href: deckKind(props.kind).index() }] }),
});

const k = computed(() => deckKind(props.kind));
const used = (d: (typeof props.decks)[number]) =>
    props.kind === 'tariff' ? (d.customers_count ? `${d.customers_count} customer(s)` : 'Not in use') : d.trunks_count ? `${d.trunks_count} trunk(s)` : 'Not in use';
</script>

<template>
    <Head :title="k.plural" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading :title="k.plural" :description="k.description" />
            <Button as-child><Link :href="create({ query: { kind } })"><Plus class="icon" /> New {{ k.singular.toLowerCase() }}</Link></Button>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">Name</th>
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
                        <td class="text-secondary">{{ used(d) }}</td>
                    </tr>
                    <tr v-if="decks.length === 0">
                        <td colspan="4" class="text-center text-secondary">No {{ k.plural.toLowerCase() }} yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
