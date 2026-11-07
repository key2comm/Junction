<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <div v-if="canManageTwoFactor">
        <Heading variant="small" title="Two-factor authentication" description="A code from an authenticator app at every sign-in" />

        <div v-if="!twoFactorEnabled">
            <p class="text-secondary">
                When two-factor authentication is on, you're asked for a code during sign-in. The code comes from a TOTP app on your phone.
            </p>
            <Button v-if="hasSetupData" @click="showSetupModal = true"><ShieldCheck class="icon" /> Continue setup</Button>
            <Form v-else v-bind="enable.form()" @success="showSetupModal = true" #default="{ processing }">
                <Button type="submit" :disabled="processing">Enable 2FA</Button>
            </Form>
        </div>

        <div v-else>
            <p class="text-secondary">You're asked for a code from your authenticator app at sign-in.</p>
            <Form v-bind="disable.form()" #default="{ processing }" class="mb-3">
                <Button variant="destructive" type="submit" :disabled="processing">Disable 2FA</Button>
            </Form>
            <TwoFactorRecoveryCodes />
        </div>

        <TwoFactorSetupModal v-model:isOpen="showSetupModal" :requiresConfirmation="requiresConfirmation" :twoFactorEnabled="twoFactorEnabled" />
    </div>
</template>
