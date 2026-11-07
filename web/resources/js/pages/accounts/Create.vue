<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import type { AccountFormOptions } from '@/components/accounts/AccountForm.vue';
import AccountForm from '@/components/accounts/AccountForm.vue';
import Heading from '@/components/Heading.vue';
import { create, index } from '@/routes/accounts';
import { create as createCarrier, index as carriers } from '@/routes/carriers';

const props = defineProps<{ options: AccountFormOptions; role: 'customer' | 'carrier' }>();

defineOptions({
    layout: (props: { role: 'customer' | 'carrier' }) => ({
        breadcrumbs:
            props.role === 'carrier'
                ? [
                      { title: 'Carriers', href: carriers() },
                      { title: 'New carrier', href: createCarrier() },
                  ]
                : [
                      { title: 'Accounts', href: index() },
                      { title: 'New customer', href: create() },
                  ],
    }),
});

const title = props.role === 'carrier' ? 'New carrier' : 'New customer';
</script>

<template>
    <Head :title="title" />

    <div class="ss-maxw-3xl ss-stack-4">
        <Heading :title="title" :description="role === 'carrier' ? 'A carrier we send traffic to. Add its trunks after saving.' : 'A customer that sends us traffic'" />
        <AccountForm
            :action="AccountController.store.form()"
            :options="options"
            :role="role"
            :submit-label="role === 'carrier' ? 'Create carrier' : 'Create customer'"
            :cancel-href="role === 'carrier' ? carriers().url : index().url"
        />
    </div>
</template>
