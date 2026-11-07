<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

defineProps<{ href: LinkComponentBaseProps['href']; tabindex?: number; method?: Method; as?: string }>();
</script>

<template>
    <Link :href="href" :tabindex="tabindex" :method="method" :as="as" :class="{ 'btn btn-link p-0 align-baseline': as === 'button' }"><slot /></Link>
</template>
