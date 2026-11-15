<script setup lang="ts">
/** The link in an admin invitation: choose a name and password, then you're signed in. */
import { Form, Head } from '@inertiajs/vue3';
import InvitationController from '@/actions/App/Http/Controllers/Auth/InvitationController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: {
        title: 'Accept your invitation',
        description: 'Choose your name and a password to finish setting up your login',
    },
});

defineProps<{ token: string; email: string; role: string; invitedBy: string | null; passwordRules: string }>();
</script>

<template>
    <Head title="Accept invitation" />

    <p class="text-secondary text-center">
        {{ invitedBy ? `${invitedBy} invited you` : "You're invited" }} as <strong>{{ role }}</strong>.
    </p>

    <Form v-bind="InvitationController.accept.form(token)" :reset-on-error="['password', 'password_confirmation']" v-slot="{ errors, processing }">
        <div class="ss-grid gap-4">
            <div class="ss-grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" type="email" :model-value="email" autocomplete="email" readonly />
            </div>

            <div class="ss-grid gap-2">
                <Label for="name">Your name</Label>
                <Input id="name" name="name" required autofocus autocomplete="name" />
                <InputError :message="errors.name" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput id="password" name="password" autocomplete="new-password" required :passwordrules="passwordRules" />
                <InputError :message="errors.password" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput id="password_confirmation" name="password_confirmation" autocomplete="new-password" required :passwordrules="passwordRules" />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button type="submit" class="mt-2 w-100" :disabled="processing">
                <Spinner v-if="processing" />
                Create my login
            </Button>
        </div>
    </Form>
</template>
