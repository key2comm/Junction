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
    plan: {
        id: number;
        name: string;
        mode: string;
        max_attempts: number;
        description: string | null;
        quality_routing: boolean;
        quality_min_asr: string;
        quality_max_pdd_ms: number;
        quality_min_attempts: number;
    };
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

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="ss-stack-2">
                <Heading :title="plan.name" :description="plan.description ?? undefined" />
                <div class="d-flex gap-1">
                    <Badge variant="outline">{{ modeLabel[plan.mode] ?? plan.mode }}</Badge>
                    <Badge variant="outline">up to {{ plan.max_attempts }} attempts</Badge>
                    <Badge v-if="plan.quality_routing" variant="secondary" :title="`After ${plan.quality_min_attempts} attempts in the last hour`">
                        quality routing: demote below {{ Number(plan.quality_min_asr) }}% ASR or above {{ plan.quality_max_pdd_ms / 1000 }}s PDD
                    </Badge>
                </div>
            </div>
            <Button variant="outline" as-child><Link :href="edit(plan.id)"><Pencil class="icon" /> Edit</Link></Button>
        </div>

        <section class="ss-stack-3">
            <Heading
                variant="small"
                title="Routes"
                description="For each call, the longest matching prefix wins, and its trunks are tried in the plan's order. A blank prefix matches every destination."
            />

            <Form
                v-bind="RoutingPlanController.storeRoute.form(plan.id)"
                :options="{ preserveScroll: true }"
                class="ss-grid gap-2 rounded border p-3 ss-gtc-sm-1fr-2fr-auto-auto-auto align-items-sm-start"
                v-slot="{ errors, processing }"
            >
                <div class="ss-grid gap-1">
                    <Input name="prefix" placeholder="Prefix (blank = all)" class="font-monospace" aria-label="Prefix" />
                    <InputError :message="errors.prefix" />
                </div>
                <div class="ss-grid gap-1">
                    <select name="trunk_id" :class="selectClass" required aria-label="Trunk">
                        <option value="" disabled selected>Choose a trunk</option>
                        <option v-for="t in trunks" :key="t.id" :value="t.id">{{ t.label }}</option>
                    </select>
                    <InputError :message="errors.trunk_id" />
                </div>
                <div class="ss-grid gap-1">
                    <Input name="priority" type="number" min="1" max="100" default-value="1" class="ss-w-24" aria-label="Priority" title="Priority (lower first)" />
                    <InputError :message="errors.priority" />
                </div>
                <div class="ss-grid gap-1">
                    <Input name="weight" type="number" min="1" max="1000" default-value="100" class="ss-w-24" aria-label="Weight" title="Weight (load balance share)" />
                    <InputError :message="errors.weight" />
                </div>
                <Button :disabled="processing || trunks.length === 0">Add route</Button>
            </Form>

            <Input v-model="q" placeholder="Filter by prefix" class="ss-maxw-xs font-monospace" @input="search" />

            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Prefix</th>
                            <th>Trunk</th>
                            <th class="text-end">Priority</th>
                            <th class="text-end">Weight</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in routes.data" :key="r.id" :class="{ 'opacity-50': !r.enabled || !r.trunk.enabled }">
                            <td class="font-monospace">{{ r.prefix || '(all)' }}</td>
                            <td>
                                <Link :href="showTrunk(r.trunk.id)">{{ r.trunk.vendor }} / {{ r.trunk.name }}</Link>
                                <Badge v-if="!r.trunk.enabled" variant="secondary" class="ms-2">trunk disabled</Badge>
                            </td>
                            <td class="text-end tabular-nums">{{ r.priority }}</td>
                            <td class="text-end tabular-nums">{{ r.weight }}</td>
                            <td class="text-end text-nowrap">
                                <Button size="sm" variant="ghost" @click="toggle(r)">{{ r.enabled ? 'Disable' : 'Enable' }}</Button>
                                <Form v-bind="RoutingPlanController.destroyRoute.form([plan.id, r.id])" :options="{ preserveScroll: true }" class="d-inline" v-slot="{ processing }">
                                    <Button size="sm" variant="ghost" :disabled="processing" aria-label="Remove route"><Trash2 class="icon" /></Button>
                                </Form>
                            </td>
                        </tr>
                        <tr v-if="routes.data.length === 0">
                            <td colspan="5" class="text-center text-secondary">
                                {{ trunks.length === 0 ? 'Create a vendor trunk first, then route prefixes to it.' : 'No routes yet.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="routes.links" :from="routes.from" :to="routes.to" :total="routes.total" />
        </section>

        <section class="ss-stack-2">
            <Heading variant="small" title="Customers on this plan" />
            <ul>
                <li v-for="c in customers" :key="c.id"><Link :href="showAccount(c.id)">{{ c.name }}</Link> <span class="text-secondary">{{ c.code }}</span></li>
                <li v-if="customers.length === 0" class="text-secondary">None. Assign this plan on a customer account.</li>
            </ul>
        </section>
    </div>
</template>
