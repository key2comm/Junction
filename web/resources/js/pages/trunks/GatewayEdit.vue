<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import GatewayController from '@/actions/App/Http/Controllers/Admin/GatewayController';
import Heading from '@/components/Heading.vue';
import type { GatewayValues } from '@/components/trunks/GatewayFields.vue';
import GatewayFields from '@/components/trunks/GatewayFields.vue';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/trunks';

const props = defineProps<{
    trunk: { id: number; name: string };
    gateway: GatewayValues & { id: number };
    transports: string[];
}>();

defineOptions({
    layout: (props: { trunk: { id: number; name: string }; gateway: { name: string } }) => ({
        breadcrumbs: [
            { title: 'Trunks', href: index() },
            { title: props.trunk.name, href: show(props.trunk.id) },
            { title: props.gateway.name, href: show(props.trunk.id) },
        ],
    }),
});
</script>

<template>
    <Head :title="`Edit ${props.gateway.name}`" />

    <div class="max-w-3xl space-y-6 p-4">
        <Heading :title="`Edit gateway ${gateway.name}`" />
        <Form v-bind="GatewayController.update.form([trunk.id, gateway.id])" class="space-y-6" v-slot="{ errors, processing }">
            <GatewayFields :gateway="gateway" :transports="transports" :errors="errors" />
            <label v-if="gateway.has_password" class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="clear_password" value="1" class="size-4" /> Remove the saved password
            </label>
            <div class="flex gap-3">
                <Button :disabled="processing">Save changes</Button>
                <Button variant="ghost" as-child><Link :href="show(trunk.id)">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
