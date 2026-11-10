<script setup lang="ts">
/**
 * The logo top left and on the sign-in page. Settings → Branding: the
 * company's logo replaces it; otherwise its icon (if any) sits beside the name.
 */
import { usePage } from '@inertiajs/vue3';
import { Waypoints } from '@lucide/vue';
import { computed } from 'vue';

withDefaults(defineProps<{ showName?: boolean }>(), { showName: true });
const page = usePage();
const name = computed(() => page.props.name as string);
const branding = computed(() => (page.props.branding ?? {}) as { logo?: string | null; icon?: string | null; company?: string | null });
</script>

<template>
    <img v-if="branding.logo" :src="branding.logo" :alt="branding.company ?? name" class="ss-brand-logo" />
    <span v-else class="d-inline-flex align-items-center gap-2">
        <img v-if="branding.icon" :src="branding.icon" alt="" class="avatar avatar-sm bg-transparent" />
        <span v-else class="avatar avatar-sm bg-primary text-white"><Waypoints class="icon" /></span>
        <span v-if="showName" class="fw-bold">{{ name }}</span>
    </span>
</template>
