<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import AccountIpController from '@/actions/App/Http/Controllers/Admin/AccountIpController';
import InvoiceController from '@/actions/App/Http/Controllers/Admin/InvoiceController';
import LedgerEntryController from '@/actions/App/Http/Controllers/Admin/LedgerEntryController';
import RouteTester from '@/components/accounts/RouteTester.vue';
import VendorPayables from '@/components/accounts/VendorPayables.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { billingMonth, dateTime, money, titleCase } from '@/lib/format';
import { edit, index, show } from '@/routes/accounts';
import { index as cdrIndex } from '@/routes/cdrs';
import { show as showInvoice } from '@/routes/invoices';

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
    sell_rate_deck: { id: number; name: string } | null;
    routing_plan: { id: number; name: string } | null;
};

const props = defineProps<{
    account: Account;
    ips: { id: number; ip: string; tech_prefix: string; description: string | null; enabled: boolean }[];
    trunks: { id: number; name: string; enabled: boolean; gateways_count: number }[];
    ledger: { id: number; type: string; amount: string; balance_after: string; description: string; created_at: string; effective_at: string }[];
    timezones: string[];
    vendorLedger: { id: number; type: string; amount: string; balance_after: string; description: string; effective_at: string }[];
    vendorInvoices: { id: number; reference: string; period_start: string; period_end: string; timezone: string; amount: string; our_amount: string; status: string; difference: string }[];
    invoices: { id: number; number: string; status: string; period_start: string; timezone: string; currency: string; usage_total: string; amount_due: string }[];
}>();

defineOptions({
    layout: (props: { account: { id: number; code: string } }) => ({
        breadcrumbs: [
            { title: 'Accounts', href: index() },
            { title: props.account.code, href: show(props.account.id) },
        ],
    }),
});

const select =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head :title="props.account.code" />

    <div class="space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <Heading :title="account.name" :description="account.code" />
                <div class="flex flex-wrap gap-1">
                    <Badge :variant="account.status === 'active' ? 'default' : 'destructive'">{{ titleCase(account.status) }}</Badge>
                    <Badge v-if="account.is_customer" variant="outline">Customer</Badge>
                    <Badge v-if="account.is_vendor" variant="outline">Vendor</Badge>
                    <Badge variant="secondary">{{ titleCase(account.billing_type) }}</Badge>
                </div>
            </div>
            <div class="flex gap-2">
                <Button v-if="account.is_customer" variant="outline" as-child>
                    <Link :href="cdrIndex({ query: { customer: account.id } })">Calls sent</Link>
                </Button>
                <Button v-if="account.is_vendor" variant="outline" as-child>
                    <Link :href="cdrIndex({ query: { vendor: account.id } })">Calls carried</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="edit(account.id)"><Pencil class="size-4" /> Edit</Link>
                </Button>
            </div>
        </div>

        <!-- Money -->
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <p class="text-xs text-muted-foreground">Balance</p>
                <p class="text-2xl font-semibold tabular-nums" :class="{ 'text-destructive': Number(account.balance) < 0 }">
                    {{ money(account.balance, account.currency) }}
                </p>
            </div>
            <div class="rounded-lg border p-4">
                <p class="text-xs text-muted-foreground">Credit limit</p>
                <p class="text-2xl font-semibold tabular-nums">{{ money(account.credit_limit, account.currency) }}</p>
            </div>
            <div class="rounded-lg border p-4" :class="{ 'border-destructive': account.low_balance_alerted_at }">
                <p class="text-xs text-muted-foreground">Available for calls now</p>
                <p class="text-2xl font-semibold tabular-nums">{{ money(account.available_now, account.currency) }}</p>
                <p class="text-xs text-muted-foreground">
                    <span v-if="account.low_balance_alerted_at" class="text-destructive">Below the alert level of {{ money(account.low_balance_threshold, account.currency) }}</span>
                    <span v-else-if="account.low_balance_threshold">Alert below {{ money(account.low_balance_threshold, account.currency) }}</span>
                    <span v-else>Includes calls in progress and usage not yet posted</span>
                </p>
            </div>
        </div>

        <div class="grid gap-8 lg:grid-cols-2">
            <!-- Customer ingress -->
            <section v-if="account.is_customer" class="space-y-4">
                <Heading
                    variant="small"
                    title="Customer IPs"
                    description="Traffic from these addresses is identified as this customer. They are also allowed through the firewall."
                />
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-muted-foreground">Sell rate deck</dt>
                    <dd>{{ account.sell_rate_deck?.name ?? '— not set —' }}</dd>
                    <dt class="text-muted-foreground">Routing plan</dt>
                    <dd>{{ account.routing_plan?.name ?? '— not set —' }}</dd>
                    <dt class="text-muted-foreground">Limits</dt>
                    <dd>{{ account.max_channels ?? '∞' }} channels, {{ account.max_cps ?? '∞' }} CPS</dd>
                </dl>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">IP / range</th>
                                <th class="px-3 py-2 font-medium">Tech prefix</th>
                                <th class="px-3 py-2 font-medium">Description</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ip in ips" :key="ip.id" class="border-t" :class="{ 'opacity-50': !ip.enabled }">
                                <td class="px-3 py-2 font-mono">{{ ip.ip }}</td>
                                <td class="px-3 py-2 font-mono">{{ ip.tech_prefix || '—' }}</td>
                                <td class="px-3 py-2">{{ ip.description }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <Form v-bind="AccountIpController.update.form([account.id, ip.id])" class="inline" v-slot="{ processing }">
                                        <input type="hidden" name="enabled" :value="ip.enabled ? 0 : 1" />
                                        <Button size="sm" variant="ghost" :disabled="processing">{{ ip.enabled ? 'Disable' : 'Enable' }}</Button>
                                    </Form>
                                    <Form v-bind="AccountIpController.destroy.form([account.id, ip.id])" class="inline" v-slot="{ processing }">
                                        <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${ip.ip}`"><Trash2 class="size-4" /></Button>
                                    </Form>
                                </td>
                            </tr>
                            <tr v-if="ips.length === 0">
                                <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">No IPs yet. This customer can't send traffic until one is added.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Form
                    v-bind="AccountIpController.store.form(account.id)"
                    reset-on-success
                    class="grid gap-3 sm:grid-cols-[2fr_1fr_2fr_auto] sm:items-start"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-1">
                        <Label for="ip" class="sr-only">IP or range</Label>
                        <Input id="ip" name="ip" placeholder="198.51.100.7 or /24" required class="font-mono" />
                        <InputError :message="errors.ip" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="tech_prefix" class="sr-only">Tech prefix</Label>
                        <Input id="tech_prefix" name="tech_prefix" placeholder="Tech prefix" class="font-mono" />
                        <InputError :message="errors.tech_prefix" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="ip_description" class="sr-only">Description</Label>
                        <Input id="ip_description" name="description" placeholder="Description" />
                    </div>
                    <Button :disabled="processing">Add IP</Button>
                </Form>
            </section>

            <RouteTester v-if="account.is_customer" :account-id="account.id" :currency="account.currency" />

            <!-- Money movements -->
            <section class="space-y-4">
                <Heading variant="small" title="Payments and adjustments" description="Every change to the balance is recorded here and can't be edited." />

                <Form
                    v-bind="LedgerEntryController.store.form(account.id)"
                    reset-on-success
                    class="grid gap-3 sm:grid-cols-[auto_1fr_2fr_auto] sm:items-start"
                    v-slot="{ errors, processing }"
                >
                    <select name="type" :class="select" aria-label="Type">
                        <option value="payment">Payment</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                    <div class="grid gap-1">
                        <Input name="amount" type="number" step="0.000001" placeholder="Amount" required aria-label="Amount" />
                        <InputError :message="errors.amount" />
                    </div>
                    <div class="grid gap-1">
                        <Input name="description" placeholder="Reference / reason" required aria-label="Description" />
                        <InputError :message="errors.description" />
                    </div>
                    <Button :disabled="processing">Record</Button>
                </Form>
                <p class="text-xs text-muted-foreground">Adjustments can be negative to take money off the account.</p>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">When</th>
                                <th class="px-3 py-2 font-medium">Description</th>
                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                                <th class="px-3 py-2 text-right font-medium">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in ledger" :key="e.id" class="border-t">
                                <td class="px-3 py-2 whitespace-nowrap text-muted-foreground">{{ dateTime(e.created_at) }}</td>
                                <td class="px-3 py-2">
                                    <Badge variant="outline" class="mr-1">{{ titleCase(e.type) }}</Badge>{{ e.description }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="Number(e.amount) < 0 ? 'text-destructive' : 'text-green-600 dark:text-green-400'">
                                    {{ money(e.amount, account.currency) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money(e.balance_after, account.currency) }}</td>
                            </tr>
                            <tr v-if="ledger.length === 0">
                                <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">No entries yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Invoices -->
            <section v-if="account.is_customer" class="space-y-4">
                <Heading variant="small" title="Invoices" description="Issued automatically after each month ends. Issue one by hand for any finished month (e.g. after voiding)." />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="i in invoices" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" :class="{ 'opacity-60': i.status === 'void' }">
                        <span>
                            <Link :href="showInvoice(i.id)" class="font-mono hover:underline">{{ i.number }}</Link>
                            <span class="ml-2 text-muted-foreground">{{ billingMonth(i.period_start, i.timezone) }}</span>
                            <Badge v-if="i.status === 'void'" variant="secondary" class="ml-2">void</Badge>
                        </span>
                        <span class="tabular-nums">{{ Number(i.amount_due) ? `${money(i.amount_due, i.currency)} due` : money(i.usage_total, i.currency) }}</span>
                    </li>
                    <li v-if="invoices.length === 0" class="px-3 py-6 text-center text-muted-foreground">No invoices yet.</li>
                </ul>
                <Form v-bind="InvoiceController.store.form(account.id)" class="flex flex-wrap items-start gap-2" v-slot="{ errors, processing }">
                    <div class="grid gap-1">
                        <Input name="month" type="month" required aria-label="Billing month" class="w-44" />
                        <InputError :message="errors.month" />
                    </div>
                    <Button variant="outline" :disabled="processing">Issue invoice</Button>
                </Form>
            </section>

            <!-- Vendor egress -->
            <section v-if="account.is_vendor" class="space-y-4">
                <Heading variant="small" title="Vendor trunks" description="Where we send traffic to this vendor. Managed from the Trunks page." />
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="t in trunks" :key="t.id" class="flex justify-between px-3 py-2">
                        <span :class="{ 'opacity-50': !t.enabled }">{{ t.name }}</span>
                        <span class="text-muted-foreground">{{ t.gateways_count }} gateway(s)</span>
                    </li>
                    <li v-if="trunks.length === 0" class="px-3 py-6 text-center text-muted-foreground">No trunks yet.</li>
                </ul>
            </section>

            <VendorPayables
                v-if="account.is_vendor"
                :account-id="account.id"
                :currency="account.currency"
                :owed="account.vendor_balance"
                :timezones="timezones"
                :entries="vendorLedger"
                :invoices="vendorInvoices"
            />

            <section class="space-y-2 text-sm">
                <Heading variant="small" title="Details" />
                <dl class="grid grid-cols-2 gap-2">
                    <dt class="text-muted-foreground">Billing email</dt>
                    <dd>{{ account.billing_email ?? '—' }}</dd>
                    <dt class="text-muted-foreground">NOC email</dt>
                    <dd>{{ account.noc_email ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Time zone</dt>
                    <dd>{{ account.timezone }}</dd>
                </dl>
                <p v-if="account.notes" class="whitespace-pre-line text-muted-foreground">{{ account.notes }}</p>
            </section>
        </div>
    </div>
</template>
