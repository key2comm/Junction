<script setup lang="ts">
defineProps<{ message?: string }>();
</script>

<template>
    <div v-if="message" class="invalid-feedback d-block">{{ message }}</div>
</template>
