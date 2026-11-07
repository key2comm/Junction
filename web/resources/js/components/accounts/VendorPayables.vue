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
    <section class="space-y-6 lg:col-span-2">
        <Heading variant="small" title="Vendor payables" description="What you owe this vendor: usage from your own call records, less the payments you make." />

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <p class="text-xs text-muted-foreground">Owed to vendor</p>
                <p class="text-2xl font-semibold tabular-nums">{{ m(owed) }}</p>
            </div>
            <Form
                v-bind="VendorBillingController.storeEntry.form(accountId)"
                reset-on-success
                class="grid gap-2 rounded-lg border p-4 sm:col-span-2 sm:grid-cols-[auto_1fr_2fr_auto] sm:items-start"
                v-slot="{ errors, processing }"
            >
                <select name="type" :class="selectClass" class="w-auto" aria-label="Type">
                    <option value="payment">Payment made</option>
                    <option value="adjustment">Adjustment</option>
                </select>
                <div class="grid gap-1">
                    <Input name="amount" type="number" step="0.000001" placeholder="Amount" required aria-label="Amount" />
                    <InputError :message="errors.amount" />
                </div>
                <div class="grid gap-1">
                    <Input name="description" placeholder="Reference (e.g. wire #)" required aria-label="Description" />
                    <InputError :message="errors.description" />
                </div>
                <Button :disabled="processing">Record</Button>
                <p class="text-xs text-muted-foreground sm:col-span-4">Payments are entered as positive amounts. Adjustments: positive = you owe more.</p>
            </Form>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-3">
                <h3 class="text-sm font-medium">Vendor invoices</h3>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">Invoice</th>
                                <th class="px-3 py-2 font-medium">Period</th>
                                <th class="px-3 py-2 text-right font-medium">Theirs</th>
                                <th class="px-3 py-2 text-right font-medium">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in invoices" :key="i.id" class="border-t">
                                <td class="px-3 py-1.5">
                                    <Link :href="showVendorInvoice(i.id)" class="font-mono hover:underline">{{ i.reference }}</Link>
                                    <Badge :variant="statusVariant(i.status)" class="ml-2">{{ i.status }}</Badge>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap text-xs">{{ date(i.period_start, i.timezone) }} – {{ date(lastDay(i.period_end), i.timezone) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ m(i.amount) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums" :class="{ 'text-destructive': Number(i.difference) > 0 }">{{ m(i.difference) }}</td>
                            </tr>
                            <tr v-if="invoices.length === 0">
                                <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">No vendor invoices recorded.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <details class="rounded-lg border p-4">
                    <summary class="cursor-pointer text-sm font-medium">Enter a vendor invoice</summary>
                    <Form v-bind="VendorBillingController.storeInvoice.form(accountId)" class="mt-4 grid gap-3 sm:grid-cols-2" v-slot="{ errors, processing }">
                        <div class="grid gap-1 sm:col-span-2">
                            <Label for="vi_reference">Their invoice number</Label>
                            <Input id="vi_reference" name="reference" required />
                            <InputError :message="errors.reference" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="vi_start">Period from</Label>
                            <Input id="vi_start" name="period_start" type="date" required />
                            <InputError :message="errors.period_start" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="vi_end">Period to (inclusive)</Label>
                            <Input id="vi_end" name="period_end" type="date" required />
                            <InputError :message="errors.period_end" />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label for="vi_tz">Their billing time zone</Label>
                            <select id="vi_tz" name="timezone" :class="selectClass">
                                <option v-for="tz in timezones" :key="tz" :value="tz" :selected="tz === 'UTC'">{{ tz }}</option>
                            </select>
                        </div>
                        <div class="grid gap-1">
                            <Label for="vi_amount">Their total</Label>
                            <Input id="vi_amount" name="amount" type="number" step="0.000001" min="0" required />
                            <InputError :message="errors.amount" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="vi_minutes">Their minutes (optional)</Label>
                            <Input id="vi_minutes" name="minutes" type="number" step="0.01" min="0" />
                        </div>
                        <div class="grid gap-1 sm:col-span-2">
                            <Label for="vi_breakdown">Their breakdown CSV (optional)</Label>
                            <Input id="vi_breakdown" name="breakdown" type="file" accept=".csv,text/csv,text/plain" />
                            <p class="text-xs text-muted-foreground">Columns: prefix, amount; optional destination, calls, minutes. Enables a per-destination comparison.</p>
                            <InputError :message="errors.breakdown" />
                        </div>
                        <Button :disabled="processing" class="sm:col-span-2 sm:justify-self-start">Compare with our records</Button>
                    </Form>
                </details>
            </div>

            <div class="space-y-3">
                <h3 class="text-sm font-medium">Payables history</h3>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">When</th>
                                <th class="px-3 py-2 font-medium">Description</th>
                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                                <th class="px-3 py-2 text-right font-medium">Owed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in entries" :key="e.id" class="border-t">
                                <td class="px-3 py-1.5 whitespace-nowrap text-muted-foreground">{{ date(e.effective_at) }}</td>
                                <td class="px-3 py-1.5"><Badge variant="outline" class="mr-1">{{ titleCase(e.type) }}</Badge>{{ e.description }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ m(e.amount) }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ m(e.balance_after) }}</td>
                            </tr>
                            <tr v-if="entries.length === 0">
                                <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">Nothing yet. Usage is posted from your call records every hour.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
