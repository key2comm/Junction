<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from '@lucide/vue';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <Card>
        <CardHeader>
            <div>
                <CardTitle><LockKeyhole class="icon me-1" /> 2FA recovery codes</CardTitle>
                <CardDescription>Recovery codes get you back in if you lose your 2FA device. Keep them in a password manager.</CardDescription>
            </div>
        </CardHeader>
        <CardContent>
            <div class="d-flex flex-wrap gap-2 justify-content-between">
                <Button @click="toggleRecoveryCodesVisibility">
                    <component :is="isRecoveryCodesVisible ? EyeOff : Eye" class="icon" />
                    {{ isRecoveryCodesVisible ? 'Hide' : 'View' }} recovery codes
                </Button>
                <Form
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    v-bind="regenerateRecoveryCodes.form()"
                    method="post"
                    :options="{ preserveScroll: true }"
                    @success="fetchRecoveryCodes"
                    #default="{ processing }"
                >
                    <Button variant="outline" type="submit" :disabled="processing"><RefreshCw class="icon" /> Regenerate codes</Button>
                </Form>
            </div>
            <div v-if="isRecoveryCodesVisible" class="mt-3">
                <AlertError v-if="errors?.length" :errors="errors" />
                <template v-else>
                    <div ref="recoveryCodeSectionRef" class="bg-body-tertiary rounded p-3 font-monospace">
                        <div v-if="!recoveryCodesList.length" class="placeholder-glow">
                            <span v-for="n in 8" :key="n" class="placeholder col-6 d-block mb-1"></span>
                        </div>
                        <div v-for="(code, index) in recoveryCodesList" v-else :key="index">{{ code }}</div>
                    </div>
                    <p class="form-hint mt-2">Each code works once and is then removed. Need more? Use <strong>Regenerate codes</strong>.</p>
                </template>
            </div>
        </CardContent>
    </Card>
</template>
