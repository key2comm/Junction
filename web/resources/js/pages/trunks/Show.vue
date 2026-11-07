<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import GatewayController from '@/actions/App/Http/Controllers/Admin/GatewayController';
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

    <div class="space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="trunk.name" />
                <p class="text-sm">
                    Vendor <Link :href="showAccount(trunk.vendor.id)" class="font-medium hover:underline">{{ trunk.vendor.name }}</Link>
                    · Buy rates
                    <Link v-if="trunk.rate_deck" :href="showDeck(trunk.rate_deck.id)" class="font-medium hover:underline">{{ trunk.rate_deck.name }}</Link>
                    <span v-else class="text-destructive">not set</span>
                </p>
                <div class="flex flex-wrap gap-1">
                    <Badge v-if="!trunk.enabled" variant="secondary">Disabled</Badge>
                    <Badge variant="outline">{{ trunk.max_channels ?? '∞' }} channels</Badge>
                    <Badge variant="outline">{{ trunk.max_cps ?? '∞' }} CPS</Badge>
                    <Badge v-if="trunk.strip_digits" variant="outline">strip {{ trunk.strip_digits }}</Badge>
                    <Badge v-if="trunk.tech_prefix" variant="outline" class="font-mono">+{{ trunk.tech_prefix }}</Badge>
                </div>
            </div>
            <Button variant="outline" as-child><Link :href="edit(trunk.id)"><Pencil class="size-4" /> Edit</Link></Button>
        </div>

        <section class="space-y-4">
            <Heading variant="small" title="Gateways" description="SIP endpoints for this trunk. Calls try lower priority numbers first; equal priorities share traffic by weight." />

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">Endpoint</th>
                            <th class="px-3 py-2 font-medium">Auth</th>
                            <th class="px-3 py-2 text-right font-medium">Priority / weight</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in gateways" :key="g.id" class="border-t" :class="{ 'opacity-50': !g.enabled }">
                            <td class="px-3 py-2 font-mono">{{ g.name }}</td>
                            <td class="px-3 py-2 font-mono">{{ g.transport }}:{{ g.host }}:{{ g.port }}</td>
                            <td class="px-3 py-2">
                                <span v-if="g.username">{{ g.username }}<span v-if="g.register"> (registers)</span></span>
                                <span v-else class="text-muted-foreground">IP</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ g.priority }} / {{ g.weight }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <Button size="sm" variant="ghost" as-child><Link :href="editGateway([trunk.id, g.id])" :aria-label="`Edit ${g.name}`"><Pencil class="size-4" /></Link></Button>
                                <Form v-bind="GatewayController.destroy.form([trunk.id, g.id])" class="inline" v-slot="{ processing }">
                                    <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${g.name}`"><Trash2 class="size-4" /></Button>
                                </Form>
                            </td>
                        </tr>
                        <tr v-if="gateways.length === 0">
                            <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">No gateways yet. Calls can't use this trunk until one is added.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <details class="rounded-lg border p-4" :open="gateways.length === 0">
                <summary class="cursor-pointer text-sm font-medium">Add gateway</summary>
                <Form v-bind="GatewayController.store.form(trunk.id)" reset-on-success class="mt-4 space-y-4" v-slot="{ errors, processing }">
                    <GatewayFields :transports="transports" :errors="errors" />
                    <Button :disabled="processing">Add gateway</Button>
                </Form>
            </details>
        </section>

        <section class="space-y-4">
            <Heading variant="small" title="Used in routing plans" />
            <ul class="divide-y rounded-lg border text-sm">
                <li v-for="r in routes" :key="r.id" class="flex justify-between px-3 py-2" :class="{ 'opacity-50': !r.enabled }">
                    <Link :href="showPlan(r.plan.id)" class="hover:underline">{{ r.plan.name }}</Link>
                    <span class="font-mono text-muted-foreground">{{ r.prefix || '(all destinations)' }}</span>
                </li>
                <li v-if="routes.length === 0" class="px-3 py-6 text-center text-muted-foreground">Not in any routing plan yet.</li>
            </ul>
        </section>
    </div>
</template>
