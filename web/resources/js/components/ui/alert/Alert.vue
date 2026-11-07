<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{ variant?: 'default' | 'destructive' | 'success' | 'warning'; class?: HTMLAttributes['class'] }>(), {
    variant: 'default',
});
const variants = { default: 'alert-info', destructive: 'alert-danger', success: 'alert-success', warning: 'alert-warning' };
</script>

<template>
    <div role="alert" :class="cn('alert', variants[props.variant], props.class)">
        <div class="d-flex gap-2"><slot /></div>
    </div>
</template>
