<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import GatewayController from '@/actions/App/Http/Controllers/Admin/GatewayController';
import TrunkController from '@/actions/App/Http/Controllers/Admin/TrunkController';
import ConfirmByName from '@/components/ConfirmByName.vue';
import Heading from '@/components/Heading.vue';
import GatewayFields from '@/components/trunks/GatewayFields.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show as showAccount } from '@/routes/accounts';
import { show as showDeck } from '@/routes/rate-decks';
import { show as showPlan } from '@/routes/routing-plans';
import { edit, index, show } from '@/routes/trunks';
import { edit as editGateway } from '@/routes/trunks/gateways';

const props = defineProps<{
    trunk: {
        id: number;
        name: string;
        enabled: boolean;
        max_channels: number | null;
        max_cps: string | null;
        tech_prefix: string;
        strip_digits: number;
        vendor: { id: number; name: string; code: string };
        rate_deck: { id: number; name: string; currency: string } | null;
    };
    gateways: {
        id: number;
        name: string;
        host: string;
        port: number;
        transport: string;
        username: string | null;
        has_password: boolean;
        register: boolean;
        enabled: boolean;
        priority: number;
        weight: number;
    }[];
    routes: { id: number; prefix: string; priority: number; weight: number; enabled: boolean; plan: { id: number; name: string } }[];
    transports: string[];
    routes_count: number;
}>();

defineOptions({
    layout: (props: { trunk: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Trunks', href: index() },
            { title: props.trunk.name, href: show(props.trunk.id) },
        ],
    }),
});
</script>

<template>
    <Head :title="props.trunk.name" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="ss-stack-2">
                <Heading :title="trunk.name" />
                <p>
                    Carrier <Link :href="showAccount(trunk.vendor.id)" class="fw-medium">{{ trunk.vendor.name }}</Link>
                    · Carrier rates
                    <Link v-if="trunk.rate_deck" :href="showDeck(trunk.rate_deck.id)" class="fw-medium">{{ trunk.rate_deck.name }}</Link>
                    <span v-else class="text-danger">not set</span>
                </p>
                <div class="d-flex flex-wrap gap-1">
                    <Badge v-if="!trunk.enabled" variant="secondary">Disabled</Badge>
                    <Badge variant="outline">{{ trunk.max_channels ?? '∞' }} channels</Badge>
                    <Badge variant="outline">{{ trunk.max_cps ?? '∞' }} CPS</Badge>
                    <Badge v-if="trunk.strip_digits" variant="outline">strip {{ trunk.strip_digits }}</Badge>
                    <Badge v-if="trunk.tech_prefix" variant="outline" class="font-monospace">+{{ trunk.tech_prefix }}</Badge>
                </div>
            </div>
            <div class="d-flex gap-2">
                <Button variant="outline" as-child><Link :href="edit(trunk.id)"><Pencil class="icon" /> Edit</Link></Button>
                <ConfirmByName
                    :action="TrunkController.destroy.form(trunk.id)"
                    :name="trunk.name"
                    :title="`Delete trunk ${trunk.name}?`"
                    description="The trunk and its gateways are deleted and calls stop going to it straight away. Past call records are kept. This cannot be undone."
                    label="Delete trunk"
                >
                    <div v-if="routes_count" class="alert alert-warning">
                        It is in {{ routes_count }} LCR group route(s); they are removed with it. Calls on those prefixes use the group's other carriers.
                    </div>
                </ConfirmByName>
            </div>
        </div>

        <section class="ss-stack-3">
            <Heading variant="small" title="Gateways" description="SIP endpoints for this trunk. Calls try lower priority numbers first; equal priorities share traffic by weight." />

            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Endpoint</th>
                            <th>Auth</th>
                            <th class="text-end">Priority / weight</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in gateways" :key="g.id" :class="{ 'opacity-50': !g.enabled }">
                            <td class="font-monospace">{{ g.name }}</td>
                            <td class="font-monospace">{{ g.transport }}:{{ g.host }}:{{ g.port }}</td>
                            <td>
                                <span v-if="g.username">{{ g.username }}<span v-if="g.register"> (registers)</span></span>
                                <span v-else class="text-secondary">IP</span>
                            </td>
                            <td class="text-end tabular-nums">{{ g.priority }} / {{ g.weight }}</td>
                            <td class="text-end text-nowrap">
                                <Button size="sm" variant="ghost" as-child><Link :href="editGateway([trunk.id, g.id])" :aria-label="`Edit ${g.name}`"><Pencil class="icon" /></Link></Button>
                                <Form v-bind="GatewayController.destroy.form([trunk.id, g.id])" class="d-inline" v-slot="{ processing }">
                                    <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${g.name}`"><Trash2 class="icon" /></Button>
                                </Form>
                            </td>
                        </tr>
                        <tr v-if="gateways.length === 0">
                            <td colspan="5" class="text-center text-secondary">No gateways yet. Calls can't use this trunk until one is added.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <details class="card card-body" :open="gateways.length === 0">
                <summary class="fw-medium">Add gateway</summary>
                <Form v-bind="GatewayController.store.form(trunk.id)" reset-on-success class="mt-3 ss-stack-3" v-slot="{ errors, processing }">
                    <GatewayFields :transports="transports" :errors="errors" />
                    <Button :disabled="processing">Add gateway</Button>
                </Form>
            </details>
        </section>

        <section class="ss-stack-3">
            <Heading variant="small" title="Used in LCR groups" />
            <ul class="list-group">
                <li v-for="r in routes" :key="r.id" class="list-group-item d-flex justify-content-between px-2 py-2" :class="{ 'opacity-50': !r.enabled }">
                    <Link :href="showPlan(r.plan.id)">{{ r.plan.name }}</Link>
                    <span class="font-monospace text-secondary">{{ r.prefix || '(all destinations)' }}</span>
                </li>
                <li v-if="routes.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">Not in any LCR group yet.</li>
            </ul>
        </section>
    </div>
</template>
