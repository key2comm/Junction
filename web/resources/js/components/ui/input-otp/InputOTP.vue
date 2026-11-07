<script setup lang="ts">
/**
 * One-time code input (vue-input-otp), drawn as Tabler form-controls.
 * Usage: <InputOTP v-model="code" :maxlength="6" />
 */
import { OTPInput } from 'vue-input-otp';
import type { SlotProps } from 'vue-input-otp';

defineProps<{ maxlength: number; disabled?: boolean; id?: string; autofocus?: boolean }>();
const code = defineModel<string>({ default: '' });
const emit = defineEmits<{ complete: [value: string] }>();
</script>

<template>
    <OTPInput :id="id" v-slot="{ slots }" v-model="code" :maxlength="maxlength" :disabled="disabled" :autofocus="autofocus" container-class="d-flex gap-2 justify-content-center" @complete="emit('complete', $event)">
        <div
            v-for="(slot, i) in slots as SlotProps[]"
            :key="i"
            class="form-control form-control-lg text-center font-monospace d-flex align-items-center justify-content-center p-0"
            :class="{ 'border-primary': slot.isActive }"
            style="width: 2.75rem; height: 3rem"
        >
            {{ slot.char ?? '' }}<span v-if="slot.hasFakeCaret" class="text-primary">|</span>
        </div>
    </OTPInput>
</template>
