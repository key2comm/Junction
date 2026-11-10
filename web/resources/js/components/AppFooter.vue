<script setup lang="ts">
/** "© <this year> <company>" from Settings → Branding (nothing when no company name is set). */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const company = computed(() => (page.props.branding as { company?: string | null } | undefined)?.company ?? null);
const year = new Date().getFullYear();
</script>

<template>
    <footer v-if="company" class="footer footer-transparent d-print-none">
        <div class="container-xl text-center text-secondary small">© {{ year }} {{ company }}</div>
    </footer>
</template>
