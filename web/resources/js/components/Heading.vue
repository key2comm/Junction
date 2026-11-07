<script setup lang="ts">
/** Page title (default) or a section title inside a page (small). */
withDefaults(defineProps<{ title: string; description?: string; variant?: 'default' | 'small' }>(), { variant: 'default' });
</script>

<template>
    <div v-if="variant === 'small'" class="mb-2">
        <h3 class="card-title mb-1">{{ title }}</h3>
        <div v-if="description" class="text-secondary small">{{ description }}</div>
    </div>
    <div v-else class="mb-3">
        <h2 class="page-title">{{ title }}</h2>
        <div v-if="description" class="text-secondary mt-1">{{ description }}</div>
    </div>
</template>
