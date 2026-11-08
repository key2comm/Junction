<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Copy, Eye, EyeOff, Plus, RefreshCw, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EndpointController from '@/actions/App/Http/Controllers/Admin/EndpointController';
import ConfirmByName from '@/components/ConfirmByName.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { index as didsIndex } from '@/routes/dids';
import { index } from '@/routes/endpoints';

type Kind = 'pbx' | 'device';
type Flow = 'always' | 'no_answer' | 'busy' | 'failed' | 'pbx';
type RuleType = 'domain' | 'ip' | 'number';
type Endpoint = {
    id: number;
    account_id: number | null;
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
    max_inbound: number | null;
    max_outbound: number | null;
    max_total: number | null;
    notes: string | null;
    sip_username: string | null;
    sip_password: string | null;
    rules: { type: RuleType; value: string }[];
    dids: number;
};
type PbxNumber = { number: string; kind: 'extension' | 'ivr'; label: string; voicemail: boolean };

const props = defineProps<{
    endpoint: Endpoint | null;
    defaults: { account_id?: number | null; kind?: Kind; sip_password?: string };
    customers: { id: number; name: string; code: string }[];
    kinds: Record<Kind, string>;
    transports: Record<string, string>;
    codecs: Record<string, string>;
    flows: Record<Flow, string>;
    ruleTypes: Record<RuleType, string>;
    connection: { host: string; sip: string; domain: string };
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
    kind: (e?.kind ?? props.defaults.kind ?? 'pbx') as Kind,
    name: e?.name ?? '',
    enabled: e?.enabled ?? true,
    hosts,
    transport: e?.transport ?? 'udp',
    port: e?.port ?? 5060,
    delivery: e?.delivery ?? 'sip',
    // Where calls to a device go: its IP/FQDN, or down its SIP trunk (the PBX registers).
    target: (e?.sip_username ? 'trunk' : 'hosts') as 'hosts' | 'trunk',
    sip_username: e?.sip_username ?? '',
    sip_password: e?.sip_password ?? props.defaults.sip_password ?? '',
    flow: (e?.flow ?? 'no_answer') as Flow,
    forward_number: e?.forward_number ?? '',
    ring_seconds: e?.ring_seconds ?? 25,
    pbx_destination: { type: e?.pbx_destination?.type ?? 'number', target: e?.pbx_destination?.target ?? '' },
    codecs: e?.codecs ?? ['PCMU', 'PCMA'],
    caller_id_number: e?.caller_id_number ?? '',
    forward_caller_id: e?.forward_caller_id ?? 'original',
    max_inbound: e?.max_inbound ?? '',
    max_outbound: e?.max_outbound ?? '',
    max_total: e?.max_total ?? '',
    notes: e?.notes ?? '',
    rules: (e?.rules ?? []).map((r) => ({ ...r })),
});

const tab = ref<'sending' | 'receiving'>('sending');
const isDevice = computed(() => form.kind === 'device');
const usesFlow = computed(() => isDevice.value && form.delivery === 'flow');
// Calls are sent to it directly, or first for no answer / busy / failed.
const ringsIt = computed(() => !usesFlow.value || ['no_answer', 'busy', 'failed'].includes(form.flow));
const trunk = computed(() => isDevice.value && form.target === 'trunk');
const forwards = computed(() => usesFlow.value && form.flow !== 'pbx');
const hostCount = computed(() => (isDevice.value ? 3 : 1));
const numbers = computed(() => props.pbxNumbers[String(form.account_id)] ?? []);
const pbxTargets = computed(() => (form.pbx_destination.type === 'voicemail' ? numbers.value.filter((n) => n.kind === 'extension' && n.voicemail) : numbers.value));
const customer = computed(() => props.customers.find((c) => c.id === form.account_id));

// The usual port for the transport, unless one was typed.
watch(
    () => form.transport,
    (t, old) => {
        if (Number(form.port) === (old === 'tls' ? 5061 : 5060)) form.port = t === 'tls' ? 5061 : 5060;
    },
);
// Suggest a trunk username from the customer and name.
watch(trunk, (on) => {
    if (on && !form.sip_username) {
        const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '');
        form.sip_username = [slug(customer.value?.code ?? ''), slug(form.name)].filter(Boolean).join('.') || 'trunk';
    }
});

const codecOrder = Object.keys(props.codecs);
function toggleCodec(c: string, on: boolean) {
    const set = new Set(form.codecs);
    if (on) set.add(c);
    else set.delete(c);
    form.codecs = codecOrder.filter((x) => set.has(x)); // keep the preference order
}

const showPassword = ref(!e);
function newPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = crypto.getRandomValues(new Uint8Array(20));
    form.sip_password = Array.from(bytes, (b) => chars[b % chars.length]).join('');
    showPassword.value = true;
}
const copied = ref('');
async function copy(what: string, text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        setTimeout(() => (copied.value = ''), 1500);
    } catch {
        // Clipboard blocked: the text is on screen to copy by hand.
    }
}

const ruleHelp: Record<RuleType, { placeholder: string; help: string }> = {
    domain: { placeholder: 'tenant.example.com', help: 'Calls From …@tenant.example.com (e.g. 100@tenant.example.com).' },
    ip: { placeholder: '203.0.113.10 or pbx.example.com', help: 'Calls coming from this address.' },
    number: { placeholder: '+14165551234', help: 'Calls showing this caller number, exactly as sent. Add each format the PBX may use.' },
};
const addRule = (type: RuleType) => form.rules.push({ type, value: '' });

const err = (k: string) => (form.errors as Record<string, string>)[k];
const hostError = computed(() => err('hosts') ?? [0, 1, 2].map((i) => err(`hosts.${i}`)).find(Boolean));
const sendingErrors = computed(() => Object.keys(form.errors).some((k) => !k.startsWith('rules')));
const receivingErrors = computed(() => Object.keys(form.errors).some((k) => k.startsWith('rules')));
watch(
    () => form.errors,
    () => {
        if (receivingErrors.value && !sendingErrors.value) tab.value = 'receiving';
        else if (sendingErrors.value && !receivingErrors.value) tab.value = 'sending';
    },
);

function submit() {
    form.transform((d) => ({
        ...d,
        account_id: isDevice.value ? d.account_id : null,
        hosts: trunk.value ? [] : d.hosts.slice(0, hostCount.value).filter((h) => h.trim() !== ''),
        delivery: isDevice.value ? d.delivery : 'sip',
        target: isDevice.value ? d.target : 'hosts',
        rules: isDevice.value ? d.rules.filter((r) => r.value.trim() !== '') : [],
    }));
    if (e) form.put(EndpointController.update.url(e.id), { preserveScroll: true });
    else form.post(EndpointController.store.url(), { preserveScroll: true });
}
</script>

<template>
    <Head :title="endpoint ? endpoint.name : 'New PBX/Device'" />

    <div class="ss-maxw-3xl ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading :title="endpoint ? endpoint.name : 'New PBX/Device'" description="Where DIDs are delivered: a shared PBX, or a customer's device." />
            <ConfirmByName
                v-if="endpoint"
                :action="EndpointController.destroy.form(endpoint.id)"
                :name="endpoint.name"
                :title="`Delete ${endpoint.name}?`"
                description="Calls can no longer be delivered to it or recognised by its rules. Past call records are kept."
                label="Delete"
            />
        </div>

        <form class="ss-stack-4" @submit.prevent="submit">
            <section class="ss-grid gap-3 ss-cols-sm-2">
                <fieldset class="ss-grid gap-1 ss-span-sm-2">
                    <legend class="fw-medium fs-5 mb-1">Type</legend>
                    <div class="d-flex flex-wrap gap-4">
                        <label v-for="(label, k) in kinds" :key="k" class="d-flex align-items-center gap-2">
                            <input v-model="form.kind" type="radio" :value="k" class="form-check-input m-0" /> {{ label }}
                        </label>
                    </div>
                    <p class="small text-secondary mb-0">
                        <template v-if="isDevice">A device belongs to one customer: their own PBX, a phone system, or a service like Twilio.</template>
                        <template v-else>
                            A PBX somewhere else (often multi-tenant) that isn't tied to one customer: DIDs of any customer can be sent to it.
                        </template>
                    </p>
                </fieldset>
                <div v-if="isDevice" class="ss-grid gap-1">
                    <Label for="account_id">Customer</Label>
                    <select id="account_id" v-model="form.account_id" :class="selectClass" required>
                        <option value="" disabled>Choose…</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                    </select>
                    <InputError :message="form.errors.account_id" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required maxlength="100" :placeholder="isDevice ? 'Office PBX' : 'Hosted PBX cluster 1'" />
                    <InputError :message="form.errors.name" />
                </div>
                <label class="d-flex align-items-center gap-2">
                    <input v-model="form.enabled" type="checkbox" class="form-check-input m-0" /> Enabled
                </label>
            </section>

            <!-- Device: Sending / Receiving -->
            <div v-if="isDevice" class="d-flex flex-wrap gap-4 border-bottom pb-2">
                <label class="d-flex align-items-center gap-2 fw-medium">
                    <input v-model="tab" type="radio" value="sending" class="form-check-input m-0" /> Sending calls
                    <span v-if="sendingErrors" class="text-danger small">(check)</span>
                </label>
                <label class="d-flex align-items-center gap-2 fw-medium">
                    <input v-model="tab" type="radio" value="receiving" class="form-check-input m-0" /> Receiving calls
                    <span v-if="form.rules.length" class="badge bg-secondary-lt">{{ form.rules.length }}</span>
                    <span v-if="receivingErrors" class="text-danger small">(check)</span>
                </label>
            </div>

            <!-- Sending calls (and everything a PBX has) -->
            <div v-show="!isDevice || tab === 'sending'" class="ss-stack-4">
                <section class="ss-stack-3">
                    <fieldset v-if="isDevice" class="ss-grid gap-1">
                        <legend class="fw-medium fs-5 mb-1">How do you want to send calls?</legend>
                        <label class="d-flex align-items-center gap-2"><input v-model="form.delivery" type="radio" value="sip" class="form-check-input m-0" /> IP/FQDN</label>
                        <label class="d-flex align-items-center gap-2"><input v-model="form.delivery" type="radio" value="flow" class="form-check-input m-0" /> Call Flow</label>
                    </fieldset>

                    <fieldset v-if="usesFlow" class="ss-grid gap-1 rounded border p-3">
                        <legend class="fw-medium fs-5 mb-1 float-none w-auto px-1">Call Flow (choose one)</legend>
                        <label v-for="(label, f) in flows" :key="f" class="d-flex align-items-start gap-2">
                            <input v-model="form.flow" type="radio" :value="f" class="form-check-input m-0 mt-1" />
                            <span>
                                {{ label }}
                                <span class="d-block small text-secondary">
                                    <template v-if="f === 'always'">Every call goes straight to the forward number.</template>
                                    <template v-else-if="f === 'no_answer'">Send the call to the device; if nobody answers in time, forward.</template>
                                    <template v-else-if="f === 'busy'">Send the call to the device; if it's busy, forward.</template>
                                    <template v-else-if="f === 'failed'">Send the call to the device; if it can't connect (offline, error), forward.</template>
                                    <template v-else>Send calls to an extension, IVR or voicemail on this switch's PBX.</template>
                                </span>
                            </span>
                        </label>
                        <InputError :message="form.errors.flow" />
                    </fieldset>

                    <template v-if="ringsIt">
                        <fieldset v-if="isDevice" class="ss-grid gap-1">
                            <legend class="fw-medium fs-5 mb-1">Reach it by</legend>
                            <label class="d-flex align-items-center gap-2"><input v-model="form.target" type="radio" value="hosts" class="form-check-input m-0" /> Its IP/FQDN</label>
                            <label class="d-flex align-items-start gap-2">
                                <input v-model="form.target" type="radio" value="trunk" class="form-check-input m-0 mt-1" />
                                <span>
                                    SIP trunk (username &amp; password)
                                    <span class="d-block small text-secondary">The PBX registers with this switch; calls are sent to where it registered, and calls it sends over the trunk go out through the customer's LCR.</span>
                                </span>
                            </label>
                        </fieldset>

                        <div v-if="!trunk" class="ss-grid gap-3 ss-cols-sm-3">
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
                        </div>

                        <div v-else class="ss-stack-2 rounded border p-3">
                            <p class="mb-0 fw-medium">Enter these in the PBX's SIP trunk</p>
                            <div class="ss-grid gap-3 ss-cols-sm-2">
                                <div class="ss-grid gap-1">
                                    <Label for="sip_username">Username</Label>
                                    <div class="d-flex gap-1">
                                        <Input id="sip_username" v-model="form.sip_username" class="font-monospace" autocomplete="off" />
                                        <Button type="button" variant="ghost" size="sm" aria-label="Copy username" @click="copy('user', form.sip_username)"><Copy class="icon" /></Button>
                                    </div>
                                    <InputError :message="form.errors.sip_username" />
                                </div>
                                <div class="ss-grid gap-1">
                                    <Label for="sip_password">Password</Label>
                                    <div class="d-flex gap-1">
                                        <Input id="sip_password" v-model="form.sip_password" :type="showPassword ? 'text' : 'password'" class="font-monospace" autocomplete="new-password" />
                                        <Button type="button" variant="ghost" size="sm" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword">
                                            <EyeOff v-if="showPassword" class="icon" /><Eye v-else class="icon" />
                                        </Button>
                                        <Button type="button" variant="ghost" size="sm" aria-label="Copy password" @click="copy('password', form.sip_password)"><Copy class="icon" /></Button>
                                        <Button type="button" variant="ghost" size="sm" aria-label="New password" title="New password" @click="newPassword"><RefreshCw class="icon" /></Button>
                                    </div>
                                    <InputError :message="form.errors.sip_password" />
                                </div>
                            </div>
                            <p class="small mb-0">
                                SIP server <span class="font-monospace">{{ connection.sip }}</span> (UDP or TCP; TLS on port 5061) · domain/realm
                                <span class="font-monospace">{{ connection.domain }}</span> · registration on.
                                <span v-if="copied" class="text-success ms-1">Copied.</span>
                            </p>
                            <p class="small text-secondary mb-0">Changing the password disconnects the PBX until it is updated there too.</p>
                        </div>

                        <div v-if="usesFlow && form.flow === 'no_answer'" class="ss-grid gap-1 ss-w-48">
                            <Label for="ring_seconds">Ring for (seconds)</Label>
                            <Input id="ring_seconds" v-model="form.ring_seconds" type="number" min="5" max="120" />
                            <InputError :message="form.errors.ring_seconds" />
                        </div>
                    </template>

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
                    <div v-if="isDevice" class="ss-grid gap-1 align-content-start">
                        <Label for="caller_id_number">Caller ID <span class="small text-secondary">(optional)</span></Label>
                        <Input id="caller_id_number" v-model="form.caller_id_number" inputmode="tel" class="font-monospace" placeholder="14165550000" />
                        <p class="small text-secondary mb-0">
                            Shown on calls it makes out{{ forwards ? ' (and on forwards, if chosen above)' : '' }}. Blank keeps the caller's own number. Carriers may override it.
                        </p>
                        <InputError :message="form.errors.caller_id_number" />
                    </div>
                    <div v-else class="small text-secondary align-self-start rounded border p-3">
                        Calls this PBX sends to the switch are matched to customers by their devices' <strong>Receiving calls</strong> rules (From domain or
                        caller number), then go out through that customer's LCR.
                        <template v-if="endpoint">
                            <br /><Link :href="didsIndex({ query: { endpoint: endpoint.id } })" class="fw-medium">{{ endpoint.dids }} DID(s)</Link> are sent here.
                        </template>
                    </div>
                </section>

                <section class="ss-stack-2">
                    <h2 class="fw-semibold mb-0">Concurrent call limits</h2>
                    <div class="ss-grid gap-3 ss-cols-sm-3">
                        <div class="ss-grid gap-1">
                            <Label for="max_inbound">Inbound</Label>
                            <Input id="max_inbound" v-model="form.max_inbound" type="number" min="1" placeholder="Unlimited" />
                            <InputError :message="form.errors.max_inbound" />
                        </div>
                        <template v-if="isDevice">
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
                        </template>
                    </div>
                    <p v-if="isDevice" class="small text-secondary mb-0">The customer's own account limits still apply on top.</p>
                </section>
            </div>

            <!-- Receiving calls: how the calls this device sends us are recognised -->
            <section v-if="isDevice" v-show="tab === 'receiving'" class="ss-stack-3">
                <p class="mb-0">
                    How to recognise calls this device sends to the switch, so they go out through
                    <strong>{{ customer?.name ?? "the customer" }}</strong>'s LCR. Any rule that matches is enough.
                </p>
                <p v-if="trunk" class="small text-secondary mb-0">Calls the PBX sends over its SIP trunk login are accepted without rules.</p>

                <div v-if="form.rules.length" class="ss-stack-2">
                    <div v-for="(r, i) in form.rules" :key="i" class="ss-grid gap-1">
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <select v-model="r.type" :class="selectClass" class="w-auto" :aria-label="`Rule ${i + 1} type`">
                                <option v-for="(label, t) in ruleTypes" :key="t" :value="t">{{ label }}</option>
                            </select>
                            <Input v-model="r.value" class="font-monospace ss-maxw-xs" :placeholder="ruleHelp[r.type].placeholder" :aria-label="`Rule ${i + 1} value`" />
                            <Button type="button" variant="ghost" size="sm" :aria-label="`Remove rule ${i + 1}`" @click="form.rules.splice(i, 1)"><Trash2 class="icon" /></Button>
                        </div>
                        <p class="small text-secondary mb-0">{{ ruleHelp[r.type].help }}</p>
                        <InputError :message="err(`rules.${i}.value`) ?? err(`rules.${i}.type`)" />
                    </div>
                </div>
                <p v-else class="text-secondary mb-0">No rules yet: this device only receives calls{{ trunk ? ' (apart from its SIP trunk)' : '' }}.</p>

                <div class="d-flex flex-wrap gap-2">
                    <Button v-for="(label, t) in ruleTypes" :key="t" type="button" variant="outline" size="sm" @click="addRule(t)"><Plus class="icon" /> {{ label }}</Button>
                </div>
                <p class="small text-secondary mb-0">
                    FROM domain and caller number rules only count for calls arriving from one of your PBXs or from this device's own IP/FQDN rules, so
                    nobody else can borrow them.
                </p>
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
