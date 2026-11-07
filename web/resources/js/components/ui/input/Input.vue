<script setup lang="ts">
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    defaultValue?: string | number;
    modelValue?: string | number;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{ (e: 'update:modelValue', payload: string | number): void }>();

const modelValue = useVModel(props, 'modelValue', emits, { passive: true, defaultValue: props.defaultValue });
</script>

<template>
    <input v-model="modelValue" :class="cn('form-control', props.class)" />
</template>
