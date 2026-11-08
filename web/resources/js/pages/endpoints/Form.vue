<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import EndpointController from '@/actions/App/Http/Controllers/Admin/EndpointController';
import ConfirmByName from '@/components/ConfirmByName.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { index } from '@/routes/endpoints';

type Kind = 'pbx' | 'device';
type Flow = 'always' | 'no_answer' | 'busy' | 'failed' | 'pbx';
type Endpoint = {
    id: number;
    account_id: number;
    kind: Kind;
    name: string;
    enabled: boolean;
    hosts: string[];
    transport: 'udp' | 'tcp' | 'tls';
    port: number;
    delivery: 'sip' | 'flow';
    flow: Flow | null;
    forward_number: string | null;
    ring_seconds: number;
    pbx_destination: { type: string; target?: string } | null;
    codecs: string[];
    caller_id_number: string | null;
    forward_caller_id: 'original' | 'endpoint';
    outbound_enabled: boolean;
    max_inbound: number | null;
    max_outbound: number | null;
    max_total: number | null;
    notes: string | null;
};
type PbxNumber = { number: string; kind: 'extension' | 'ivr'; label: string; voicemail: boolean };

const props = defineProps<{
    endpoint: Endpoint | null;
    defaults: { account_id?: number | null };
    customers: { id: number; name: string; code: string }[];
    kinds: Record<Kind, string>;
    transports: Record<string, string>;
    codecs: Record<string, string>;
    flows: Record<Flow, string>;
    pbxNumbers: Record<string, PbxNumber[]>;
}>();

defineOptions({
    layout: (p: { endpoint: Endpoint | null }) => ({
        breadcrumbs: [
            { title: 'PBX/Devices', href: index() },
            { title: p.endpoint ? p.endpoint.name : 'New PBX/Device', href: index() },
        ],
    }),
});

const e = props.endpoint;
const hosts = [...(e?.hosts ?? [])];
while (hosts.length < 3) hosts.push('');

const form = useForm({
    account_id: e?.account_id ?? props.defaults.account_id ?? '',
    kind: (e?.kind ?? 'pbx') as Kind,
    name: e?.name ?? '',
    enabled: e?.enabled ?? true,
    hosts,
    transport: e?.transport ?? 'udp',
    port: e?.port ?? 5060,
    delivery: e?.delivery ?? 'sip',
    flow: (e?.flow ?? 'no_answer') as Flow,
    forward_number: e?.forward_number ?? '',
    ring_seconds: e?.ring_seconds ?? 25,
    pbx_destination: { type: e?.pbx_destination?.type ?? 'number', target: e?.pbx_destination?.target ?? '' },
    codecs: e?.codecs ?? ['PCMU', 'PCMA'],
    caller_id_number: e?.caller_id_number ?? '',
    forward_caller_id: e?.forward_caller_id ?? 'original',
    outbound_enabled: e?.outbound_enabled ?? true,
    max_inbound: e?.max_inbound ?? '',
    max_outbound: e?.max_outbound ?? '',
    max_total: e?.max_total ?? '',
    notes: e?.notes ?? '',
});

const isDevice = computed(() => form.kind === 'device');
const usesFlow = computed(() => isDevice.value && form.delivery === 'flow');
// Hosts are rung directly, or first for no answer / busy / failed.
const ringsHosts = computed(() => !usesFlow.value || ['no_answer', 'busy', 'failed'].includes(form.flow));
const forwards = computed(() => usesFlow.value && form.flow !== 'pbx');
const hostCount = computed(() => (isDevice.value ? 3 : 1));
const numbers = computed(() => props.pbxNumbers[String(form.account_id)] ?? []);
const pbxTargets = computed(() => (form.pbx_destination.type === 'voicemail' ? numbers.value.filter((n) => n.kind === 'extension' && n.voicemail) : numbers.value));

// The usual port for the transport, unless one was typed.
watch(
    () => form.transport,
    (t, old) => {
        if (form.port === (old === 'tls' ? 5061 : 5060)) form.port = t === 'tls' ? 5061 : 5060;
    },
);

const codecOrder = Object.keys(props.codecs);
function toggleCodec(c: string, on: boolean) {
    const set = new Set(form.codecs);
    if (on) set.add(c);
    else set.delete(c);
    form.codecs = codecOrder.filter((x) => set.has(x)); // keep the preference order
}

const err = (k: string) => (form.errors as Record<string, string>)[k];
const hostError = computed(() => err('hosts') ?? [0, 1, 2].map((i) => err(`hosts.${i}`)).find(Boolean));

function submit() {
    form.transform((d) => ({
        ...d,
        hosts: d.hosts.slice(0, hostCount.value).filter((h) => h.trim() !== ''),
        delivery: isDevice.value ? d.delivery : 'sip',
    }));
    if (e) form.put(EndpointController.update.url(e.id), { preserveScroll: true });
    else form.post(EndpointController.store.url(), { preserveScroll: true });
}
</script>

<template>
    <Head :title="endpoint ? endpoint.name : 'New PBX/Device'" />

    <div class="ss-maxw-3xl ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading
                :title="endpoint ? endpoint.name : 'New PBX/Device'"
                description="Where a customer's calls are delivered. DIDs (PBX page, and soon Routing → DIDs) choose one of these."
            />
            <ConfirmByName
                v-if="endpoint"
                :action="EndpointController.destroy.form(endpoint.id)"
                :name="endpoint.name"
                :title="`Delete ${endpoint.name}?`"
                description="Calls can no longer be delivered to it, and calls from its addresses are no longer accepted. Past call records are kept."
                label="Delete"
            />
        </div>

        <form class="ss-stack-4" @submit.prevent="submit">
            <!-- What and whose -->
            <section class="ss-grid gap-3 ss-cols-sm-2">
                <div class="ss-grid gap-1">
                    <Label for="account_id">Customer</Label>
                    <select id="account_id" v-model="form.account_id" :class="selectClass" required>
                        <option value="" disabled>Choose…</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                    </select>
                    <InputError :message="form.errors.account_id" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required maxlength="100" placeholder="Head office PBX" />
                    <InputError :message="form.errors.name" />
                </div>
                <fieldset class="ss-grid gap-1 ss-span-sm-2">
                    <legend class="fw-medium fs-5 mb-1">Type</legend>
                    <div class="d-flex flex-wrap gap-4">
                        <label v-for="(label, k) in kinds" :key="k" class="d-flex align-items-center gap-2">
                            <input v-model="form.kind" type="radio" :value="k" class="form-check-input m-0" /> {{ label }}
                        </label>
                    </div>
                    <p class="small text-secondary mb-0">
                        {{ isDevice ? 'A device has up to three addresses (e.g. Twilio) and can use a call flow.' : 'A PBX has one address: its IP or hostname.' }}
                    </p>
                </fieldset>
                <label class="d-flex align-items-center gap-2">
                    <input v-model="form.enabled" type="checkbox" class="form-check-input m-0" /> Enabled
                </label>
            </section>

            <!-- How calls reach it -->
            <section class="ss-stack-3">
                <h2 class="fw-semibold mb-0">Sending calls</h2>

                <fieldset v-if="isDevice" class="ss-grid gap-1">
                    <legend class="fw-medium fs-5 mb-1">How do you want to send calls?</legend>
                    <label class="d-flex align-items-center gap-2"><input v-model="form.delivery" type="radio" value="sip" class="form-check-input m-0" /> To its IP/FQDN</label>
                    <label class="d-flex align-items-center gap-2"><input v-model="form.delivery" type="radio" value="flow" class="form-check-input m-0" /> With a call flow</label>
                </fieldset>

                <fieldset v-if="usesFlow" class="ss-grid gap-1 rounded border p-3">
                    <legend class="fw-medium fs-5 mb-1 float-none w-auto px-1">Call flow (choose one)</legend>
                    <label v-for="(label, f) in flows" :key="f" class="d-flex align-items-start gap-2">
                        <input v-model="form.flow" type="radio" :value="f" class="form-check-input m-0 mt-1" />
                        <span>
                            {{ label }}
                            <span class="d-block small text-secondary">
                                <template v-if="f === 'always'">Every call goes straight to the forward number.</template>
                                <template v-else-if="f === 'no_answer'">Ring the device; if nobody answers in time, forward.</template>
                                <template v-else-if="f === 'busy'">Ring the device; if it's busy, forward.</template>
                                <template v-else-if="f === 'failed'">Ring the device; if the call can't connect (offline, error), forward.</template>
                                <template v-else>Send calls to an extension, IVR or voicemail on this switch's PBX.</template>
                            </span>
                        </span>
                    </label>
                    <InputError :message="form.errors.flow" />
                </fieldset>

                <div v-if="ringsHosts" class="ss-grid gap-3 ss-cols-sm-3">
                    <div class="ss-grid gap-1 ss-span-sm-3">
                        <Label for="host0">{{ isDevice ? 'Addresses (IP or FQDN, tried in order)' : 'Address (IP or FQDN)' }}</Label>
                        <Input
                            v-for="i in hostCount"
                            :id="`host${i - 1}`"
                            :key="i"
                            v-model="form.hosts[i - 1]"
                            class="font-monospace"
                            :placeholder="isDevice ? ['example.pstn.twilio.com', 'second address (optional)', 'third address (optional)'][i - 1] : 'pbx.example.com or 203.0.113.10'"
                            :aria-label="`Address ${i}`"
                        />
                        <InputError :message="hostError" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="transport">Transport</Label>
                        <select id="transport" v-model="form.transport" :class="selectClass">
                            <option v-for="(label, t) in transports" :key="t" :value="t">{{ label }}</option>
                        </select>
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="port">Port</Label>
                        <Input id="port" v-model="form.port" type="number" min="1" max="65535" required />
                        <InputError :message="form.errors.port" />
                    </div>
                    <div v-if="usesFlow && form.flow === 'no_answer'" class="ss-grid gap-1">
                        <Label for="ring_seconds">Ring for (seconds)</Label>
                        <Input id="ring_seconds" v-model="form.ring_seconds" type="number" min="5" max="120" />
                        <InputError :message="form.errors.ring_seconds" />
                    </div>
                </div>

                <div v-if="forwards" class="ss-grid gap-3 ss-cols-sm-2">
                    <div class="ss-grid gap-1">
                        <Label for="forward_number">Forward to</Label>
                        <Input id="forward_number" v-model="form.forward_number" inputmode="numeric" class="font-monospace" placeholder="14165551234" />
                        <p class="small text-secondary mb-0">The full number with country code. Forwards go out through the customer's LCR and are billed like their other calls.</p>
                        <InputError :message="form.errors.forward_number" />
                    </div>
                    <fieldset class="ss-grid gap-1">
                        <legend class="fw-medium fs-5 mb-1">Forwarded calls show</legend>
                        <label class="d-flex align-items-center gap-2">
                            <input v-model="form.forward_caller_id" type="radio" value="original" class="form-check-input m-0" /> The original caller's number
                        </label>
                        <label class="d-flex align-items-center gap-2">
                            <input v-model="form.forward_caller_id" type="radio" value="endpoint" class="form-check-input m-0" /> The caller ID below
                        </label>
                    </fieldset>
                </div>

                <div v-if="usesFlow && form.flow === 'pbx'" class="ss-grid gap-1">
                    <Label for="pbx_type">Destination on this softswitch's PBX</Label>
                    <div v-if="numbers.length" class="d-flex flex-wrap gap-2">
                        <select id="pbx_type" v-model="form.pbx_destination.type" :class="selectClass" class="w-auto">
                            <option value="number">Ring / go to</option>
                            <option value="voicemail">Voicemail of</option>
                        </select>
                        <select v-model="form.pbx_destination.target" :class="selectClass" class="w-auto ss-minw-48" aria-label="PBX number">
                            <option value="" disabled>Choose…</option>
                            <option v-for="n in pbxTargets" :key="n.number" :value="n.number">{{ n.label }}</option>
                        </select>
                    </div>
                    <p v-else class="text-secondary mb-0">This customer has no extensions or IVRs yet (PBX page).</p>
                    <InputError :message="err('pbx_destination.type')" />
                </div>
            </section>

            <!-- Media and caller ID -->
            <section class="ss-grid gap-3 ss-cols-sm-2">
                <fieldset class="ss-grid gap-1">
                    <legend class="fw-medium fs-5 mb-1">Codecs (in order of preference)</legend>
                    <label v-for="(label, c) in codecs" :key="c" class="d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input m-0" :checked="form.codecs.includes(c)" @change="toggleCodec(c, ($event.target as HTMLInputElement).checked)" />
                        {{ label }}
                    </label>
                    <p class="small text-secondary mb-0">The switch transcodes when the other side uses a different codec.</p>
                    <InputError :message="form.errors.codecs" />
                </fieldset>
                <div class="ss-stack-3">
                    <div class="ss-grid gap-1">
                        <Label for="caller_id_number">Caller ID <span class="small text-secondary">(optional)</span></Label>
                        <Input id="caller_id_number" v-model="form.caller_id_number" inputmode="tel" class="font-monospace" placeholder="14165550000" />
                        <p class="small text-secondary mb-0">
                            Shown on calls it makes out{{ forwards ? ' (and on forwards, if chosen above)' : '' }}. Blank keeps the caller's own number. Carriers may override it.
                        </p>
                        <InputError :message="form.errors.caller_id_number" />
                    </div>
                    <label class="d-flex align-items-start gap-2">
                        <input v-model="form.outbound_enabled" type="checkbox" class="form-check-input m-0 mt-1" />
                        <span>
                            Accept outbound calls from it
                            <span class="d-block small text-secondary">Calls arriving from its addresses go out through the customer's LCR. Turn off for services like Twilio that should only receive calls.</span>
                        </span>
                    </label>
                </div>
            </section>

            <!-- Limits -->
            <section class="ss-stack-2">
                <h2 class="fw-semibold mb-0">Concurrent call limits</h2>
                <div class="ss-grid gap-3 ss-cols-sm-3">
                    <div class="ss-grid gap-1">
                        <Label for="max_inbound">Inbound</Label>
                        <Input id="max_inbound" v-model="form.max_inbound" type="number" min="1" placeholder="Unlimited" />
                        <InputError :message="form.errors.max_inbound" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="max_outbound">Outbound</Label>
                        <Input id="max_outbound" v-model="form.max_outbound" type="number" min="1" placeholder="Unlimited" />
                        <InputError :message="form.errors.max_outbound" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="max_total">Total (inbound + outbound)</Label>
                        <Input id="max_total" v-model="form.max_total" type="number" min="1" placeholder="Unlimited" />
                        <InputError :message="form.errors.max_total" />
                    </div>
                </div>
                <p class="small text-secondary mb-0">The customer's own account limits still apply on top.</p>
            </section>

            <div class="ss-grid gap-1">
                <Label for="notes">Notes</Label>
                <textarea id="notes" v-model="form.notes" rows="2" class="form-control"></textarea>
            </div>

            <div class="d-flex gap-2">
                <Button type="submit" :disabled="form.processing">{{ endpoint ? 'Save' : 'Create' }}</Button>
                <Button variant="ghost" as-child><Link :href="index()">Cancel</Link></Button>
            </div>
        </form>
    </div>
</template>
