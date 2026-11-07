<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from 'reka-ui';
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, injectDialogRootContext, useForwardPropsEmits } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<DialogContentProps & { class?: HTMLAttributes['class']; showCloseButton?: boolean; size?: 'sm' | 'md' | 'lg' }>(),
    { showCloseButton: true, size: 'md' },
);
const emits = defineEmits<DialogContentEmits>();
const delegated = computed(() => {
    const { class: _c, showCloseButton: _s, size: _z, ...rest } = props; // eslint-disable-line @typescript-eslint/no-unused-vars

    return rest;
});
const forwarded = useForwardPropsEmits(delegated, emits);
// The full-screen .modal wrapper must only exist while the dialog is open,
// or it sits invisibly over the page and swallows every click.
const root = injectDialogRootContext();
</script>

<template>
    <DialogPortal>
        <DialogOverlay class="modal-backdrop fade show" />
        <div v-if="root.open.value" class="modal d-block" tabindex="-1">
            <div :class="['modal-dialog modal-dialog-centered', { 'modal-sm': size === 'sm', 'modal-lg': size === 'lg' }]">
                <DialogContent v-bind="{ ...$attrs, ...forwarded }" :class="cn('modal-content', props.class)">
                    <DialogClose v-if="showCloseButton" class="btn-close" aria-label="Close" style="position: absolute; top: 1rem; right: 1rem; z-index: 1" />
                    <div class="modal-body">
                        <slot />
                    </div>
                </DialogContent>
            </div>
        </div>
    </DialogPortal>
</template>
