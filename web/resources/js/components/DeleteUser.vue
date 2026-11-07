<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div>
        <Heading variant="small" title="Delete account" description="Delete your login and everything attached to it" />
        <div class="alert alert-danger">
            <div>
                <h4 class="alert-heading">Warning</h4>
                <div class="alert-description mb-3">This cannot be undone.</div>
                <Dialog>
                    <DialogTrigger as-child>
                        <Button variant="destructive" data-test="delete-user-button">Delete account</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="ProfileController.destroy.form()"
                            reset-on-success
                            @error="() => passwordInput?.focus()"
                            :options="{ preserveScroll: true }"
                            v-slot="{ errors, processing, reset, clearErrors }"
                        >
                            <DialogHeader>
                                <DialogTitle>Are you sure you want to delete your account?</DialogTitle>
                                <DialogDescription>
                                    Once your account is deleted, all of its resources and data are permanently deleted. Enter your password to confirm.
                                </DialogDescription>
                            </DialogHeader>

                            <Label for="password" class="visually-hidden">Password</Label>
                            <PasswordInput id="password" name="password" ref="passwordInput" placeholder="Password" />
                            <InputError :message="errors.password" />

                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline" @click="() => { clearErrors(); reset(); }">Cancel</Button>
                                </DialogClose>
                                <Button type="submit" variant="destructive" :disabled="processing" data-test="confirm-delete-user-button">Delete account</Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    </div>
</template>
