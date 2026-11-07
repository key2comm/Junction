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

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="Routing plans" description="Which carrier trunks may carry which destinations, assigned to customers" />
            <Button as-child><Link :href="create()"><Plus class="icon" /> New routing plan</Link></Button>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th class="fw-medium">Plan</th>
                        <th class="fw-medium">Mode</th>
                        <th class="text-end fw-medium">Max attempts</th>
                        <th class="text-end fw-medium">Routes</th>
                        <th class="text-end fw-medium">Customers</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in plans" :key="p.id">
                        <td>
                            <Link :href="show(p.id)" class="fw-medium">{{ p.name }}</Link>
                            <div v-if="p.description" class="small text-secondary">{{ p.description }}</div>
                        </td>
                        <td><Badge variant="outline">{{ modeLabel[p.mode] ?? p.mode }}</Badge></td>
                        <td class="text-end tabular-nums">{{ p.max_attempts }}</td>
                        <td class="text-end tabular-nums">{{ p.routes_count }}</td>
                        <td class="text-end tabular-nums">{{ p.accounts_count }}</td>
                    </tr>
                    <tr v-if="plans.length === 0">
                        <td colspan="5" class="text-center text-secondary">No routing plans yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
