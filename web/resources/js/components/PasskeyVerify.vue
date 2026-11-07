<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { KeyRound } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

const props = defineProps<Props>();

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    ...(props.routes
        ? {
              routes: {
                  options: props.routes.options.url,
                  submit: props.routes.submit.url,
              },
          }
        : {}),
    onSuccess: (response) => {
        router.visit(response.redirect ?? '/dashboard');
    },
});
</script>

<template>
    <div v-if="isSupported">
        <Button type="button" variant="outline" class="w-100" @click="verify" :disabled="isLoading">
            <Spinner v-if="isLoading" class="me-2" />
            <KeyRound v-else class="icon" />
            {{ isLoading ? (props.loadingLabel ?? 'Authenticating...') : (props.label ?? 'Sign in with a passkey') }}
        </Button>
        <div v-if="error" class="text-center"><InputError :message="error" /></div>
        <div class="hr-text">{{ props.separator ?? 'Or continue with email' }}</div>
    </div>
</template>
