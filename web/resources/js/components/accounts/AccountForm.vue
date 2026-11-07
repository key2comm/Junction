<script setup lang="ts">
import type { Method } from '@inertiajs/core';
import { Form, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { titleCase } from '@/lib/format';

export type AccountFormOptions = {
    statuses: string[];
    billingTypes: string[];
    rateDecks: { id: number; name: string; currency: string }[];
    routingPlans: { id: number; name: string }[];
    timezones: string[];
};

export type AccountFormValues = {
    name: string;
    code: string;
    is_customer: boolean;
    is_vendor: boolean;
    status: string;
    currency: string;
    billing_type: string;
    credit_limit: string;
    timezone: string;
    billing_email: string | null;
    noc_email: string | null;
    sell_rate_deck_id: number | null;
    routing_plan_id: number | null;
    max_channels: number | null;
    max_cps: string | null;
    notes: string | null;
};

const props = defineProps<{
    action: { action: string; method: Method };
    options: AccountFormOptions;
    account?: Partial<AccountFormValues>;
    submitLabel: string;
    cancelHref: string;
}>();

const v: Partial<AccountFormValues> = {
    status: 'active',
    currency: 'USD',
    billing_type: 'prepaid',
    credit_limit: '0',
    timezone: 'UTC',
    is_customer: true,
    ...props.account,
};

const select =
    'mt-1 block h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 outline-none dark:bg-input/30';
</script>

<template>
    <Form v-bind="action" class="space-y-8" v-slot="{ errors, processing }">
        <section class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="name">Company name</Label>
                <Input id="name" name="name" :default-value="v.name" required />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="code">Account code</Label>
                <Input id="code" name="code" :default-value="v.code" required maxlength="32" placeholder="ACME01" class="uppercase" />
                <p class="text-xs text-muted-foreground">Short reference used on CDRs and invoices.</p>
                <InputError :message="errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <select id="status" name="status" :class="select">
                    <option v-for="s in options.statuses" :key="s" :value="s" :selected="s === v.status">{{ titleCase(s) }}</option>
                </select>
                <InputError :message="errors.status" />
            </div>

            <fieldset class="grid gap-2 sm:col-span-2">
                <legend class="text-sm font-medium">Role</legend>
                <div class="flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="is_customer" value="1" :checked="v.is_customer" class="size-4" />
                        Customer <span class="text-muted-foreground">(sends us traffic)</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="is_vendor" value="1" :checked="v.is_vendor" class="size-4" />
                        Vendor <span class="text-muted-foreground">(we send traffic to them)</span>
                    </label>
                </div>
                <InputError :message="errors.is_customer" />
            </fieldset>
        </section>

        <section class="grid gap-6 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">Billing</h2>

            <div class="grid gap-2">
                <Label for="billing_type">Billing type</Label>
                <select id="billing_type" name="billing_type" :class="select">
                    <option v-for="b in options.billingTypes" :key="b" :value="b" :selected="b === v.billing_type">{{ titleCase(b) }}</option>
                </select>
                <InputError :message="errors.billing_type" />
            </div>

            <div class="grid gap-2">
                <Label for="credit_limit">Credit limit</Label>
                <Input id="credit_limit" name="credit_limit" type="number" step="0.01" min="0" :default-value="v.credit_limit" required />
                <p class="text-xs text-muted-foreground">How far below zero the balance may go before calls are refused.</p>
                <InputError :message="errors.credit_limit" />
            </div>

            <div class="grid gap-2">
                <Label for="currency">Currency</Label>
                <Input id="currency" name="currency" :default-value="v.currency" maxlength="3" required class="uppercase" />
                <InputError :message="errors.currency" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Billing time zone</Label>
                <select id="timezone" name="timezone" :class="select">
                    <option v-for="tz in options.timezones" :key="tz" :value="tz" :selected="tz === v.timezone">{{ tz }}</option>
                </select>
                <InputError :message="errors.timezone" />
            </div>

            <div class="grid gap-2">
                <Label for="billing_email">Billing email</Label>
                <Input id="billing_email" name="billing_email" type="email" :default-value="v.billing_email ?? ''" />
                <InputError :message="errors.billing_email" />
            </div>

            <div class="grid gap-2">
                <Label for="noc_email">NOC email</Label>
                <Input id="noc_email" name="noc_email" type="email" :default-value="v.noc_email ?? ''" />
                <InputError :message="errors.noc_email" />
            </div>
        </section>

        <section class="grid gap-6 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <h2 class="text-sm font-semibold">Customer settings</h2>
                <p class="text-xs text-muted-foreground">Used when this account sends us traffic.</p>
            </div>

            <div class="grid gap-2">
                <Label for="sell_rate_deck_id">Sell rate deck</Label>
                <select id="sell_rate_deck_id" name="sell_rate_deck_id" :class="select">
                    <option value="">— None —</option>
                    <option v-for="d in options.rateDecks" :key="d.id" :value="d.id" :selected="d.id === v.sell_rate_deck_id">{{ d.name }} ({{ d.currency }})</option>
                </select>
                <InputError :message="errors.sell_rate_deck_id" />
            </div>

            <div class="grid gap-2">
                <Label for="routing_plan_id">Routing plan</Label>
                <select id="routing_plan_id" name="routing_plan_id" :class="select">
                    <option value="">— None —</option>
                    <option v-for="p in options.routingPlans" :key="p.id" :value="p.id" :selected="p.id === v.routing_plan_id">{{ p.name }}</option>
                </select>
                <InputError :message="errors.routing_plan_id" />
            </div>

            <div class="grid gap-2">
                <Label for="max_channels">Max concurrent calls</Label>
                <Input id="max_channels" name="max_channels" type="number" min="1" :default-value="v.max_channels ?? ''" placeholder="Unlimited" />
                <InputError :message="errors.max_channels" />
            </div>

            <div class="grid gap-2">
                <Label for="max_cps">Max calls per second</Label>
                <Input id="max_cps" name="max_cps" type="number" step="0.1" min="0.1" :default-value="v.max_cps ?? ''" placeholder="Unlimited" />
                <InputError :message="errors.max_cps" />
            </div>
        </section>

        <div class="grid gap-2">
            <Label for="notes">Notes</Label>
            <textarea
                id="notes"
                name="notes"
                rows="3"
                class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                >{{ v.notes ?? '' }}</textarea
            >
            <InputError :message="errors.notes" />
        </div>

        <div class="flex items-center gap-3">
            <Button :disabled="processing">{{ submitLabel }}</Button>
            <Button variant="ghost" as-child><Link :href="cancelHref">Cancel</Link></Button>
        </div>
    </Form>
</template>
