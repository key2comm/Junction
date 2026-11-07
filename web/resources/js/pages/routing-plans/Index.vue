<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/routing-plans';

defineProps<{
    plans: { id: number; name: string; mode: string; max_attempts: number; description: string | null; routes_count: number; accounts_count: number }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Routing plans', href: index() }] } });

const modeLabel: Record<string, string> = { lcr: 'Least cost', priority: 'Priority', weight: 'Load balance' };
</script>

<template>
    <Head title="Routing plans" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Routing plans" description="Which vendor trunks may carry which destinations, assigned to customers" />
            <Button as-child><Link :href="create()"><Plus class="size-4" /> New routing plan</Link></Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Plan</th>
                        <th class="px-4 py-2 font-medium">Mode</th>
                        <th class="px-4 py-2 text-right font-medium">Max attempts</th>
                        <th class="px-4 py-2 text-right font-medium">Routes</th>
                        <th class="px-4 py-2 text-right font-medium">Customers</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in plans" :key="p.id" class="border-t hover:bg-muted/30">
                        <td class="px-4 py-2">
                            <Link :href="show(p.id)" class="font-medium hover:underline">{{ p.name }}</Link>
                            <div v-if="p.description" class="text-xs text-muted-foreground">{{ p.description }}</div>
                        </td>
                        <td class="px-4 py-2"><Badge variant="outline">{{ modeLabel[p.mode] ?? p.mode }}</Badge></td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ p.max_attempts }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ p.routes_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ p.accounts_count }}</td>
                    </tr>
                    <tr v-if="plans.length === 0">
                        <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">No routing plans yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
