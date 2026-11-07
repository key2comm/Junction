<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{ class?: HTMLAttributes['class']; autofocus?: boolean }>();

const showPassword = ref(false);
const inputRef = useTemplateRef<HTMLInputElement>('inputRef');

defineExpose({ $el: inputRef, focus: () => inputRef.value?.focus() });
</script>

<template>
    <div class="input-group input-group-flat">
        <input v-focus="props.autofocus" ref="inputRef" :type="showPassword ? 'text' : 'password'" :class="cn('form-control', props.class)" v-bind="$attrs" />
        <span class="input-group-text">
            <button type="button" class="link-secondary btn btn-link p-0 border-0 lh-1" :aria-label="showPassword ? 'Hide password' : 'Show password'" :tabindex="-1" @click="showPassword = !showPassword">
                <EyeOff v-if="showPassword" class="icon" /><Eye v-else class="icon" />
            </button>
        </span>
    </div>
</template>
