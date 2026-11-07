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
    <div v-if="total > 0" class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <p class="text-muted-foreground">{{ from }}–{{ to }} of {{ total }}</p>
        <nav v-if="links.length > 3" class="flex flex-wrap gap-1" aria-label="Pagination">
            <template v-for="(link, i) in links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1"
                    :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    ><span v-html="link.label"
                /></Link>
                <span v-else class="rounded-md px-3 py-1 text-muted-foreground" v-html="link.label" />
            </template>
        </nav>
    </div>
</template>
