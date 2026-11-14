<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Pencil, Trash2 } from '@lucide/vue';
import InvoiceController from '@/actions/App/Http/Controllers/Admin/InvoiceController';
import PortalUserController from '@/actions/App/Http/Controllers/Admin/PortalUserController';
import LedgerEntryController from '@/actions/App/Http/Controllers/Admin/LedgerEntryController';
import BillingPlan from '@/components/accounts/BillingPlan.vue';
import type { Plan } from '@/components/accounts/BillingPlan.vue';
import RouteTester from '@/components/accounts/RouteTester.vue';
import VendorPayables from '@/components/accounts/VendorPayables.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useBilling } from '@/composables/useBilling';
import { billingMonth, dateTime, money, titleCase } from '@/lib/format';
import { edit, index, show } from '@/routes/accounts';
import { show as showPbx } from '@/routes/accounts/pbx';
import { index as carriers } from '@/routes/carriers';
import { index as cdrIndex } from '@/routes/cdrs';
import { show as showInvoice } from '@/routes/invoices';
import { edit as editSipTrunk } from '@/routes/accounts/sip-trunks';
import { show as showDeck } from '@/routes/rate-decks';
import GatewayController from '@/actions/App/Http/Controllers/Admin/GatewayController';
import SipTrunkFields from '@/components/accounts/SipTrunkFields.vue';
import type { SipTrunkValues } from '@/components/accounts/SipTrunkFields.vue';

type Account = {
    id: number;
    name: string;
    code: string;
    is_customer: boolean;
    is_vendor: boolean;
    status: string;
    billing_type: string;
    currency: string;
    credit_limit: string;
    balance: string;
    vendor_balance: string;
    available: string;
    available_now: string;
    low_balance_threshold: string | null;
    low_balance_alerted_at: string | null;
    timezone: string;
    billing_email: string | null;
    noc_email: string | null;
    max_channels: number | null;
    max_cps: string | null;
    notes: string | null;
    dialing_scope: 'nanp' | 'international' | 'countries';
    dialing_country_names: string[];
    sell_rate_deck: { id: number; name: string } | null;
    routing_plan: { id: number; name: string } | null;
};

const props = defineProps<{
    account: Account;
    carrier: {
        trunk_id: number | null;
        rate_deck: { id: number; name: string; currency: string } | null;
        max_inbound: number | null;
        max_outbound: number | null;
        outbound_cps: string | null;
        codecs: string[];
        tech_prefix: string;
        strip_digits: number;
        routes_count: number;
        sip_trunks: (SipTrunkValues & { id: number; name: string })[];
        transports: string[];
    } | null;
    plan: Plan | null;
    ledger: { id: number; type: string; amount: string; balance_after: string; description: string; created_at: string; effective_at: string }[];
    timezones: string[];
    portalUsers: { id: number; name: string; email: string; created_at: string }[];
    vendorLedger: { id: number; type: string; amount: string; balance_after: string; description: string; effective_at: string }[];
    vendorInvoices: { id: number; reference: string; period_start: string; period_end: string; timezone: string; amount: string; our_amount: string; status: string; difference: string }[];
    invoices: { id: number; number: string; status: string; period_start: string; timezone: string; currency: string; usage_total: string; amount_due: string }[];
}>();

defineOptions({
    layout: (props: { account: { id: number; code: string; is_vendor: boolean } }) => ({
        breadcrumbs: [
            props.account.is_vendor ? { title: 'Carriers', href: carriers() } : { title: 'Accounts', href: index() },
            { title: props.account.code, href: show(props.account.id) },
        ],
    }),
});

const page = usePage();
// Settings → Billing: with billing off the switch only routes, so no money figures.
const billing = useBilling();
// One-time password for a new portal login (only when email isn't set up).
const portalPassword = computed(() => (page.flash as Record<string, unknown> | undefined)?.portalPassword as { email: string; password: string } | undefined);

const select = 'form-select w-auto';
</script>

<template>
    <Head :title="props.account.code" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="ss-stack-2">
                <Heading :title="account.name" :description="account.code" />
                <div class="d-flex flex-wrap gap-1">
                    <Badge :variant="account.status === 'active' ? 'default' : 'destructive'">{{ titleCase(account.status) }}</Badge>
                    <Badge v-if="account.is_customer" variant="outline">Customer</Badge>
                    <Badge v-if="account.is_vendor" variant="outline">Carrier</Badge>
                    <Badge v-if="billing && account.is_customer" variant="secondary">{{ titleCase(account.billing_type) }}</Badge>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <Button v-if="account.is_customer" variant="outline" as-child>
                    <Link :href="cdrIndex({ query: { customer: account.id } })">Calls sent</Link>
                </Button>
                <Button v-if="account.is_customer" variant="outline" as-child>
                    <Link :href="showPbx(account.id)">PBX</Link>
                </Button>
                <Button v-if="account.is_vendor" variant="outline" as-child>
                    <Link :href="cdrIndex({ query: { vendor: account.id } })">Calls carried</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="edit(account.id)"><Pencil class="icon" /> Edit</Link>
                </Button>
            </div>
        </div>

        <!-- Money (customer balance; carriers have payables further down) -->
        <div v-if="billing && account.is_customer" class="ss-grid gap-3 ss-cols-sm-3">
            <div class="card card-body">
                <p class="small text-secondary">Balance</p>
                <p class="fs-1 fw-semibold tabular-nums" :class="{ 'text-danger': Number(account.balance) < 0 }">
                    {{ money(account.balance, account.currency) }}
                </p>
            </div>
            <div class="card card-body">
                <p class="small text-secondary">Credit limit</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ money(account.credit_limit, account.currency) }}</p>
            </div>
            <div class="card card-body" :class="{ 'border-danger': account.low_balance_alerted_at }">
                <p class="small text-secondary">Available for calls now</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ money(account.available_now, account.currency) }}</p>
                <p class="small text-secondary">
                    <span v-if="account.low_balance_alerted_at" class="text-danger">Below the alert level of {{ money(account.low_balance_threshold, account.currency) }}</span>
                    <span v-else-if="account.low_balance_threshold">Alert below {{ money(account.low_balance_threshold, account.currency) }}</span>
                    <span v-else>Includes calls in progress and usage not yet posted</span>
                </p>
            </div>
        </div>

        <div class="ss-grid gap-4 ss-cols-lg-2">
            <!-- Customer routing -->
            <section v-if="account.is_customer" class="ss-stack-3">
                <Heading variant="small" title="Routing" description="How this customer's calls are rated and routed. Devices (and their IP authentication) are under PBX." />
                <dl class="ss-grid ss-cols-2 gap-2">
                    <dt class="text-secondary">Tariffs</dt>
                    <dd>{{ account.sell_rate_deck?.name ?? '— not set —' }}</dd>
                    <dt class="text-secondary">LCR group</dt>
                    <dd>{{ account.routing_plan?.name ?? '— not set —' }}</dd>
                    <dt class="text-secondary">Limits</dt>
                    <dd>{{ account.max_channels ?? '∞' }} channels, {{ account.max_cps ?? '∞' }} CPS</dd>
                    <dt class="text-secondary">May call</dt>
                    <dd>
                        <template v-if="account.dialing_scope === 'international'">Canada, USA and all international</template>
                        <template v-else-if="account.dialing_scope === 'countries'">
                            Canada, USA and {{ account.dialing_country_names.length }} more
                            <span class="d-block small text-secondary">{{ account.dialing_country_names.join(', ') }}</span>
                        </template>
                        <template v-else>Canada and USA only</template>
                    </dd>
                </dl>
            </section>

            <RouteTester v-if="account.is_customer" :account-id="account.id" :currency="account.currency" />

            <BillingPlan v-if="plan" :account-id="account.id" :currency="account.currency" :timezone="account.timezone" :plan="plan" />

            <!-- Money movements -->
            <section v-if="billing && account.is_customer" class="ss-stack-3">
                <Heading variant="small" title="Payments and adjustments" description="Every change to the balance is recorded here and can't be edited." />

                <Form
                    v-bind="LedgerEntryController.store.form(account.id)"
                    reset-on-success
                    class="ss-grid gap-2 ss-gtc-sm-auto-1fr-2fr-auto align-items-sm-start"
                    v-slot="{ errors, processing }"
                >
                    <select name="type" :class="select" aria-label="Type">
                        <option value="payment">Payment</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                    <div class="ss-grid gap-1">
                        <Input name="amount" type="number" step="0.000001" placeholder="Amount" required aria-label="Amount" />
                        <InputError :message="errors.amount" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Input name="description" placeholder="Reference / reason" required aria-label="Description" />
                        <InputError :message="errors.description" />
                    </div>
                    <Button :disabled="processing">Record</Button>
                </Form>
                <p class="small text-secondary">Adjustments can be negative to take money off the account.</p>

                <div class="card table-responsive">
                    <table class="table table-vcenter card-table table-tight">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in ledger" :key="e.id">
                                <td class="text-nowrap text-secondary">{{ dateTime(e.created_at) }}</td>
                                <td>
                                    <Badge variant="outline" class="me-1">{{ titleCase(e.type) }}</Badge>{{ e.description }}
                                </td>
                                <td class="text-end tabular-nums" :class="Number(e.amount) < 0 ? 'text-danger' : 'text-success'">
                                    {{ money(e.amount, account.currency) }}
                                </td>
                                <td class="text-end tabular-nums">{{ money(e.balance_after, account.currency) }}</td>
                            </tr>
                            <tr v-if="ledger.length === 0">
                                <td colspan="4" class="text-center text-secondary">No entries yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Customer portal logins -->
            <section v-if="account.is_customer" class="ss-stack-3">
                <Heading
                    variant="small"
                    title="Portal logins"
                    :description="`People at this customer who can sign in to see their ${billing ? 'balance, calls, invoices' : 'calls'} and rates (never your carriers or costs).`"
                />
                <div v-if="portalPassword" class="rounded border border-primary p-2">
                    <p class="fw-medium">Login created for {{ portalPassword.email }}</p>
                    <p class="text-secondary">Email isn't set up, so pass on this password securely; it won't be shown again. They can change it under Settings.</p>
                    <code class="mt-1 d-inline-block rounded bg-body-tertiary px-2 py-1 font-monospace">{{ portalPassword.password }}</code>
                </div>
                <ul class="list-group">
                    <li v-for="u in portalUsers" :key="u.id" class="list-group-item d-flex align-items-center justify-content-between gap-2 px-2 py-2">
                        <span>{{ u.name }} <span class="text-secondary">{{ u.email }}</span></span>
                        <Form v-bind="PortalUserController.destroy.form([account.id, u.id])" v-slot="{ processing }">
                            <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${u.email}`"><Trash2 class="icon" /></Button>
                        </Form>
                    </li>
                    <li v-if="portalUsers.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">No portal logins.</li>
                </ul>
                <Form v-bind="PortalUserController.store.form(account.id)" reset-on-success class="ss-grid gap-2 ss-gtc-sm-1fr-1fr-auto align-items-sm-start" v-slot="{ errors, processing }">
                    <div class="ss-grid gap-1">
                        <Input name="name" placeholder="Name" required aria-label="Name" />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Input name="email" type="email" placeholder="Email" required aria-label="Email" />
                        <InputError :message="errors.email" />
                    </div>
                    <Button variant="outline" :disabled="processing">Add login</Button>
                </Form>
            </section>

            <!-- Invoices -->
            <section v-if="billing && account.is_customer" class="ss-stack-3">
                <Heading variant="small" title="Invoices" description="Issued automatically after each month ends. Issue one by hand for any finished month (e.g. after voiding)." />
                <ul class="list-group">
                    <li v-for="i in invoices" :key="i.id" class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2 px-2 py-2" :class="{ 'opacity-50': i.status === 'void' }">
                        <span>
                            <Link :href="showInvoice(i.id)" class="font-monospace">{{ i.number }}</Link>
                            <span class="ms-2 text-secondary">{{ billingMonth(i.period_start, i.timezone) }}</span>
                            <Badge v-if="i.status === 'void'" variant="secondary" class="ms-2">void</Badge>
                        </span>
                        <span class="tabular-nums">{{ Number(i.amount_due) ? `${money(i.amount_due, i.currency)} due` : money(i.usage_total, i.currency) }}</span>
                    </li>
                    <li v-if="invoices.length === 0" class="list-group-item px-2 py-4 text-center text-secondary">No invoices yet.</li>
                </ul>
                <Form v-bind="InvoiceController.store.form(account.id)" class="d-flex flex-wrap align-items-start gap-2" v-slot="{ errors, processing }">
                    <div class="ss-grid gap-1">
                        <Input name="month" type="month" required aria-label="Billing month" class="ss-w-44" />
                        <InputError :message="errors.month" />
                    </div>
                    <Button variant="outline" :disabled="processing">Issue invoice</Button>
                </Form>
            </section>

            <!-- Carrier: settings and SIP trunks -->
            <section v-if="carrier" class="ss-stack-3 ss-span-lg-2">
                <Heading variant="small" title="Carrier settings" description="Change them with Edit." />
                <dl class="ss-grid ss-cols-sm-4 gap-2">
                    <div>
                        <dt class="text-secondary small">Concurrent calls in / out</dt>
                        <dd class="tabular-nums">{{ carrier.max_inbound ?? '∞' }} / {{ carrier.max_outbound ?? '∞' }}</dd>
                    </div>
                    <div>
                        <dt class="text-secondary small">Codecs</dt>
                        <dd>{{ carrier.codecs.join(', ') || '— not set —' }}</dd>
                    </div>
                    <div>
                        <dt class="text-secondary small">Carrier rates</dt>
                        <dd>
                            <Link v-if="carrier.rate_deck" :href="showDeck(carrier.rate_deck.id)">{{ carrier.rate_deck.name }}</Link>
                            <span v-else class="text-danger">not set (no calls are routed to it)</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-secondary small">Number sent</dt>
                        <dd>
                            <span v-if="!carrier.strip_digits && !carrier.tech_prefix">as dialled</span>
                            <span v-else>
                                <template v-if="carrier.strip_digits">strip {{ carrier.strip_digits }}</template>
                                <template v-if="carrier.tech_prefix"> + <span class="font-monospace">{{ carrier.tech_prefix }}</span> in front</template>
                            </span>
                        </dd>
                    </div>
                </dl>
                <p v-if="carrier.routes_count === 0" class="small text-secondary mb-0">Not in any LCR group yet: add it to one under Routing → LCR groups.</p>

                <Heading
                    variant="small"
                    title="SIP trunks"
                    description="Where calls to this carrier are sent. Priority 1 is tried first; the others are failovers. Each IP/FQDN is whitelisted on the firewall (every port) as soon as it's saved."
                />
                <div class="card table-responsive">
                    <table class="table table-vcenter card-table table-tight">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>IP / FQDN</th>
                                <th>Options</th>
                                <th class="text-end">Priority</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="g in carrier.sip_trunks" :key="g.id" :class="{ 'opacity-50': !g.enabled }">
                                <td>{{ g.label }} <Badge v-if="!g.enabled" variant="secondary" class="ms-1">disabled</Badge></td>
                                <td class="font-monospace">{{ g.host }}:{{ g.port }} <span class="text-secondary">{{ g.transport.toUpperCase() }}</span></td>
                                <td class="small text-secondary">
                                    {{ [g.send_rpid ? 'RPID' : null, g.send_pai ? 'PAI' : null, g.username ? `auth ${g.username}${g.register ? ' (registers)' : ''}` : 'IP auth',
                                        g.from_user ? `From user ${g.from_user}` : null, g.from_domain ? `From domain ${g.from_domain}` : null,
                                        g.outbound_proxy ? `proxy ${g.outbound_proxy}` : null].filter(Boolean).join(' · ') }}
                                </td>
                                <td class="text-end tabular-nums">{{ g.priority }}<span v-if="g.weight !== 100" class="text-secondary"> / {{ g.weight }}</span></td>
                                <td class="text-end text-nowrap">
                                    <Button size="sm" variant="ghost" as-child><Link :href="editSipTrunk([account.id, g.id])" :aria-label="`Edit ${g.label}`"><Pencil class="icon" /></Link></Button>
                                    <Form v-bind="GatewayController.destroy.form([account.id, g.id])" class="d-inline" v-slot="{ processing }">
                                        <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${g.label}`"><Trash2 class="icon" /></Button>
                                    </Form>
                                </td>
                            </tr>
                            <tr v-if="carrier.sip_trunks.length === 0">
                                <td colspan="5" class="text-center text-secondary">No SIP trunks yet. Calls can't be sent to this carrier until one is added.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <details class="card card-body" :open="carrier.sip_trunks.length === 0">
                    <summary class="fw-medium">Add SIP trunk</summary>
                    <Form v-bind="GatewayController.store.form(account.id)" reset-on-success class="mt-3 ss-stack-3" v-slot="{ errors, processing }">
                        <SipTrunkFields :transports="carrier.transports" :errors="errors" id-prefix="new" />
                        <div><Button :disabled="processing">Add SIP trunk</Button></div>
                    </Form>
                </details>
            </section>

            <VendorPayables
                v-if="billing && account.is_vendor"
                :account-id="account.id"
                :currency="account.currency"
                :owed="account.vendor_balance"
                :timezones="timezones"
                :entries="vendorLedger"
                :invoices="vendorInvoices"
            />

            <section class="ss-stack-2">
                <Heading variant="small" title="Details" />
                <dl class="ss-grid ss-cols-2 gap-2">
                    <template v-if="billing">
                        <dt class="text-secondary">Billing email</dt>
                        <dd>{{ account.billing_email ?? '—' }}</dd>
                    </template>
                    <dt class="text-secondary">NOC email</dt>
                    <dd>{{ account.noc_email ?? '—' }}</dd>
                    <dt class="text-secondary">Time zone</dt>
                    <dd>{{ account.timezone }}</dd>
                </dl>
                <p v-if="account.notes" class="ss-pre-line text-secondary">{{ account.notes }}</p>
            </section>
        </div>
    </div>
</template>
