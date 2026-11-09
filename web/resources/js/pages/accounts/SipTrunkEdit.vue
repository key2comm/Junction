<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import GatewayController from '@/actions/App/Http/Controllers/Admin/GatewayController';
import type { SipTrunkValues } from '@/components/accounts/SipTrunkFields.vue';
import SipTrunkFields from '@/components/accounts/SipTrunkFields.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/accounts';
import { index as carriers } from '@/routes/carriers';

const props = defineProps<{
    account: { id: number; name: string; code: string };
    gateway: SipTrunkValues & { id: number };
    transports: string[];
}>();

defineOptions({
    layout: (props: { account: { id: number; code: string }; gateway: { label: string } }) => ({
        breadcrumbs: [
            { title: 'Carriers', href: carriers() },
            { title: props.account.code, href: show(props.account.id) },
            { title: props.gateway.label, href: show(props.account.id) },
        ],
    }),
});
</script>

<template>
    <Head :title="`Edit ${props.gateway.label}`" />

    <div class="ss-maxw-3xl ss-stack-4">
        <Heading :title="`Edit SIP trunk ${gateway.label}`" :description="account.name" />
        <Form v-bind="GatewayController.update.form([account.id, gateway.id])" class="ss-stack-4" v-slot="{ errors, processing }">
            <SipTrunkFields :gateway="gateway" :transports="transports" :errors="errors" />
            <label v-if="gateway.has_password" class="d-flex align-items-center gap-2">
                <input type="checkbox" name="clear_password" value="1" class="form-check-input m-0" /> Remove the saved auth password
            </label>
            <div class="d-flex gap-2">
                <Button :disabled="processing">Save changes</Button>
                <Button variant="ghost" as-child><Link :href="show(account.id)">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
