<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

defineProps<{ timezones: string[] }>();

const page = usePage();
const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="visually-hidden">Profile settings</h1>

    <div class="d-flex flex-column ss-stack-4">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name, email address and time zone"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="ss-stack-4"
            v-slot="{ errors, processing }"
        >
            <div class="ss-grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 d-block w-100"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 d-block w-100"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="timezone">Time zone</Label>
                <select id="timezone" name="timezone" :class="selectClass" :value="user.timezone ?? 'UTC'">
                    <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
                <p class="small text-secondary mb-0">
                    Times across the site are shown in this zone. Calls are recorded in UTC.
                    <template v-if="browserTz && browserTz !== user.timezone">This browser is set to {{ browserTz }}.</template>
                </p>
                <InputError class="mt-2" :message="errors.timezone" />
            </div>


            <div class="d-flex align-items-center gap-3">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
