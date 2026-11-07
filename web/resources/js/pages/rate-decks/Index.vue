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

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Rate decks" description="Price lists by destination prefix: sell decks for customers, buy decks for vendor trunks" />
            <Button as-child><Link :href="create()"><Plus class="size-4" /> New rate deck</Link></Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Deck</th>
                        <th class="px-4 py-2 font-medium">Currency</th>
                        <th class="px-4 py-2 text-right font-medium">Current rates</th>
                        <th class="px-4 py-2 font-medium">Used by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in decks" :key="d.id" class="border-t hover:bg-muted/30">
                        <td class="px-4 py-2">
                            <Link :href="show(d.id)" class="font-medium hover:underline">{{ d.name }}</Link>
                            <div v-if="d.description" class="text-xs text-muted-foreground">{{ d.description }}</div>
                        </td>
                        <td class="px-4 py-2">{{ d.currency }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ d.current_rates_count.toLocaleString() }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            <span v-if="d.customers_count">{{ d.customers_count }} customer(s)</span>
                            <span v-if="d.customers_count && d.trunks_count">, </span>
                            <span v-if="d.trunks_count">{{ d.trunks_count }} trunk(s)</span>
                            <span v-if="!d.customers_count && !d.trunks_count">Not in use</span>
                        </td>
                    </tr>
                    <tr v-if="decks.length === 0">
                        <td colspan="4" class="px-4 py-10 text-center text-muted-foreground">No rate decks yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
