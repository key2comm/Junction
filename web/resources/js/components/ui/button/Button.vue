<script setup lang="ts">
import type { PrimitiveProps } from 'reka-ui';
import { Primitive } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import type { ButtonSize, ButtonVariant } from '.';
import { buttonClass } from '.';

interface Props extends PrimitiveProps {
    variant?: ButtonVariant;
    size?: ButtonSize;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), { as: 'button', variant: 'default', size: 'default' });
</script>

<template>
    <Primitive :as="as" :as-child="asChild" :class="cn(buttonClass(variant, size), props.class)">
        <slot />
    </Primitive>
</template>
