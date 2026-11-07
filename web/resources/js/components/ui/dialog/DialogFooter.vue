<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <div :class="cn('d-flex flex-wrap justify-content-end gap-2 mt-4', props.class)"><slot /></div>
</template>
