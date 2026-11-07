<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

export type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline' | 'success' | 'warning';

const props = withDefaults(defineProps<{ variant?: BadgeVariant; class?: HTMLAttributes['class'] }>(), { variant: 'default' });

const variants: Record<BadgeVariant, string> = {
    default: 'bg-primary-lt',
    secondary: 'bg-secondary-lt',
    destructive: 'bg-danger-lt',
    outline: 'badge-outline text-secondary',
    success: 'bg-success-lt',
    warning: 'bg-warning-lt',
};
</script>

<template>
    <span :class="cn('badge', variants[props.variant], props.class)"><slot /></span>
</template>
