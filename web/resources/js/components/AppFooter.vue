<script setup lang="ts">
/**
 * "© <this year> <company>" from Settings → Branding, and for admins the
 * Junction release (web/VERSION). Nothing when neither applies.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const company = computed(() => (page.props.branding as { company?: string | null } | undefined)?.company ?? null);
const version = computed(() => (page.props.version as string | null | undefined) ?? null);
const year = new Date().getFullYear();
// "0.1.0-beta" → "v0.1.0 beta"
const release = computed(() => (version.value ? `v${version.value.replace(/-(\w+)$/, ' $1')}` : null));
</script>

<template>
    <footer v-if="company || release" class="footer footer-transparent d-print-none">
        <div class="container-xl text-center text-secondary small">
            <template v-if="company">© {{ year }} {{ company }}</template>
            <template v-if="company && release"> · </template>
            <template v-if="release">Junction {{ release }}</template>
        </div>
    </footer>
</template>
