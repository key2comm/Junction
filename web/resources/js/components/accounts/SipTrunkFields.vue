<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';

export type SipTrunkValues = {
    label: string;
    host: string;
    port: number;
    transport: string;
    username: string | null;
    has_password?: boolean;
    from_user: string | null;
    from_domain: string | null;
    outbound_proxy: string | null;
    send_rpid: boolean;
    send_pai: boolean;
    register: boolean;
    enabled: boolean;
    priority: number;
    weight: number;
};

const props = defineProps<{
    gateway?: Partial<SipTrunkValues>;
    transports: string[];
    errors: Record<string, string | undefined>;
    /** Prefix for element ids, so two forms can be on one page. */
    idPrefix?: string;
}>();

const id = (name: string) => `${props.idPrefix ?? 'st'}_${name}`;
const g = props.gateway ?? {};
const advancedOpen = !!(g.username || g.has_password || g.from_user || g.from_domain || g.outbound_proxy || g.register || g.send_pai || g.send_rpid === false);
</script>

<template>
    <div class="ss-stack-3">
        <div class="ss-grid gap-3 ss-cols-sm-6">
            <div class="ss-grid gap-1 ss-span-sm-6">
                <Label :for="id('label')">Name</Label>
                <Input :id="id('label')" name="label" :default-value="g.label ?? ''" required maxlength="100" placeholder="e.g. Primary, Failover, Toronto" />
                <InputError :message="errors.label" />
            </div>
            <div class="ss-grid gap-1 ss-span-sm-3">
                <Label :for="id('host')">IP / FQDN</Label>
                <Input :id="id('host')" name="host" :default-value="g.host" required placeholder="sip.carrier.example or 203.0.113.10" class="font-monospace" />
                <InputError :message="errors.host" />
            </div>
            <div class="ss-grid gap-1">
                <Label :for="id('port')">Port</Label>
                <Input :id="id('port')" name="port" type="number" min="1" max="65535" :default-value="g.port ?? 5060" required />
                <InputError :message="errors.port" />
            </div>
            <div class="ss-grid gap-1 ss-span-sm-2">
                <Label :for="id('transport')">Transport</Label>
                <select :id="id('transport')" name="transport" :class="selectClass">
                    <option v-for="t in transports" :key="t" :value="t" :selected="t === (g.transport ?? 'udp')">{{ t.toUpperCase() }}</option>
                </select>
                <InputError :message="errors.transport" />
            </div>
            <div class="ss-grid gap-1 ss-span-sm-2">
                <Label :for="id('priority')">Priority</Label>
                <Input :id="id('priority')" name="priority" type="number" min="1" max="100" :default-value="g.priority ?? 1" required />
                <p class="small text-secondary">1 is tried first; a higher number is a failover.</p>
                <InputError :message="errors.priority" />
            </div>
            <div class="d-flex align-items-start pt-sm-4 ss-span-sm-4">
                <label class="d-flex align-items-center gap-2 mt-sm-2"><input type="checkbox" name="enabled" value="1" :checked="g.enabled ?? true" class="form-check-input m-0" /> Enabled</label>
            </div>
        </div>

        <details class="border rounded p-2" :open="advancedOpen">
            <summary class="fw-medium">Advanced options</summary>
            <div class="ss-grid gap-3 ss-cols-sm-2 mt-3">
                <div class="ss-grid gap-1">
                    <Label :for="id('from_user')">From user</Label>
                    <Input :id="id('from_user')" name="from_user" :default-value="g.from_user ?? ''" placeholder="The caller's number" class="font-monospace" />
                    <p class="small text-secondary">A fixed user in the From header (e.g. an account number). The caller ID then goes in RPID/PAI.</p>
                    <InputError :message="errors.from_user" />
                </div>
                <div class="ss-grid gap-1">
                    <Label :for="id('from_domain')">From domain</Label>
                    <Input :id="id('from_domain')" name="from_domain" :default-value="g.from_domain ?? ''" placeholder="The IP/FQDN above" class="font-monospace" />
                    <InputError :message="errors.from_domain" />
                </div>
                <div class="ss-grid gap-1">
                    <Label :for="id('username')">Auth user</Label>
                    <Input :id="id('username')" name="username" :default-value="g.username ?? ''" autocomplete="off" placeholder="None (IP authentication)" />
                    <InputError :message="errors.username" />
                </div>
                <div class="ss-grid gap-1">
                    <Label :for="id('password')">Auth password</Label>
                    <Input :id="id('password')" name="password" type="password" autocomplete="new-password" :placeholder="g.has_password ? '•••••• (unchanged)' : ''" />
                    <InputError :message="errors.password" />
                </div>
                <div class="ss-grid gap-1">
                    <Label :for="id('outbound_proxy')">Outbound proxy</Label>
                    <Input :id="id('outbound_proxy')" name="outbound_proxy" :default-value="g.outbound_proxy ?? ''" placeholder="None" class="font-monospace" />
                    <p class="small text-secondary">Host or IP, optionally :port. Calls are sent through it.</p>
                    <InputError :message="errors.outbound_proxy" />
                </div>
                <div class="ss-grid gap-1">
                    <Label :for="id('weight')">Weight</Label>
                    <Input :id="id('weight')" name="weight" type="number" min="1" max="1000" :default-value="g.weight ?? 100" required />
                    <p class="small text-secondary">Shares traffic between SIP trunks with the same priority.</p>
                    <InputError :message="errors.weight" />
                </div>
                <div class="ss-stack-2 ss-span-sm-2">
                    <!-- Tells the server unticked boxes mean "off" (the API keeps what's saved). -->
                    <input type="hidden" name="checkboxes" value="1" />
                    <label class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="send_rpid" value="1" :checked="g.send_rpid ?? true" class="form-check-input m-0" /> Send RPID (Remote-Party-ID)
                    </label>
                    <label class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="send_pai" value="1" :checked="g.send_pai ?? false" class="form-check-input m-0" /> Send PAI (P-Asserted-Identity)
                    </label>
                    <label class="d-flex align-items-center gap-2">
                        <input type="checkbox" name="register" value="1" :checked="g.register ?? false" class="form-check-input m-0" /> Register with the auth user
                    </label>
                </div>
            </div>
        </details>
    </div>
</template>
