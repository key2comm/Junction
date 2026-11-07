<script setup lang="ts">
import { Form, Head, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import EmailController from '@/actions/App/Http/Controllers/Settings/EmailController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { selectClass } from '@/lib/ui';
import { edit } from '@/routes/email';

type Mail = {
    host: string | null;
    port: number | null;
    security: 'starttls' | 'tls';
    username: string | null;
    has_password: boolean;
    from_address: string | null;
    from_name: string | null;
    admin_address: string | null;
};

const props = defineProps<{
    mail: Mail;
    configured: boolean;
    test: { state: 'queued' | 'sent' | 'failed'; to: string; message: string | null; at: string } | null;
    defaultTestTo: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Email', href: edit() }] } });

const waiting = computed(() => props.test?.state === 'queued');
const { start, stop } = usePoll(2000, { only: ['test'] }, { autoStart: false });
watch(waiting, (w) => (w ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="Email settings" />

    <h1 class="visually-hidden">Email settings</h1>

    <div class="ss-stack-4">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <Heading variant="small" title="Outgoing email" description="The SMTP server used to send invoices, low-balance alerts and fraud alerts" />
            <Badge :variant="configured ? 'default' : 'secondary'">{{ configured ? 'Configured' : 'Not set up' }}</Badge>
        </div>

        <Form v-bind="EmailController.update.form()" class="ss-grid gap-3 ss-cols-sm-2" v-slot="{ errors, processing }">
            <div class="ss-grid gap-1 ss-span-sm-2">
                <Label for="host">SMTP server</Label>
                <Input id="host" name="host" :default-value="mail.host ?? ''" required placeholder="smtp.office365.com" autocapitalize="none" />
                <InputError :message="errors.host" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="security">Security</Label>
                <select id="security" name="security" :class="selectClass">
                    <option value="starttls" :selected="mail.security === 'starttls'">STARTTLS (usually port 587)</option>
                    <option value="tls" :selected="mail.security === 'tls'">SSL/TLS (usually port 465)</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="port">Port</Label>
                <Input id="port" name="port" type="number" min="1" max="65535" :default-value="mail.port ?? 587" required />
                <InputError :message="errors.port" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="username">Username</Label>
                <Input id="username" name="username" :default-value="mail.username ?? ''" autocomplete="off" />
                <InputError :message="errors.username" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="password">Password</Label>
                <Input id="password" name="password" type="password" autocomplete="new-password" :placeholder="mail.has_password ? '•••••• (unchanged)' : ''" />
                <label v-if="mail.has_password" class="d-flex align-items-center gap-2 small text-secondary">
                    <input type="checkbox" name="clear_password" value="1" class="icon" /> Remove the saved password
                </label>
                <InputError :message="errors.password" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="from_address">From address</Label>
                <Input id="from_address" name="from_address" type="email" :default-value="mail.from_address ?? ''" required placeholder="billing@example.com" />
                <InputError :message="errors.from_address" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="from_name">From name</Label>
                <Input id="from_name" name="from_name" :default-value="mail.from_name ?? ''" />
            </div>
            <div class="ss-grid gap-1 ss-span-sm-2">
                <Label for="admin_address">Your copy of alerts and invoices</Label>
                <Input id="admin_address" name="admin_address" type="email" :default-value="mail.admin_address ?? ''" placeholder="noc@example.com (defaults to the From address)" />
                <InputError :message="errors.admin_address" />
            </div>
            <div class="ss-span-sm-2">
                <Button :disabled="processing">Save</Button>
            </div>
        </Form>
    </div>

    <div class="ss-stack-3">
        <Heading variant="small" title="Send a test email" />
        <Form v-bind="EmailController.test.form()" class="d-flex flex-wrap align-items-start gap-2" v-slot="{ errors, processing }">
            <div class="ss-grid gap-1">
                <Input name="to" type="email" :default-value="defaultTestTo" required class="ss-w-72" aria-label="Send test to" />
                <InputError :message="errors.to" />
            </div>
            <Button variant="outline" :disabled="processing || waiting || !configured">Send test</Button>
        </Form>

        <Alert v-if="test?.state === 'queued'">
            <Spinner />
            <AlertTitle>Sending to {{ test.to }}…</AlertTitle>
        </Alert>
        <Alert v-else-if="test?.state === 'sent'">
            <AlertTitle>Test email sent to {{ test.to }}</AlertTitle>
            <AlertDescription>The SMTP server accepted it. Check the inbox (and spam folder).</AlertDescription>
        </Alert>
        <Alert v-else-if="test?.state === 'failed'" variant="destructive">
            <AlertTitle>Test email to {{ test.to }} failed</AlertTitle>
            <AlertDescription class="text-break">{{ test.message }}</AlertDescription>
        </Alert>
    </div>
</template>
