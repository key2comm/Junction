<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import type { AccountFormOptions } from '@/components/accounts/AccountForm.vue';
import AccountForm from '@/components/accounts/AccountForm.vue';
import Heading from '@/components/Heading.vue';
import { create, index } from '@/routes/accounts';

defineProps<{ options: AccountFormOptions }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Accounts', href: index() },
            { title: 'New account', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New account" />

    <div class="ss-maxw-3xl ss-stack-4">
        <Heading title="New account" description="A customer, a carrier, or both" />
        <AccountForm
            :action="AccountController.store.form()"
            :options="options"
            submit-label="Create account"
            :cancel-href="index().url"
        />
    </div>
</template>
