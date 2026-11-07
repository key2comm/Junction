<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Eye, EyeOff, Headphones, Pencil, Trash2 } from '@lucide/vue';
import PbxController from '@/actions/App/Http/Controllers/Admin/PbxController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DestinationPicker from '@/components/pbx/DestinationPicker.vue';
import type { Destination, PbxNumber } from '@/components/pbx/DestinationPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as showAccount } from '@/routes/accounts';
import { phone, show } from '@/routes/accounts/pbx';
import { edit as editExtension } from '@/routes/accounts/pbx/extensions';
import { edit as editIvr } from '@/routes/accounts/pbx/ivrs';
import { index } from '@/routes/pbx';

const props = defineProps<{
    account: { id: number; name: string; code: string; status: string };
    extensions: {
        id: number;
        extension: string;
        name: string | null;
        sip_username: string;
        sip_password: string;
        voicemail_pin: string | null;
        caller_id_number: string | null;
        outbound_enabled: boolean;
        voicemail_enabled: boolean;
        ring_seconds: number;
        enabled: boolean;
    }[];
    ivrs: { id: number; extension: string; name: string; timeout_seconds: number; has_greeting: boolean; options: { key: string; label: string }[]; default: string }[];
    dids: { id: number; number: string; description: string | null; enabled: boolean; destination: Destination; label: string }[];
    numbers: PbxNumber[];
    connection: { host: string; sip: string; wss: string; domain: string };
}>();

defineOptions({
    layout: (props: { account: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'PBX', href: index() },
            { title: props.account.name, href: show(props.account.id) },
        ],
    }),
});

const revealed = ref<Record<number, boolean>>({});
const editingDid = ref<number | null>(null);
</script>

<template>
    <Head :title="`PBX · ${props.account.name}`" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="ss-stack-1">
                <Heading :title="account.name" :description="`PBX of ${account.code}. Outside calls are routed and billed like this account's carrier traffic.`" />
                <Badge v-if="account.status !== 'active'" variant="destructive">Account {{ account.status }}: phones can't register or call</Badge>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <Button v-if="extensions.length" variant="outline" as-child>
                    <Link :href="phone(account.id)"><Headphones class="icon" /> Web phone</Link>
                </Button>
                <Button variant="outline" as-child><Link :href="showAccount(account.id)">Account</Link></Button>
            </div>
        </div>

        <div class="card card-body ss-grid gap-2 ss-cols-sm-3">
            <div>
                <div class="text-secondary">SIP server (UDP/TCP)</div>
                <div class="font-monospace">{{ connection.sip }}</div>
            </div>
            <div>
                <div class="text-secondary">WebRTC (SIP over WebSocket)</div>
                <div class="font-monospace">{{ connection.wss }}</div>
            </div>
            <div>
                <div class="text-secondary">Dialling from a phone</div>
                <div>2-6 digits: extension or IVR · 7+ digits: outside number (international format) · <span class="font-monospace">*97</span>: voicemail</div>
            </div>
            <p class="small text-secondary ss-span-sm-3">Emergency calling is not supported: tell users to keep another phone for emergency numbers.</p>
        </div>

        <!-- Extensions -->
        <section class="ss-stack-3">
            <Heading variant="small" title="Extensions" description="One per phone. The phone signs in with the SIP username and password." />
            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Ext</th>
                            <th>Name</th>
                            <th>SIP login</th>
                            <th>Outside calls</th>
                            <th>Voicemail</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in extensions" :key="e.id" :class="{ 'opacity-50': !e.enabled }">
                            <td class="font-monospace fw-medium">{{ e.extension }}</td>
                            <td>{{ e.name }}</td>
                            <td class="font-monospace small">
                                <div>{{ e.sip_username }}</div>
                                <div class="d-flex align-items-center gap-1 text-secondary">
                                    <span>{{ revealed[e.id] ? e.sip_password : '••••••••••••' }}</span>
                                    <button type="button" :aria-label="revealed[e.id] ? 'Hide password' : 'Show password'" @click="revealed[e.id] = !revealed[e.id]">
                                        <EyeOff v-if="revealed[e.id]" class="icon" /><Eye v-else class="icon" />
                                    </button>
                                </div>
                            </td>
                            <td>
                                <span v-if="e.outbound_enabled" class="font-monospace">CLI {{ e.caller_id_number }}</span>
                                <span v-else class="text-secondary">Not allowed</span>
                            </td>
                            <td>
                                <span v-if="e.voicemail_enabled">After {{ e.ring_seconds }}s · PIN <span class="font-monospace">{{ revealed[e.id] ? e.voicemail_pin : '••••' }}</span></span>
                                <span v-else class="text-secondary">Off</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <Button size="sm" variant="ghost" as-child>
                                    <Link :href="phone(account.id, { query: { extension: e.id } })" :aria-label="`Web phone as ${e.extension}`"><Headphones class="icon" /></Link>
                                </Button>
                                <Button size="sm" variant="ghost" as-child>
                                    <Link :href="editExtension([account.id, e.id])" :aria-label="`Edit ${e.extension}`"><Pencil class="icon" /></Link>
                                </Button>
                                <Form v-bind="PbxController.destroyExtension.form([account.id, e.id])" class="d-inline" v-slot="{ processing }">
                                    <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${e.extension}`"><Trash2 class="icon" /></Button>
                                </Form>
                            </td>
                        </tr>
                        <tr v-if="extensions.length === 0">
                            <td colspan="6" class="py-3 text-center text-secondary">No extensions yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <details class="card card-body" :open="extensions.length === 0">
                <summary class="fw-medium">Add extension</summary>
                <Form v-bind="PbxController.storeExtension.form(account.id)" reset-on-success class="mt-3 ss-stack-3" v-slot="{ errors, processing }">
                    <div class="ss-grid gap-3 ss-cols-sm-6">
                        <div class="ss-grid gap-1">
                            <Label for="ext_number">Extension</Label>
                            <Input id="ext_number" name="extension" required inputmode="numeric" placeholder="1001" class="font-monospace" />
                            <InputError :message="errors.extension" />
                        </div>
                        <div class="ss-grid gap-1 ss-span-sm-2">
                            <Label for="ext_name">Name</Label>
                            <Input id="ext_name" name="name" placeholder="Reception" />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="ss-grid gap-1 ss-span-sm-3">
                            <Label for="ext_cli">Caller ID for outside calls</Label>
                            <Input id="ext_cli" name="caller_id_number" inputmode="numeric" placeholder="15145550100" class="font-monospace" />
                            <InputError :message="errors.caller_id_number" />
                        </div>
                        <div class="ss-grid gap-1 ss-span-sm-2">
                            <Label for="ext_user">SIP username</Label>
                            <Input id="ext_user" name="sip_username" autocomplete="off" placeholder="auto" class="font-monospace text-lowercase" />
                            <InputError :message="errors.sip_username" />
                        </div>
                        <div class="ss-grid gap-1 ss-span-sm-2">
                            <Label for="ext_pass">SIP password</Label>
                            <Input id="ext_pass" name="sip_password" type="password" autocomplete="new-password" placeholder="generated" />
                            <InputError :message="errors.sip_password" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="ext_pin">Voicemail PIN</Label>
                            <Input id="ext_pin" name="voicemail_pin" inputmode="numeric" autocomplete="off" placeholder="generated" class="font-monospace" />
                            <InputError :message="errors.voicemail_pin" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="ext_ring">Ring (s)</Label>
                            <Input id="ext_ring" name="ring_seconds" type="number" min="5" max="120" :default-value="25" required />
                            <InputError :message="errors.ring_seconds" />
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="outbound_enabled" value="1" checked class="form-check-input m-0" /> Outside calls</label>
                        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="voicemail_enabled" value="1" checked class="form-check-input m-0" /> Voicemail</label>
                        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="enabled" value="1" checked class="form-check-input m-0" /> Enabled</label>
                    </div>
                    <Button :disabled="processing">Add extension</Button>
                </Form>
            </details>
        </section>

        <!-- IVRs -->
        <section class="ss-stack-3">
            <Heading variant="small" title="IVRs" description="Auto-attendants: play a greeting, then send the caller where the key they press says." />
            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Name</th>
                            <th>Options</th>
                            <th>No key pressed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in ivrs" :key="i.id">
                            <td class="font-monospace fw-medium">{{ i.extension }}</td>
                            <td>
                                {{ i.name }}
                                <Badge v-if="!i.has_greeting" variant="outline" class="ms-1">no greeting</Badge>
                            </td>
                            <td>
                                <div v-for="o in i.options" :key="o.key"><span class="font-monospace">{{ o.key }}</span> → {{ o.label }}</div>
                                <span v-if="i.options.length === 0" class="text-secondary">None</span>
                            </td>
                            <td>{{ i.default }}</td>
                            <td class="text-end text-nowrap">
                                <Button size="sm" variant="ghost" as-child>
                                    <Link :href="editIvr([account.id, i.id])" :aria-label="`Edit ${i.name}`"><Pencil class="icon" /></Link>
                                </Button>
                                <Form v-bind="PbxController.destroyIvr.form([account.id, i.id])" class="d-inline" v-slot="{ processing }">
                                    <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${i.name}`"><Trash2 class="icon" /></Button>
                                </Form>
                            </td>
                        </tr>
                        <tr v-if="ivrs.length === 0">
                            <td colspan="5" class="py-3 text-center text-secondary">No IVRs.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Form v-bind="PbxController.storeIvr.form(account.id)" class="d-flex flex-wrap align-items-start gap-2" v-slot="{ errors, processing }">
                <div class="ss-grid gap-1">
                    <Input name="extension" required inputmode="numeric" placeholder="Number, e.g. 5000" aria-label="IVR number" class="ss-w-44 font-monospace" />
                    <InputError :message="errors.extension" />
                </div>
                <div class="ss-grid gap-1">
                    <Input name="name" required placeholder="Name, e.g. Main menu" aria-label="IVR name" class="ss-w-56" />
                    <InputError :message="errors.name" />
                </div>
                <Button variant="outline" :disabled="processing">Add IVR</Button>
            </Form>
        </section>

        <!-- DIDs -->
        <section class="ss-stack-3">
            <Heading variant="small" title="DIDs" description="Numbers that carriers deliver to this switch. Inbound calls to them are free and go straight to the PBX." />
            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Goes to</th>
                            <th>Description</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="d in dids" :key="d.id">
                            <tr :class="{ 'opacity-50': !d.enabled }">
                                <td class="font-monospace fw-medium">{{ d.number }}</td>
                                <td>{{ d.label }}</td>
                                <td>{{ d.description }}</td>
                                <td class="text-end text-nowrap">
                                    <Button size="sm" variant="ghost" :aria-label="`Edit ${d.number}`" @click="editingDid = editingDid === d.id ? null : d.id"><Pencil class="icon" /></Button>
                                    <Form v-bind="PbxController.updateDid.form([account.id, d.id])" class="d-inline" v-slot="{ processing }">
                                        <input type="hidden" name="enabled" :value="d.enabled ? 0 : 1" />
                                        <Button size="sm" variant="ghost" :disabled="processing">{{ d.enabled ? 'Disable' : 'Enable' }}</Button>
                                    </Form>
                                    <Form v-bind="PbxController.destroyDid.form([account.id, d.id])" class="d-inline" v-slot="{ processing }">
                                        <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${d.number}`"><Trash2 class="icon" /></Button>
                                    </Form>
                                </td>
                            </tr>
                            <tr v-if="editingDid === d.id" class="bg-body-tertiary">
                                <td colspan="4" class="px-2 py-2">
                                    <Form v-bind="PbxController.updateDid.form([account.id, d.id])" class="d-flex flex-wrap align-items-start gap-2" @success="editingDid = null" v-slot="{ errors, processing }">
                                        <div class="ss-grid gap-1">
                                            <DestinationPicker name="destination" :numbers="numbers" :value="d.destination" />
                                            <InputError :message="errors['destination.type']" />
                                        </div>
                                        <Input name="description" :default-value="d.description ?? ''" placeholder="Description" aria-label="Description" class="ss-w-56" />
                                        <Button size="sm" :disabled="processing">Save</Button>
                                    </Form>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="dids.length === 0">
                            <td colspan="4" class="py-3 text-center text-secondary">No DIDs.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Form v-if="numbers.length" v-bind="PbxController.storeDid.form(account.id)" reset-on-success class="d-flex flex-wrap align-items-start gap-2" v-slot="{ errors, processing }">
                <div class="ss-grid gap-1">
                    <Input name="number" required inputmode="numeric" placeholder="DID, e.g. 15145550100" aria-label="DID number" class="ss-w-52 font-monospace" />
                    <InputError :message="errors.number" />
                </div>
                <div class="ss-grid gap-1">
                    <DestinationPicker name="destination" :numbers="numbers" />
                    <InputError :message="errors['destination.type']" />
                </div>
                <Input name="description" placeholder="Description" aria-label="Description" class="ss-w-48" />
                <Button variant="outline" :disabled="processing">Add DID</Button>
            </Form>
            <p v-else class="text-secondary">Add an extension or IVR first: a DID needs somewhere to go.</p>
            <p class="small text-secondary">Carriers must send these calls from an allowed IP (a customer IP or vendor gateway) to the carrier port.</p>
        </section>
    </div>
</template>
