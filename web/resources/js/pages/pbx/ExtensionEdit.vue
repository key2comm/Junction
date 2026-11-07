<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PbxController from '@/actions/App/Http/Controllers/Admin/PbxController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show } from '@/routes/accounts/pbx';
import { index } from '@/routes/pbx';

const props = defineProps<{
    account: { id: number; name: string; code: string };
    extension: {
        id: number;
        extension: string;
        name: string | null;
        sip_username: string;
        caller_id_number: string | null;
        outbound_enabled: boolean;
        voicemail_enabled: boolean;
        voicemail_pin: string | null;
        ring_seconds: number;
        enabled: boolean;
    };
}>();

defineOptions({
    layout: (props: { account: { id: number; name: string }; extension: { extension: string } }) => ({
        breadcrumbs: [
            { title: 'PBX', href: index() },
            { title: props.account.name, href: show(props.account.id) },
            { title: `Extension ${props.extension.extension}`, href: '#' },
        ],
    }),
});
</script>

<template>
    <Head :title="`Extension ${props.extension.extension}`" />

    <div class="max-w-3xl space-y-6 p-4">
        <Heading :title="`Extension ${extension.extension}`" :description="account.name" />

        <Form v-bind="PbxController.updateExtension.form([account.id, extension.id])" class="space-y-6" v-slot="{ errors, processing }">
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="extension">Extension</Label>
                    <Input id="extension" name="extension" :default-value="extension.extension" required inputmode="numeric" class="font-mono" />
                    <InputError :message="errors.extension" />
                </div>
                <div class="grid gap-1 sm:col-span-4">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" :default-value="extension.name ?? ''" />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1 sm:col-span-3">
                    <Label for="sip_username">SIP username</Label>
                    <Input id="sip_username" name="sip_username" :default-value="extension.sip_username" autocomplete="off" class="font-mono lowercase" />
                    <InputError :message="errors.sip_username" />
                </div>
                <div class="grid gap-1 sm:col-span-3">
                    <Label for="sip_password">New SIP password</Label>
                    <Input id="sip_password" name="sip_password" type="password" autocomplete="new-password" placeholder="unchanged" />
                    <InputError :message="errors.sip_password" />
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="regenerate_password" value="1" class="size-4" /> Generate a new random password</label>
                </div>
                <div class="grid gap-1 sm:col-span-3">
                    <Label for="caller_id_number">Caller ID for outside calls</Label>
                    <Input id="caller_id_number" name="caller_id_number" :default-value="extension.caller_id_number ?? ''" inputmode="numeric" class="font-mono" />
                    <InputError :message="errors.caller_id_number" />
                </div>
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="voicemail_pin">Voicemail PIN</Label>
                    <Input id="voicemail_pin" name="voicemail_pin" :default-value="extension.voicemail_pin ?? ''" inputmode="numeric" autocomplete="off" class="font-mono" />
                    <InputError :message="errors.voicemail_pin" />
                </div>
                <div class="grid gap-1">
                    <Label for="ring_seconds">Ring (s)</Label>
                    <Input id="ring_seconds" name="ring_seconds" type="number" min="5" max="120" :default-value="extension.ring_seconds" required />
                    <InputError :message="errors.ring_seconds" />
                </div>
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="outbound_enabled" value="1" :checked="extension.outbound_enabled" class="size-4" /> Outside calls</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="voicemail_enabled" value="1" :checked="extension.voicemail_enabled" class="size-4" /> Voicemail</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" :checked="extension.enabled" class="size-4" /> Enabled</label>
            </div>
            <p class="text-sm text-muted-foreground">A new password signs the phone out at once; enter the new one on the phone.</p>
            <Button :disabled="processing">Save</Button>
        </Form>
    </div>
</template>
