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

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Vendor trunks" description="Where traffic goes: each trunk is a vendor product with a buy rate deck and one or more gateways" />
            <Button as-child><Link :href="create()"><Plus class="size-4" /> New trunk</Link></Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Vendor</th>
                        <th class="px-4 py-2 font-medium">Trunk</th>
                        <th class="px-4 py-2 font-medium">Buy rates</th>
                        <th class="px-4 py-2 text-right font-medium">Gateways</th>
                        <th class="px-4 py-2 text-right font-medium">Routes</th>
                        <th class="px-4 py-2 font-medium">Limits</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in trunks" :key="t.id" class="border-t hover:bg-muted/30" :class="{ 'opacity-60': !t.enabled }">
                        <td class="px-4 py-2"><Link :href="showAccount(t.vendor.id)" class="hover:underline">{{ t.vendor.name }}</Link></td>
                        <td class="px-4 py-2">
                            <Link :href="show(t.id)" class="font-medium hover:underline">{{ t.name }}</Link>
                            <Badge v-if="!t.enabled" variant="secondary" class="ml-2">Disabled</Badge>
                        </td>
                        <td class="px-4 py-2">
                            <Link v-if="t.rate_deck" :href="showDeck(t.rate_deck.id)" class="hover:underline">{{ t.rate_deck.name }}</Link>
                            <span v-else class="text-destructive">None — can't be used for LCR</span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums" :class="{ 'text-destructive': t.gateways_count === 0 }">{{ t.gateways_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ t.routes_count }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ t.max_channels ?? '∞' }} ch, {{ t.max_cps ?? '∞' }} CPS</td>
                    </tr>
                    <tr v-if="trunks.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">No trunks yet. Create a vendor account first, then add its trunks.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
