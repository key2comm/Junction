<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}>();
</script>

<template>
    <div v-if="total > 0" class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <p class="m-0 text-secondary">{{ from }}–{{ to }} of {{ total }}</p>
        <ul v-if="links.length > 3" class="pagination m-0" aria-label="Pagination">
            <li v-for="(link, i) in links" :key="i" class="page-item" :class="{ active: link.active, disabled: !link.url }">
                <Link v-if="link.url" :href="link.url" preserve-scroll class="page-link"><span v-html="link.label" /></Link>
                <span v-else class="page-link" v-html="link.label" />
            </li>
        </ul>
    </div>
</template>
