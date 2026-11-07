<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <span role="status" aria-label="Loading" :class="cn('spinner-border spinner-border-sm', props.class)"></span>
</template>
