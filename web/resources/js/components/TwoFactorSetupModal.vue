<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, ScanLine } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { InputOTP } from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useAppearance } from '@/composables/useAppearance';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { confirm } from '@/routes/two-factor';
import type { TwoFactorConfigContent } from '@/types';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
};

const { resolvedAppearance } = useAppearance();

const props = defineProps<Props>();
const isOpen = defineModel<boolean>('isOpen');

const { copy, copied } = useClipboard();
const { qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors } =
    useTwoFactorAuth();

const showVerificationStep = ref(false);
const code = ref<string>('');

const pinInputContainerRef = useTemplateRef('pinInputContainerRef');

const modalConfig = computed<TwoFactorConfigContent>(() => {
    if (props.twoFactorEnabled) {
        return {
            title: 'Two-factor authentication enabled',
            description:
                'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
            buttonText: 'Close',
        };
    }

    if (showVerificationStep.value) {
        return {
            title: 'Verify authentication code',
            description: 'Enter the 6-digit code from your authenticator app',
            buttonText: 'Continue',
        };
    }

    return {
        title: 'Enable two-factor authentication',
        description:
            'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
        buttonText: 'Continue',
    };
});

const handleModalNextStep = () => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;

        nextTick(() => {
            pinInputContainerRef.value?.querySelector('input')?.focus();
        });

        return;
    }

    clearSetupData();
    isOpen.value = false;
};

const resetModalState = () => {
    if (props.twoFactorEnabled) {
        clearSetupData();
    }

    showVerificationStep.value = false;
    code.value = '';
};

watch(
    () => isOpen.value,
    async (isOpen) => {
        if (!isOpen) {
            resetModalState();

            return;
        }

        if (!qrCodeSvg.value) {
            await fetchSetupData();
        }
    },
);
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent>
            <DialogHeader class="text-center">
                <span class="avatar avatar-lg mb-3"><ScanLine class="icon" /></span>
                <DialogTitle>{{ modalConfig.title }}</DialogTitle>
                <DialogDescription>{{ modalConfig.description }}</DialogDescription>
            </DialogHeader>

            <template v-if="!showVerificationStep">
                <AlertError v-if="errors?.length" :errors="errors" />
                <template v-else>
                    <div class="d-flex justify-content-center mb-3">
                        <div class="card card-body d-flex align-items-center justify-content-center flex-row" style="width: 16rem; height: 16rem">
                            <Spinner v-if="!qrCodeSvg" />
                            <div
                                v-else
                                v-html="qrCodeSvg"
                                class="w-100 h-100 d-flex align-items-center justify-content-center"
                                :style="{ filter: resolvedAppearance === 'dark' ? 'invert(1) brightness(1.5)' : undefined }"
                            />
                        </div>
                    </div>
                    <Button class="w-100" @click="handleModalNextStep">{{ modalConfig.buttonText }}</Button>
                    <div class="hr-text">or enter the code manually</div>
                    <div v-if="!manualSetupKey" class="text-center"><Spinner /></div>
                    <div v-else class="input-group">
                        <input type="text" readonly :value="manualSetupKey" class="form-control font-monospace" />
                        <button type="button" class="btn btn-outline-secondary" aria-label="Copy" @click="copy(manualSetupKey || '')">
                            <Check v-if="copied" class="icon text-success" /><Copy v-else class="icon" />
                        </button>
                    </div>
                </template>
            </template>

            <Form
                v-else
                v-bind="confirm.form()"
                error-bag="confirmTwoFactorAuthentication"
                reset-on-error
                @finish="code = ''"
                @success="isOpen = false"
                v-slot="{ errors, processing }"
            >
                <input type="hidden" name="code" :value="code" />
                <div ref="pinInputContainerRef" class="py-2 text-center">
                    <InputOTP id="otp" v-model="code" :maxlength="6" :disabled="processing" autofocus />
                    <InputError :message="errors?.code" />
                </div>
                <div class="d-flex gap-2 mt-3">
                    <Button type="button" variant="outline" class="flex-fill" @click="showVerificationStep = false" :disabled="processing">Back</Button>
                    <Button type="submit" class="flex-fill" :disabled="processing || code.length < 6">Confirm</Button>
                </div>
            </Form>
        </DialogContent>
    </Dialog>
</template>
