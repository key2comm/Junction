<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import CaptureController from '@/actions/App/Http/Controllers/Settings/CaptureController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateTime } from '@/lib/format';
import { edit } from '@/routes/captures';

const props = defineProps<{
    settings: { enabled: boolean; retention_days: number };
    status: { raw_files: number; raw_bytes: number; latest: string | null; checked_at: string } | null;
    rawHours: number;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Call captures', href: edit() }] } });

// The capture writes all the time; quiet for over 10 minutes means it isn't running (or no SIP at all).
const running = props.status?.latest ? Date.now() - new Date(props.status.latest).getTime() < 10 * 60 * 1000 : false;
const mb = (b: number) => `${(b / 1048576).toFixed(1)} MB`;
</script>

<template>
    <Head title="Call captures" />

    <h1 class="visually-hidden">Call captures</h1>

    <Form v-bind="CaptureController.update.form()" class="ss-stack-4 ss-maxw-3xl" v-slot="{ errors, processing }">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <Heading
                variant="small"
                title="Call captures (PCAP)"
                description="The SIP signalling of every call (the customer's leg and the carrier or device legs) is saved as a PCAP, downloadable from Calls → Call records."
            />
            <Badge v-if="status" :variant="running ? 'default' : 'destructive'">{{ running ? 'Capturing' : 'Not capturing' }}</Badge>
        </div>

        <section class="card card-body ss-stack-3">
            <label class="d-flex align-items-center gap-2">
                <input type="checkbox" name="enabled" value="1" :checked="settings.enabled" class="form-check-input m-0" /> Save a PCAP for every call
            </label>
            <div class="ss-grid gap-1">
                <Label for="retention_days">Keep PCAPs for</Label>
                <div class="input-group ss-maxw-xs">
                    <Input id="retention_days" name="retention_days" type="number" min="1" max="3650" :default-value="settings.retention_days" required />
                    <span class="input-group-text">days</span>
                </div>
                <p class="small text-secondary mb-0">Older ones are deleted automatically each night. Default 90 days.</p>
                <InputError :message="errors.retention_days" />
            </div>
        </section>

        <section class="card card-body small text-secondary ss-stack-2">
            <p class="mb-0">
                Signalling only (no audio). Calls on the PBX port (5060) and the carrier port (5080) are captured; TLS (5061) and the web phone (WSS) are
                encrypted, so they can't be. Each call's PCAP is ready about a minute after it ends.
            </p>
            <p v-if="status" class="mb-0">
                Raw capture: {{ status.raw_files }} file(s), {{ mb(status.raw_bytes) }} (kept {{ rawHours }} hours)<template v-if="status.latest">, last written {{ dateTime(status.latest) }}</template>.
            </p>
        </section>

        <div>
            <Button :disabled="processing">Save</Button>
        </div>
    </Form>
</template>
