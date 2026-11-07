<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Waypoints } from '@lucide/vue';

withDefaults(defineProps<{ showName?: boolean }>(), { showName: true });
const name = usePage().props.name as string;
</script>

<template>
    <span class="d-inline-flex align-items-center gap-2">
        <span class="avatar avatar-sm bg-primary text-white"><Waypoints class="icon" /></span>
        <span v-if="showName" class="fw-bold">{{ name }}</span>
    </span>
</template>
