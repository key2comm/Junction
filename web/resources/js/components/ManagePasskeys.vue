<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import type { Passkey } from '@/types/auth';
import Heading from '@/components/Heading.vue';
import PasskeyItem from '@/components/PasskeyItem.vue';
import PasskeyRegister from '@/components/PasskeyRegister.vue';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

withDefaults(defineProps<Props>(), {
    canManagePasskeys: false,
    passkeys: () => [],
});

const handleDelete = (id: number, onError: () => void) => {
    router.delete(destroy.url(id), {
        preserveScroll: true,
        onError,
    });
};

const handleRegisterSuccess = () => {
    router.reload();
};
</script>

<template>
    <div v-if="canManagePasskeys">
        <Heading variant="small" title="Passkeys" description="Sign in without a password" />
        <div class="list-group mb-3">
            <template v-if="passkeys.length">
                <PasskeyItem v-for="passkey in passkeys" :key="passkey.id" :passkey="passkey" @remove="handleDelete" />
            </template>
            <div v-else class="list-group-item text-center py-4">
                <span class="avatar avatar-lg mb-2"><KeyRound class="icon" /></span>
                <div class="fw-medium">No passkeys yet</div>
                <div class="text-secondary">Add a passkey to sign in without a password</div>
            </div>
        </div>
        <PasskeyRegister @success="handleRegisterSuccess" />
    </div>
</template>
