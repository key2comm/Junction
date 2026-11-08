<script setup lang="ts">
/**
 * A destructive action confirmed by typing the item's name: a button that
 * opens a dialog; the form posts `confirm` (the typed name) to `action`.
 * Warnings go in the default slot.
 */
import { Form } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    action: { action: string; method: 'get' | 'post' | 'put' | 'patch' | 'delete' };
    name: string;
    title: string;
    description: string;
    label: string;
    disabled?: boolean;
}>();

const open = ref(false);
const typed = ref('');
watch(open, (o) => {
    if (!o) typed.value = '';
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline" class="text-danger" :disabled="disabled"><Trash2 class="icon" /> {{ label }}</Button>
        </DialogTrigger>
        <DialogContent>
            <Form v-bind="props.action" :options="{ preserveScroll: true }" @success="open = false" v-slot="{ errors, processing }">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>
                <slot />
                <div class="ss-grid gap-1">
                    <Label for="confirm_name">Type <strong class="user-select-all">{{ name }}</strong> to confirm</Label>
                    <Input id="confirm_name" v-model="typed" name="confirm" autocomplete="off" />
                    <InputError :message="errors.confirm" />
                </div>
                <DialogFooter>
                    <DialogClose as-child><Button variant="outline">Cancel</Button></DialogClose>
                    <Button type="submit" variant="destructive" :disabled="processing || typed.trim() !== name.trim()">{{ label }}</Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
