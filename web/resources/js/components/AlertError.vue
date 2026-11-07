<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const props = withDefaults(defineProps<{ errors: string[]; title?: string }>(), { title: 'Something went wrong.' });
const uniqueErrors = computed(() => Array.from(new Set(props.errors)));
</script>

<template>
    <Alert variant="destructive">
        <AlertCircle class="icon alert-icon" />
        <div>
            <AlertTitle>{{ title }}</AlertTitle>
            <AlertDescription>
                <ul class="mb-0 ps-3">
                    <li v-for="(error, index) in uniqueErrors" :key="index">{{ error }}</li>
                </ul>
            </AlertDescription>
        </div>
    </Alert>
</template>
