<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import VendorBillingController from '@/actions/App/Http/Controllers/Admin/VendorBillingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date, money, titleCase } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { show as showVendorInvoice } from '@/routes/vendor-invoices';

const props = defineProps<{
    accountId: number;
    currency: string;
    owed: string;
    timezones: string[];
    entries: { id: number; type: string; amount: string; balance_after: string; description: string; effective_at: string }[];
    invoices: { id: number; reference: string; period_start: string; period_end: string; timezone: string; amount: string; our_amount: string; status: string; difference: string }[];
}>();

const m = (v: string) => money(v, props.currency);
const statusVariant = (s: string) => (s === 'accepted' ? 'default' : s === 'disputed' ? 'destructive' : 'secondary');
const lastDay = (iso: string) => new Date(new Date(iso).getTime() - 1000).toISOString();
</script>

<template>
    <section class="ss-stack-4 ss-span-lg-2">
        <Heading variant="small" title="Carrier payables" description="What you owe this carrier: usage from your own call records, less the payments you make." />

        <div class="ss-grid gap-3 ss-cols-sm-3">
            <div class="card card-body">
                <p class="small text-secondary">Owed to carrier</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ m(owed) }}</p>
            </div>
            <Form
                v-bind="VendorBillingController.storeEntry.form(accountId)"
                reset-on-success
                class="ss-grid gap-2 rounded border p-3 ss-span-sm-2 ss-gtc-sm-auto-1fr-2fr-auto align-items-sm-start"
                v-slot="{ errors, processing }"
            >
                <select name="type" :class="selectClass" class="w-auto" aria-label="Type">
                    <option value="payment">Payment made</option>
                    <option value="adjustment">Adjustment</option>
                </select>
                <div class="ss-grid gap-1">
                    <Input name="amount" type="number" step="0.000001" placeholder="Amount" required aria-label="Amount" />
                    <InputError :message="errors.amount" />
                </div>
                <div class="ss-grid gap-1">
                    <Input name="description" placeholder="Reference (e.g. wire #)" required aria-label="Description" />
                    <InputError :message="errors.description" />
                </div>
                <Button :disabled="processing">Record</Button>
                <p class="small text-secondary ss-span-sm-4">Payments are entered as positive amounts. Adjustments: positive = you owe more.</p>
            </Form>
        </div>

        <div class="ss-grid gap-4 ss-cols-lg-2">
            <div class="ss-stack-2">
                <h3 class="fw-medium">Carrier invoices</h3>
                <div class="card table-responsive">
                    <table class="table table-vcenter card-table table-tight">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Period</th>
                                <th class="text-end">Theirs</th>
                                <th class="text-end">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in invoices" :key="i.id">
                                <td>
                                    <Link :href="showVendorInvoice(i.id)" class="font-monospace">{{ i.reference }}</Link>
                                    <Badge :variant="statusVariant(i.status)" class="ms-2">{{ i.status }}</Badge>
                                </td>
                                <td class="text-nowrap small">{{ date(i.period_start, i.timezone) }} – {{ date(lastDay(i.period_end), i.timezone) }}</td>
                                <td class="text-end tabular-nums">{{ m(i.amount) }}</td>
                                <td class="text-end tabular-nums" :class="{ 'text-danger': Number(i.difference) > 0 }">{{ m(i.difference) }}</td>
                            </tr>
                            <tr v-if="invoices.length === 0">
                                <td colspan="4" class="text-center text-secondary">No carrier invoices recorded.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <details class="card card-body">
                    <summary class="fw-medium">Enter a carrier invoice</summary>
                    <Form v-bind="VendorBillingController.storeInvoice.form(accountId)" class="mt-3 ss-grid gap-2 ss-cols-sm-2" v-slot="{ errors, processing }">
                        <div class="ss-grid gap-1 ss-span-sm-2">
                            <Label for="vi_reference">Their invoice number</Label>
                            <Input id="vi_reference" name="reference" required />
                            <InputError :message="errors.reference" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="vi_start">Period from</Label>
                            <Input id="vi_start" name="period_start" type="date" required />
                            <InputError :message="errors.period_start" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="vi_end">Period to (inclusive)</Label>
                            <Input id="vi_end" name="period_end" type="date" required />
                            <InputError :message="errors.period_end" />
                        </div>
                        <div class="ss-grid gap-1 ss-span-sm-2">
                            <Label for="vi_tz">Their billing time zone</Label>
                            <select id="vi_tz" name="timezone" :class="selectClass">
                                <option v-for="tz in timezones" :key="tz" :value="tz" :selected="tz === 'UTC'">{{ tz }}</option>
                            </select>
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="vi_amount">Their total</Label>
                            <Input id="vi_amount" name="amount" type="number" step="0.000001" min="0" required />
                            <InputError :message="errors.amount" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="vi_minutes">Their minutes (optional)</Label>
                            <Input id="vi_minutes" name="minutes" type="number" step="0.01" min="0" />
                        </div>
                        <div class="ss-grid gap-1 ss-span-sm-2">
                            <Label for="vi_breakdown">Their breakdown CSV (optional)</Label>
                            <Input id="vi_breakdown" name="breakdown" type="file" accept=".csv,text/csv,text/plain" />
                            <p class="small text-secondary">Columns: prefix, amount; optional destination, calls, minutes. Enables a per-destination comparison.</p>
                            <InputError :message="errors.breakdown" />
                        </div>
                        <Button :disabled="processing" class="ss-span-sm-2 ss-justify-self-sm-start">Compare with our records</Button>
                    </Form>
                </details>
            </div>

            <div class="ss-stack-2">
                <h3 class="fw-medium">Payables history</h3>
                <div class="card table-responsive">
                    <table class="table table-vcenter card-table table-tight">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Owed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in entries" :key="e.id">
                                <td class="text-nowrap text-secondary">{{ date(e.effective_at) }}</td>
                                <td><Badge variant="outline" class="me-1">{{ titleCase(e.type) }}</Badge>{{ e.description }}</td>
                                <td class="text-end tabular-nums">{{ m(e.amount) }}</td>
                                <td class="text-end tabular-nums">{{ m(e.balance_after) }}</td>
                            </tr>
                            <tr v-if="entries.length === 0">
                                <td colspan="4" class="text-center text-secondary">Nothing yet. Usage is posted from your call records every hour.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
