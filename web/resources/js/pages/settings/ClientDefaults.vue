<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ClientDefaultsController from '@/actions/App/Http/Controllers/Settings/ClientDefaultsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBilling } from '@/composables/useBilling';
import { titleCase } from '@/lib/format';
import { edit } from '@/routes/client-defaults';

type Defaults = {
    status: string;
    currency: string;
    billing_type: string;
    credit_limit: string;
    low_balance_threshold: string | null;
    timezone: string;
    sell_rate_deck_id: number | null;
    routing_plan_id: number | null;
    max_channels: number | null;
    max_cps: string | null;
    billing_profile_id: number | null;
};

const props = defineProps<{
    defaults: Defaults;
    options: {
        statuses: string[];
        billingTypes: string[];
        rateDecks: { id: number; name: string; currency: string }[];
        routingPlans: { id: number; name: string }[];
        billingProfiles: { id: number; name: string }[];
        timezones: string[];
        currencies: { code: string; name: string; top: boolean }[];
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Client defaults', href: edit() }] } });

const billing = useBilling();
const select = 'form-select';
const topCurrencies = props.options.currencies.filter((c) => c.top);
const otherCurrencies = props.options.currencies.filter((c) => !c.top);

// Warn when the default tariff is priced in another currency than the default.
const currency = ref(props.defaults.currency);
const tariff = ref<number | ''>(props.defaults.sell_rate_deck_id ?? '');
const tariffCurrency = computed(() => props.options.rateDecks.find((d) => d.id === tariff.value)?.currency);
</script>

<template>
    <Head title="Client defaults" />

    <h1 class="visually-hidden">Client defaults</h1>

    <Form v-bind="ClientDefaultsController.update.form()" class="ss-stack-4 ss-maxw-3xl" v-slot="{ errors, processing }">
        <Heading
            variant="small"
            title="Client defaults"
            description="What a new customer starts with. The New customer form is filled in with these, so you only enter the customer's details; you can still change any of them for one customer. Existing customers aren't changed."
        />

        <section class="ss-grid gap-4 ss-cols-sm-2">
            <h2 class="fw-semibold ss-span-sm-2 mb-0">Routing and limits</h2>

            <div class="ss-grid gap-2">
                <Label for="sell_rate_deck_id">Tariff</Label>
                <select id="sell_rate_deck_id" v-model="tariff" name="sell_rate_deck_id" :class="select">
                    <option value="">— None —</option>
                    <option v-for="d in options.rateDecks" :key="d.id" :value="d.id">{{ d.name }} ({{ d.currency }})</option>
                </select>
                <p v-if="tariffCurrency && tariffCurrency !== currency" class="small text-warning">This tariff is in {{ tariffCurrency }}, but the default currency is {{ currency }}.</p>
                <InputError :message="errors.sell_rate_deck_id" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="routing_plan_id">LCR group</Label>
                <select id="routing_plan_id" name="routing_plan_id" :class="select">
                    <option value="">— None —</option>
                    <option v-for="p in options.routingPlans" :key="p.id" :value="p.id" :selected="p.id === defaults.routing_plan_id">{{ p.name }}</option>
                </select>
                <InputError :message="errors.routing_plan_id" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="max_channels">Max concurrent calls</Label>
                <Input id="max_channels" name="max_channels" type="number" min="1" :default-value="defaults.max_channels ?? ''" placeholder="Unlimited" />
                <InputError :message="errors.max_channels" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="max_cps">Max calls per second</Label>
                <Input id="max_cps" name="max_cps" type="number" step="0.1" min="0.1" :default-value="defaults.max_cps ?? ''" placeholder="Unlimited" />
                <InputError :message="errors.max_cps" />
            </div>
        </section>

        <section class="ss-grid gap-4 ss-cols-sm-2">
            <h2 class="fw-semibold ss-span-sm-2 mb-0">Account</h2>

            <div class="ss-grid gap-2">
                <Label for="status">Status</Label>
                <select id="status" name="status" :class="select">
                    <option v-for="s in options.statuses" :key="s" :value="s" :selected="s === defaults.status">{{ titleCase(s) }}</option>
                </select>
                <InputError :message="errors.status" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="currency">Currency</Label>
                <select id="currency" v-model="currency" name="currency" :class="select" required>
                    <option v-for="c in topCurrencies" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name }}</option>
                    <option disabled>──────────</option>
                    <option v-for="c in otherCurrencies" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name }}</option>
                </select>
                <InputError :message="errors.currency" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="timezone">{{ billing ? 'Billing time zone' : 'Time zone' }}</Label>
                <select id="timezone" name="timezone" :class="select">
                    <option v-for="tz in options.timezones" :key="tz" :value="tz" :selected="tz === defaults.timezone">{{ tz }}</option>
                </select>
                <InputError :message="errors.timezone" />
            </div>
        </section>

        <!-- With billing off these aren't used, but are kept as they are. -->
        <template v-if="!billing">
            <input type="hidden" name="billing_type" :value="defaults.billing_type" />
            <input type="hidden" name="credit_limit" :value="defaults.credit_limit" />
            <input type="hidden" name="low_balance_threshold" :value="defaults.low_balance_threshold ?? ''" />
            <input type="hidden" name="billing_profile_id" :value="defaults.billing_profile_id ?? ''" />
        </template>
        <section v-else class="ss-grid gap-4 ss-cols-sm-2">
            <h2 class="fw-semibold ss-span-sm-2 mb-0">Billing</h2>

            <div class="ss-grid gap-2">
                <Label for="billing_type">Billing type</Label>
                <select id="billing_type" name="billing_type" :class="select">
                    <option v-for="b in options.billingTypes" :key="b" :value="b" :selected="b === defaults.billing_type">{{ titleCase(b) }}</option>
                </select>
                <InputError :message="errors.billing_type" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="credit_limit">Credit limit</Label>
                <Input id="credit_limit" name="credit_limit" type="number" step="0.01" min="0" :default-value="defaults.credit_limit" required />
                <InputError :message="errors.credit_limit" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="low_balance_threshold">Low-balance alert below</Label>
                <Input id="low_balance_threshold" name="low_balance_threshold" type="number" step="0.01" min="0" :default-value="defaults.low_balance_threshold ?? ''" placeholder="No alerts" />
                <InputError :message="errors.low_balance_threshold" />
            </div>

            <div class="ss-grid gap-2">
                <Label for="billing_profile_id">Billing profile</Label>
                <select id="billing_profile_id" name="billing_profile_id" :class="select">
                    <option value="">— None —</option>
                    <option v-for="p in options.billingProfiles" :key="p.id" :value="p.id" :selected="p.id === defaults.billing_profile_id">{{ p.name }}</option>
                </select>
                <p class="small text-secondary mb-0">Its items go on a new customer's bill from the day they're created (prorated).</p>
                <InputError :message="errors.billing_profile_id" />
            </div>
        </section>

        <div>
            <Button :disabled="processing">Save</Button>
        </div>
    </Form>
</template>
