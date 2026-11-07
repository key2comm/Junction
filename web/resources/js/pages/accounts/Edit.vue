<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import type { AccountFormOptions, AccountFormValues } from '@/components/accounts/AccountForm.vue';
import AccountForm from '@/components/accounts/AccountForm.vue';
import Heading from '@/components/Heading.vue';
import { edit, index, show } from '@/routes/accounts';

const props = defineProps<{
    options: AccountFormOptions;
    account: AccountFormValues & { id: number };
}>();

defineOptions({
    layout: (props: { account: { id: number; code: string } }) => ({
        breadcrumbs: [
            { title: 'Accounts', href: index() },
            { title: props.account.code, href: show(props.account.id) },
            { title: 'Edit', href: edit(props.account.id) },
        ],
    }),
});
</script>

<template>
    <Head :title="`Edit ${props.account.code}`" />

    <div class="max-w-3xl space-y-6 p-4">
        <Heading :title="`Edit ${props.account.name}`" />
        <AccountForm
            :action="AccountController.update.form(props.account.id)"
            :options="options"
            :account="props.account"
            submit-label="Save changes"
            :cancel-href="show(props.account.id).url"
        />
    </div>
</template>
