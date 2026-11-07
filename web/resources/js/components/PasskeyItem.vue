<script setup lang="ts">
import { KeyRound, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Passkey } from '@/types/auth';

const props = defineProps<{
    passkey: Passkey;
}>();

const emit = defineEmits<{
    remove: [id: number, onError: () => void];
}>();

const isDeleting = ref(false);

const handleDelete = () => {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
    });
};
</script>

<template>
    <div class="list-group-item d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <span class="avatar"><KeyRound class="icon" /></span>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <strong>{{ passkey.name }}</strong>
                    <span v-if="passkey.authenticator" class="badge bg-secondary-lt text-uppercase">{{ passkey.authenticator }}</span>
                </div>
                <div class="text-secondary small">
                    Added {{ passkey.created_at_diff }}
                    <template v-if="passkey.last_used_at_diff"> · Last used {{ passkey.last_used_at_diff }}</template>
                </div>
            </div>
        </div>

        <Dialog>
            <DialogTrigger as-child>
                <Button variant="ghost" size="sm" class="text-danger" aria-label="Remove"><Trash2 class="icon" /></Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Remove passkey</DialogTitle>
                <DialogDescription>Remove the "{{ passkey.name }}" passkey? You won't be able to sign in with it any more.</DialogDescription>
                <DialogFooter>
                    <DialogClose as-child><Button variant="outline">Cancel</Button></DialogClose>
                    <Button variant="destructive" :disabled="isDeleting" @click="handleDelete">{{ isDeleting ? 'Removing...' : 'Remove passkey' }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
