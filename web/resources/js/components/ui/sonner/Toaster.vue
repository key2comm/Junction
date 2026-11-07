<script lang="ts" setup>
import { Toaster as Sonner } from 'vue-sonner';
import type { ToasterProps } from 'vue-sonner';

const props = defineProps<ToasterProps>();
</script>

<template>
    <Sonner v-bind="props" rich-colors close-button position="top-right" :theme="undefined" />
</template>
