<script setup lang="ts">
import { Form, Head, Link, router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FirewallController from '@/actions/App/Http/Controllers/Settings/FirewallController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateTime } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { index } from '@/routes/firewall';

type Entry = {
    id: number;
    list: 'allow' | 'block';
    value: string;
    reason: string | null;
    resolved: string[] | null;
    hostname: boolean;
    expires_at: string | null;
    resolved_at: string | null;
    created_at: string;
    user: string | null;
};
type Service = 'ssh' | 'web' | 'pbx';

const props = defineProps<{
    allow: Entry[];
    block: Entry[];
    carriers: { id: number; host: string; label: string | null; account_id: number; carrier: string | null }[];
    lockdown: Record<Service, boolean>;
    services: Record<Service, string>;
    pending: { until: string; what: string; helper: boolean } | null;
    error: string | null;
    you: { ip: string; trusted: boolean };
    status: {
        checked_at: string | null;
        ssh_clients: { ip: string; trusted: boolean }[];
        bans: { ip: string; jail: string; banned_at: string | null; until: string | null }[];
    } | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Firewall', href: index() }] } });

const serviceHelp: Record<Service, string> = {
    ssh: 'Only whitelisted addresses can open SSH connections. fail2ban still protects it either way.',
    web: 'Only whitelisted addresses can use the admin pages. The customer portal and API are not affected.',
    pbx: 'Only whitelisted addresses can reach the PBX SIP ports (phones). Carrier traffic is not affected.',
};
const jailName = (j: string) => ({ sshd: 'SSH', freeswitch: 'SIP' })[j] ?? j;

// Lockdown switches (saved with the form).
const lock = ref({ ...props.lockdown });
watch(
    () => props.lockdown,
    (l) => (lock.value = { ...l }),
);
const lockChanged = computed(() => (Object.keys(lock.value) as Service[]).some((s) => lock.value[s] !== props.lockdown[s]));

// Pending change: countdown, and refresh the page while waiting.
const now = ref(Date.now());
const timer = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(timer));
const secondsLeft = computed(() => (props.pending ? Math.max(0, Math.round((new Date(props.pending.until).getTime() - now.value) / 1000)) : 0));
const countdown = computed(() => `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`);
const { start, stop } = usePoll(5000, { only: ['pending', 'lockdown', 'error', 'status', 'allow'] }, { autoStart: false });
watch(
    () => props.pending,
    (p) => (p ? start() : stop()),
    { immediate: true },
);

const post = (url: string, data: Record<string, string> = {}, confirmText?: string) => {
    if (confirmText && !confirm(confirmText)) return;
    router.post(url, data, { preserveScroll: true });
};
const remove = (e: Entry) => {
    const text =
        e.list === 'allow'
            ? `Remove ${e.value} from the whitelist? It can be banned again, and loses access to locked services.`
            : `Remove ${e.value} from the blacklist? It can connect again.`;
    if (confirm(text)) router.delete(FirewallController.destroy.url(e.id), { preserveScroll: true });
};
const expiry = (e: Entry) => (e.expires_at ? `until ${dateTime(e.expires_at)}` : 'permanent');
</script>

<template>
    <Head title="Firewall" />

    <h1 class="visually-hidden">Firewall</h1>

    <div class="ss-stack-4">
        <Heading
            variant="small"
            title="Firewall"
            description="Who can reach this server. Whitelisted addresses can always connect and are never banned; blacklisted ones can't connect at all. Customer and carrier addresses are allowed on the carrier SIP port automatically, from their accounts and carrier SIP trunks (listed in the whitelist as Carrier: name, SIP ports only)."
        />

        <!-- A change waiting for confirmation -->
        <div v-if="pending" class="alert alert-warning mb-0">
            <div class="ss-stack-2">
                <h4 class="alert-heading mb-0">Confirm the firewall change: {{ pending.what }}</h4>
                <p class="mb-0">
                    Check you can still connect<span v-if="pending.helper">: open a <strong>new</strong> SSH session to this server (and register a phone,
                        for PBX)</span
                    >. If you don't keep the change, it is undone automatically in <strong class="font-monospace">{{ countdown }}</strong>.
                </p>
                <div class="d-flex gap-2">
                    <Button @click="post(FirewallController.confirm.url())">Keep this change</Button>
                    <Button variant="outline" @click="post(FirewallController.undo.url())">Undo now</Button>
                </div>
            </div>
        </div>
        <div v-if="error" class="alert alert-danger mb-0">{{ error }}</div>

        <p class="mb-0">
            You are connecting from <span class="font-monospace fw-medium">{{ you.ip }}</span>
            <Badge v-if="you.trusted" class="ms-1">whitelisted</Badge>
            <Badge v-else variant="secondary" class="ms-1">not whitelisted</Badge>
        </p>

        <!-- Lockdown -->
        <section class="ss-stack-3 rounded border p-3">
            <Heading variant="small" title="Lock services to the whitelist" description="Turning a lockdown on is applied for 3 minutes first; you confirm it once you've checked you can still connect." />
            <Form v-bind="FirewallController.lockdown.form()" :options="{ preserveScroll: true }" class="ss-stack-3" v-slot="{ errors, processing }">
                <label v-for="(label, s) in services" :key="s" class="d-flex align-items-start gap-2">
                    <input v-model="lock[s]" type="checkbox" class="form-check-input m-0 mt-1" :disabled="!!pending" />
                    <input type="hidden" :name="s" :value="lock[s] ? 1 : 0" />
                    <span>
                        <span class="fw-medium">{{ label }}</span>
                        <Badge v-if="lockdown[s]" class="ms-2">locked</Badge>
                        <span class="d-block small text-secondary">{{ serviceHelp[s] }}</span>
                    </span>
                </label>
                <div v-if="status?.ssh_clients.length" class="small">
                    Connected over SSH right now from:
                    <span v-for="c in status.ssh_clients" :key="c.ip" class="me-2 font-monospace">
                        {{ c.ip }} <span :class="c.trusted ? 'text-success' : 'text-danger'">{{ c.trusted ? '(whitelisted)' : '(not whitelisted)' }}</span>
                    </span>
                </div>
                <InputError :message="errors.lockdown" />
                <div>
                    <Button :disabled="processing || !lockChanged || !!pending">Save lockdown</Button>
                </div>
            </Form>
            <p class="small text-secondary mb-0">
                Locked out of the admin pages? Over SSH run
                <code>sudo -u softswitch php /var/www/html/artisan softswitch:firewall unlock</code>.
            </p>
        </section>

        <div class="ss-grid gap-4 ss-cols-lg-2">
            <!-- Whitelist / blacklist -->
            <section v-for="list in ['allow', 'block'] as const" :key="list" class="ss-stack-3">
                <Heading
                    variant="small"
                    :title="list === 'allow' ? 'Whitelist' : 'Blacklist'"
                    :description="
                        list === 'allow'
                            ? 'Your offices, staff and monitoring: always allowed, never banned by fail2ban, and the only addresses that reach locked services.'
                            : 'Addresses that may not connect to anything. Existing connections are cut off.'
                    "
                />
                <Form
                    v-bind="FirewallController.store.form()"
                    :options="{ preserveScroll: true }"
                    reset-on-success
                    class="ss-grid gap-2"
                    v-slot="{ errors, processing }"
                >
                    <input type="hidden" name="list" :value="list" />
                    <div class="ss-grid gap-1">
                        <Label :for="`${list}_value`">IP address, network or hostname</Label>
                        <Input :id="`${list}_value`" name="value" placeholder="203.0.113.10, 203.0.113.0/24 or office.example.com" class="font-monospace" required :disabled="!!pending" />
                        <InputError :message="errors.value" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label :for="`${list}_reason`">Reason <span v-if="list === 'allow'" class="small text-secondary">(optional)</span></Label>
                        <Input :id="`${list}_reason`" name="reason" :placeholder="list === 'allow' ? 'Head office' : 'SIP scanning'" :required="list === 'block'" :disabled="!!pending" />
                        <InputError :message="errors.reason" />
                    </div>
                    <div v-if="list === 'block'" class="ss-grid gap-1">
                        <Label for="block_duration">For</Label>
                        <select id="block_duration" name="duration" :class="selectClass" :disabled="!!pending">
                            <option value="">Permanently</option>
                            <option value="1h">1 hour</option>
                            <option value="1d">1 day</option>
                            <option value="7d">1 week</option>
                            <option value="30d">30 days</option>
                        </select>
                    </div>
                    <div>
                        <Button :disabled="processing || !!pending" :variant="list === 'block' ? 'destructive' : 'default'">
                            {{ list === 'allow' ? 'Add to whitelist' : 'Add to blacklist' }}
                        </Button>
                    </div>
                </Form>

                <div class="card table-responsive">
                    <table class="table table-vcenter card-table table-tight">
                        <thead>
                            <tr>
                                <th>Address</th>
                                <th>Reason</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in list === 'allow' ? allow : block" :key="e.id">
                                <td>
                                    <span class="font-monospace">{{ e.value }}</span>
                                    <div v-if="e.hostname" class="small text-secondary font-monospace">→ {{ (e.resolved ?? []).join(', ') || 'not resolved' }}</div>
                                </td>
                                <td>
                                    {{ e.reason }}
                                    <div class="small text-secondary">
                                        <template v-if="list === 'block'">{{ expiry(e) }} · </template>{{ e.user ?? 'system' }}, {{ dateTime(e.created_at) }}
                                    </div>
                                </td>
                                <td class="text-end">
                                    <Button size="sm" variant="ghost" :disabled="!!pending" @click="remove(e)">Remove</Button>
                                </td>
                            </tr>
                            <template v-if="list === 'allow'">
                                <tr v-for="c in carriers" :key="`c${c.id}`">
                                    <td><span class="font-monospace">{{ c.host }}</span></td>
                                    <td>
                                        Carrier: <Link :href="showAccount(c.account_id)">{{ c.carrier }}</Link>
                                        <div class="small text-secondary">{{ c.label }} · SIP ports only, added automatically</div>
                                    </td>
                                    <td class="text-end small text-secondary">on the carrier</td>
                                </tr>
                            </template>
                            <tr v-if="(list === 'allow' ? allow.length + carriers.length : block.length) === 0">
                                <td colspan="3" class="text-center text-secondary">Nothing here yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- fail2ban -->
        <section class="ss-stack-3">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <Heading
                    variant="small"
                    title="Automatic bans (fail2ban)"
                    description="Addresses banned for repeated failed SSH or SIP logins. Bans expire on their own and get longer for repeat offenders."
                />
                <Button variant="outline" size="sm" @click="post(FirewallController.refresh.url())">Refresh</Button>
            </div>
            <p v-if="!status" class="text-secondary">Not read yet; the list updates every minute.</p>
            <div v-else class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Address</th>
                            <th>For</th>
                            <th>Banned</th>
                            <th>Until</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in status.bans" :key="`${b.jail}-${b.ip}`">
                            <td class="font-monospace">{{ b.ip }}</td>
                            <td>{{ jailName(b.jail) }} login failures</td>
                            <td class="text-secondary text-nowrap">{{ dateTime(b.banned_at) }}</td>
                            <td class="text-secondary text-nowrap">{{ dateTime(b.until) }}</td>
                            <td class="text-end text-nowrap">
                                <Button size="sm" variant="ghost" @click="post(FirewallController.unban.url(), { ip: b.ip }, `Unban ${b.ip}?`)">Unban</Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="text-danger"
                                    @click="post(FirewallController.blacklistBan.url(), { ip: b.ip, jail: b.jail }, `Blacklist ${b.ip} permanently?`)"
                                    >Blacklist</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="status.bans.length === 0">
                            <td colspan="5" class="text-center text-secondary">No addresses are banned right now.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="status?.checked_at" class="small text-secondary mb-0">Updated {{ dateTime(status.checked_at) }}.</p>
        </section>
    </div>
</template>
