<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/billing';
import { edit as editEmail } from '@/routes/email';

const props = defineProps<{
    settings: { enabled: boolean; auto_email_invoices: boolean; invoice_due_days: number; tax_numbers: string };
    mailConfigured: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Billing', href: edit() }] } });

// The choice on the form (saved only when the form is submitted).
const on = ref(props.settings.enabled);
</script>

<template>
    <Head title="Billing settings" />

    <h1 class="visually-hidden">Billing settings</h1>

    <Form v-bind="BillingController.update.form()" class="ss-stack-4 ss-maxw-3xl" v-slot="{ errors, processing }">
        <div class="ss-stack-3">
            <div class="d-flex align-items-start justify-content-between gap-3">
                <Heading
                    variant="small"
                    title="Billing platform"
                    description="Whether this switch bills your customers itself, or only routes their calls while another system bills them"
                />
                <Badge :variant="settings.enabled ? 'default' : 'secondary'">{{ settings.enabled ? 'On' : 'Off' }}</Badge>
            </div>

            <div class="ss-grid gap-2 ss-cols-md-2">
                <label class="card card-body d-flex flex-row gap-2 mb-0" :class="{ 'border-primary': on }">
                    <input type="radio" name="enabled" value="1" :checked="on" class="form-check-input m-0 mt-1 flex-shrink-0" @change="on = true" />
                    <span class="ss-stack-1">
                        <span class="fw-medium">Use the built-in billing</span>
                        <span class="small text-secondary">
                            Customer balances, payments and credit limits; calls are refused when funds run out. Monthly invoices, low-balance
                            alerts, and carrier payables with invoice reconciliation.
                        </span>
                    </span>
                </label>
                <label class="card card-body d-flex flex-row gap-2 mb-0" :class="{ 'border-primary': !on }">
                    <input type="radio" name="enabled" value="0" :checked="!on" class="form-check-input m-0 mt-1 flex-shrink-0" @change="on = false" />
                    <span class="ss-stack-1">
                        <span class="fw-medium">Routing only</span>
                        <span class="small text-secondary">
                            Another platform bills your customers. No invoices or low-balance emails are sent, and calls aren't limited by balance.
                            Carrier rates still drive least-cost routing, and call records still show cost and margin.
                        </span>
                    </span>
                </label>
            </div>
            <InputError :message="errors.enabled" />

            <p v-if="on && !settings.enabled" class="rounded border border-warning p-2">
                <strong>Before turning billing on</strong>, check each customer's balance and credit limit. Calls made while billing was off are
                already counted in balances, so prepaid customers may be at or below zero, and their calls will be refused until you record a
                payment or raise the credit limit.
            </p>
            <p v-else-if="!on && settings.enabled" class="rounded border border-warning p-2">
                Turning billing off hides balances, invoices and payables (nothing is deleted) and stops the funds check on new calls. Channel,
                CPS and fraud limits still apply.
            </p>
        </div>

        <section v-if="on" class="ss-stack-3">
            <Heading variant="small" title="Invoices" description="Issued automatically two hours after each customer's month ends, in their time zone" />
            <div class="ss-grid gap-3 ss-cols-sm-2">
                <div class="ss-grid gap-1">
                    <Label for="invoice_due_days">Payment terms (days)</Label>
                    <Input id="invoice_due_days" name="invoice_due_days" type="number" min="0" max="365" :default-value="settings.invoice_due_days" required class="ss-w-24" />
                    <p class="small text-secondary">When postpaid invoices are due. Prepaid accounts pay in advance.</p>
                    <InputError :message="errors.invoice_due_days" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="tax_numbers">Your tax registration numbers</Label>
                    <textarea
                        id="tax_numbers"
                        name="tax_numbers"
                        rows="3"
                        class="form-control"
                        maxlength="500"
                        placeholder="GST/HST 123456789 RT0001&#10;QST 1234567890 TQ0001"
                        :value="settings.tax_numbers"
                    ></textarea>
                    <p class="small text-secondary">One per line, printed on every invoice. Rates are set in Billing → Taxes.</p>
                    <InputError :message="errors.tax_numbers" />
                </div>
            </div>
            <label class="d-flex align-items-center gap-2">
                <input type="checkbox" name="auto_email_invoices" value="1" :checked="settings.auto_email_invoices" class="form-check-input m-0" />
                Email invoices to customers automatically when they're issued
            </label>
            <p v-if="!mailConfigured" class="small text-secondary">
                Email isn't set up yet, so invoices can't be sent. <Link :href="editEmail()" class="fw-medium text-body">Set up email</Link>
            </p>
        </section>

        <div>
            <Button :disabled="processing">Save</Button>
        </div>
    </Form>
</template>
