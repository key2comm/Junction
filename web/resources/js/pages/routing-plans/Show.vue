<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import RoutingPlanController from '@/actions/App/Http/Controllers/Admin/RoutingPlanController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Paginated } from '@/lib/ui';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { edit, index, show } from '@/routes/routing-plans';
import { show as showTrunk } from '@/routes/trunks';

type Route = {
    id: number;
    prefix: string;
    priority: number;
    weight: number;
    enabled: boolean;
    trunk: { id: number; name: string; enabled: boolean; vendor: string };
};

const props = defineProps<{
    plan: { id: number; name: string; mode: string; max_attempts: number; description: string | null };
    routes: Paginated<Route>;
    filters: { q: string };
    trunks: { id: number; label: string }[];
    customers: { id: number; name: string; code: string }[];
}>();

defineOptions({
    layout: (props: { plan: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Routing plans', href: index() },
            { title: props.plan.name, href: show(props.plan.id) },
        ],
    }),
});

const modeLabel: Record<string, string> = { lcr: 'Least cost', priority: 'Priority', weight: 'Load balance' };

const q = ref(props.filters.q);
let timer: ReturnType<typeof setTimeout> | undefined;
function search() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(show.url(props.plan.id, { query: { q: q.value || undefined } }), {}, { preserveState: true, replace: true, only: ['routes', 'filters'] }), 300);
}

function toggle(route: Route) {
    router.patch(RoutingPlanController.updateRoute.url([props.plan.id, route.id]), { enabled: !route.enabled }, { preserveScroll: true, only: ['routes'] });
}
</script>

<template>
    <Head :title="props.plan.name" />

    <div class="space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="plan.name" :description="plan.description ?? undefined" />
                <div class="flex gap-1">
                    <Badge variant="outline">{{ modeLabel[plan.mode] ?? plan.mode }}</Badge>
                    <Badge variant="outline">up to {{ plan.max_attempts }} attempts</Badge>
                </div>
            </div>
            <Button variant="outline" as-child><Link :href="edit(plan.id)"><Pencil class="size-4" /> Edit</Link></Button>
        </div>

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Routes"
                description="For each call, the longest matching prefix wins, and its trunks are tried in the plan's order. A blank prefix matches every destination."
            />

            <Form
                v-bind="RoutingPlanController.storeRoute.form(plan.id)"
                :options="{ preserveScroll: true }"
                class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_2fr_auto_auto_auto] sm:items-start"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-1">
                    <Input name="prefix" placeholder="Prefix (blank = all)" class="font-mono" aria-label="Prefix" />
                    <InputError :message="errors.prefix" />
                </div>
                <div class="grid gap-1">
                    <select name="trunk_id" :class="selectClass" required aria-label="Trunk">
                        <option value="" disabled selected>Choose a trunk</option>
                        <option v-for="t in trunks" :key="t.id" :value="t.id">{{ t.label }}</option>
                    </select>
                    <InputError :message="errors.trunk_id" />
                </div>
                <div class="grid gap-1">
                    <Input name="priority" type="number" min="1" max="100" default-value="1" class="w-24" aria-label="Priority" title="Priority (lower first)" />
                    <InputError :message="errors.priority" />
                </div>
                <div class="grid gap-1">
                    <Input name="weight" type="number" min="1" max="1000" default-value="100" class="w-24" aria-label="Weight" title="Weight (load balance share)" />
                    <InputError :message="errors.weight" />
                </div>
                <Button :disabled="processing || trunks.length === 0">Add route</Button>
            </Form>

            <Input v-model="q" placeholder="Filter by prefix" class="max-w-xs font-mono" @input="search" />

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Prefix</th>
                            <th class="px-3 py-2 font-medium">Trunk</th>
                            <th class="px-3 py-2 text-right font-medium">Priority</th>
                            <th class="px-3 py-2 text-right font-medium">Weight</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in routes.data" :key="r.id" class="border-t" :class="{ 'opacity-50': !r.enabled || !r.trunk.enabled }">
                            <td class="px-3 py-1.5 font-mono">{{ r.prefix || '(all)' }}</td>
                            <td class="px-3 py-1.5">
                                <Link :href="showTrunk(r.trunk.id)" class="hover:underline">{{ r.trunk.vendor }} / {{ r.trunk.name }}</Link>
                                <Badge v-if="!r.trunk.enabled" variant="secondary" class="ml-2">trunk disabled</Badge>
                            </td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ r.priority }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ r.weight }}</td>
                            <td class="px-3 py-1.5 text-right whitespace-nowrap">
                                <Button size="sm" variant="ghost" @click="toggle(r)">{{ r.enabled ? 'Disable' : 'Enable' }}</Button>
                                <Form v-bind="RoutingPlanController.destroyRoute.form([plan.id, r.id])" :options="{ preserveScroll: true }" class="inline" v-slot="{ processing }">
                                    <Button size="sm" variant="ghost" :disabled="processing" aria-label="Remove route"><Trash2 class="size-4" /></Button>
                                </Form>
                            </td>
                        </tr>
                        <tr v-if="routes.data.length === 0">
                            <td colspan="5" class="px-3 py-8 text-center text-muted-foreground">
                                {{ trunks.length === 0 ? 'Create a vendor trunk first, then route prefixes to it.' : 'No routes yet.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="routes.links" :from="routes.from" :to="routes.to" :total="routes.total" />
        </section>

        <section class="space-y-2 text-sm">
            <Heading variant="small" title="Customers on this plan" />
            <ul>
                <li v-for="c in customers" :key="c.id"><Link :href="showAccount(c.id)" class="hover:underline">{{ c.name }}</Link> <span class="text-muted-foreground">{{ c.code }}</span></li>
                <li v-if="customers.length === 0" class="text-muted-foreground">None. Assign this plan on a customer account.</li>
            </ul>
        </section>
    </div>
</template>
