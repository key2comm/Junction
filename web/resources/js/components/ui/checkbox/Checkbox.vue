<script setup lang="ts">
/** A Tabler checkbox: a native input, so it posts with <Form> like any field. */
defineProps<{ id?: string; name?: string; value?: string; checked?: boolean; tabindex?: number | string }>();
</script>

<template>
    <input :id="id" type="checkbox" class="form-check-input" :name="name" :value="value ?? '1'" :checked="checked" :tabindex="tabindex" />
</template>
