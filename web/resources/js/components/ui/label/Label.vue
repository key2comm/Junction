<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class']; for?: string }>();
</script>

<template>
    <label :for="props.for" :class="cn('form-label', props.class)"><slot /></label>
</template>
