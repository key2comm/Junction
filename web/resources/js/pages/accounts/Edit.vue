<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccountController from '@/actions/App/Http/Controllers/Admin/AccountController';
import type { AccountFormOptions, AccountFormValues } from '@/components/accounts/AccountForm.vue';
import AccountForm from '@/components/accounts/AccountForm.vue';
import Heading from '@/components/Heading.vue';
import { edit, index, show } from '@/routes/accounts';
import { index as carriers } from '@/routes/carriers';

const props = defineProps<{
    options: AccountFormOptions;
    account: AccountFormValues & { id: number };
}>();

defineOptions({
    layout: (props: { account: { id: number; code: string; is_vendor: boolean } }) => ({
        breadcrumbs: [
            props.account.is_vendor ? { title: 'Carriers', href: carriers() } : { title: 'Accounts', href: index() },
            { title: props.account.code, href: show(props.account.id) },
            { title: 'Edit', href: edit(props.account.id) },
        ],
    }),
});
</script>

<template>
    <Head :title="`Edit ${props.account.code}`" />

    <div class="ss-maxw-3xl ss-stack-4">
        <Heading :title="`Edit ${props.account.name}`" />
        <AccountForm
            :action="AccountController.update.form(props.account.id)"
            :options="options"
            :account="props.account"
            :role="props.account.is_vendor && !props.account.is_customer ? 'carrier' : 'customer'"
            submit-label="Save changes"
            :cancel-href="show(props.account.id).url"
        />
    </div>
</template>
