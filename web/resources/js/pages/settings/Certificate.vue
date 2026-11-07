<script setup lang="ts">
import { Form, Head, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CertificateController from '@/actions/App/Http/Controllers/Settings/CertificateController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/certificate';

type Status = {
    mode: 'selfsigned' | 'letsencrypt';
    domain: string;
    expires: string;
    subject: string;
};

type Task = {
    state: 'queued' | 'running' | 'done' | 'failed';
    action: 'status' | 'issue' | 'selfsigned';
    message: string | null;
    at: string;
};

const props = defineProps<{
    status: Status | null;
    task: Task | null;
    busy: boolean;
    defaults: { domain: string; email: string };
    publicIp: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'SSL certificate', href: edit() }],
    },
});

// While a certificate action is in progress (or the first status check is
// pending), reload the page props every 3 seconds.
const waiting = computed(() => props.busy || props.status === null);
const { start, stop } = usePoll(3000, { only: ['status', 'task', 'busy'] }, { autoStart: false });
watch(waiting, (value) => (value ? start() : stop()), { immediate: true });

const expires = computed(() =>
    props.status?.expires ? new Date(props.status.expires).toLocaleDateString() : null,
);
const actionLabel: Record<Task['action'], string> = {
    status: 'Checking certificate',
    issue: 'Requesting certificate from Let’s Encrypt',
    selfsigned: 'Switching to self-signed certificate',
};
</script>

<template>
    <Head title="SSL certificate" />

    <h1 class="visually-hidden">SSL certificate</h1>

    <div class="ss-stack-4">
        <Heading
            variant="small"
            title="SSL certificate"
            description="The HTTPS certificate for this server's admin UI and API"
        />

        <div class="card card-body ss-stack-2">
            <div v-if="status" class="d-flex flex-wrap align-items-center gap-2">
                <Badge v-if="status.mode === 'letsencrypt'">Let's Encrypt</Badge>
                <Badge v-else variant="secondary">Self-signed</Badge>
                <span class="fw-medium">{{ status.domain || status.subject }}</span>
            </div>
            <div v-else class="d-flex align-items-center gap-2 text-secondary">
                <Spinner /> Checking current certificate…
            </div>
            <p v-if="expires" class="text-secondary">Expires {{ expires }}<span v-if="status?.mode === 'letsencrypt'"> (renews automatically)</span></p>
            <p v-if="status?.mode === 'selfsigned'" class="text-secondary">
                Browsers show a security warning for self-signed certificates.
            </p>
        </div>

        <Alert v-if="busy && task">
            <Spinner />
            <AlertTitle>{{ actionLabel[task.action] }}…</AlertTitle>
            <AlertDescription>This usually takes under a minute.</AlertDescription>
        </Alert>
        <Alert v-else-if="task?.state === 'failed'" variant="destructive">
            <AlertTitle>{{ actionLabel[task.action] }} failed</AlertTitle>
            <AlertDescription>{{ task.message }}</AlertDescription>
        </Alert>
    </div>

    <div class="ss-stack-4">
        <Heading
            variant="small"
            title="Use Let's Encrypt"
            description="Get a free, trusted certificate. The domain's DNS must point to this server, and port 80 must be reachable from the internet."
        />

        <Form
            v-bind="CertificateController.store.form()"
            class="ss-stack-4"
            v-slot="{ errors, processing }"
        >
            <div class="ss-grid gap-2">
                <Label for="domain">Domain</Label>
                <Input
                    id="domain"
                    name="domain"
                    :default-value="defaults.domain"
                    required
                    placeholder="switch.example.com"
                    autocapitalize="none"
                    spellcheck="false"
                />
                <p v-if="publicIp" class="small text-secondary">
                    Needs an A record pointing to {{ publicIp }}
                </p>
                <InputError :message="errors.domain" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="email">Email for expiry notices</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    :default-value="defaults.email"
                    required
                />
                <InputError :message="errors.email" />
            </div>

            <Button :disabled="processing || busy">
                {{ status?.mode === 'letsencrypt' ? 'Renew / change certificate' : 'Request certificate' }}
            </Button>
        </Form>
    </div>

    <div v-if="status?.mode === 'letsencrypt'" class="ss-stack-3">
        <Heading
            variant="small"
            title="Use self-signed certificate"
            description="Switch back to the built-in certificate. The Let's Encrypt certificate is kept and can be re-activated."
        />
        <Form v-bind="CertificateController.destroy.form()" v-slot="{ processing }">
            <Button variant="outline" :disabled="processing || busy">Switch to self-signed</Button>
        </Form>
    </div>
</template>
