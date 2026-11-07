<script setup lang="ts">
/** Sign-in pages: Tabler's centred card. */
import AppLogo from '@/components/AppLogo.vue';
import { Toaster } from '@/components/ui/sonner';

const { title = '', description = '' } = defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <AppLogo class="fs-2" />
            </div>
            <div class="card card-md">
                <div class="card-body">
                    <h2 class="h2 text-center mb-2">{{ title }}</h2>
                    <p v-if="description" class="text-secondary text-center mb-4">{{ description }}</p>
                    <slot />
                </div>
            </div>
        </div>
        <Toaster />
    </div>
</template>
