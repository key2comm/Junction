<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { computed } from 'vue';
import VendorBillingController from '@/actions/App/Http/Controllers/Admin/VendorBillingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { date, money } from '@/lib/format';
import { index as accountsIndex, show as showAccount } from '@/routes/accounts';
import { index as cdrIndex } from '@/routes/cdrs';
import { show } from '@/routes/vendor-invoices';

type Invoice = {
    id: number;
    reference: string;
    period_start: string;
    period_end: string;
    timezone: string;
    currency: string;
    amount: string;
    calls: number | null;
    minutes: string | null;
    our_amount: string;
    our_calls: number;
    our_minutes: string;
    difference: string;
    status: 'open' | 'accepted' | 'disputed';
    notes: string | null;
    filename: string | null;
    resolved_at: string | null;
    vendor: { id: number; code: string; name: string; vendor_balance: string };
};

type Row = {
    prefix: string | null;
    destination: string | null;
    their_calls: number | null;
    their_minutes: string | null;
    their_amount: string | null;
    our_calls: number;
    our_minutes: number;
    our_amount: string;
    difference: string;
};

const props = defineProps<{ invoice: Invoice; comparison: Row[]; has_breakdown: boolean }>();

defineOptions({
    layout: (props: { invoice: { id: number; reference: string; vendor: { id: number; code: string } } }) => ({
        breadcrumbs: [
            { title: 'Accounts', href: accountsIndex() },
            { title: props.invoice.vendor.code, href: showAccount(props.invoice.vendor.id) },
            { title: props.invoice.reference, href: show(props.invoice.id) },
        ],
    }),
});

const inv = props.invoice;
const m = (v: string | null) => (v === null ? '—' : money(v, inv.currency));
const pct = computed(() => (Number(inv.our_amount) ? ((Number(inv.difference) / Number(inv.our_amount)) * 100).toFixed(2) : null));
const lastDay = new Date(new Date(inv.period_end).getTime() - 1000).toISOString();
// Our CDRs for this vendor and period, as evidence (the call records page shows up to 31 days at a time).
const evidenceEnd = new Date(Math.min(new Date(inv.period_end).getTime(), new Date(inv.period_start).getTime() + 31 * 86400_000)).toISOString();
const evidence = cdrIndex({ query: { vendor: inv.vendor.id, from: inv.period_start, to: evidenceEnd, status: 'answered' } });
const flagged = (r: Row) => Math.abs(Number(r.difference)) > Math.max(0.01, Math.abs(Number(r.our_amount)) * 0.01);
</script>

<template>
    <Head :title="`Vendor invoice ${props.invoice.reference}`" />

    <div class="ss-maxw-5xl ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div class="ss-stack-2">
                <Heading :title="`${inv.vendor.name}: invoice ${inv.reference}`" :description="`${date(inv.period_start, inv.timezone)} – ${date(lastDay, inv.timezone)} (${inv.timezone})`" />
                <Badge :variant="inv.status === 'accepted' ? 'default' : inv.status === 'disputed' ? 'destructive' : 'secondary'">{{ inv.status }}</Badge>
            </div>
            <div class="d-flex gap-2">
                <Button variant="outline" as-child><Link :href="evidence">Our call records</Link></Button>
                <Form v-if="inv.status !== 'accepted'" v-bind="VendorBillingController.refresh.form(inv.id)" v-slot="{ processing }">
                    <Button variant="outline" :disabled="processing" title="Re-read our call records (e.g. after late CDRs)"><RefreshCw class="icon" /> Refresh ours</Button>
                </Form>
            </div>
        </div>

        <div class="ss-grid gap-3 ss-cols-sm-3">
            <div class="card card-body">
                <p class="small text-secondary">They bill</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ m(inv.amount) }}</p>
                <p class="small text-secondary">
                    {{ inv.calls !== null ? `${inv.calls.toLocaleString()} calls` : '' }} {{ inv.minutes !== null ? `· ${Number(inv.minutes).toLocaleString()} min` : '' }}
                </p>
            </div>
            <div class="card card-body">
                <p class="small text-secondary">Our records say</p>
                <p class="fs-1 fw-semibold tabular-nums">{{ m(inv.our_amount) }}</p>
                <p class="small text-secondary">{{ inv.our_calls.toLocaleString() }} calls · {{ Number(inv.our_minutes).toLocaleString() }} min</p>
            </div>
            <div class="card card-body" :class="{ 'border-danger': Number(inv.difference) > 0 }">
                <p class="small text-secondary">Difference (theirs − ours)</p>
                <p class="fs-1 fw-semibold tabular-nums" :class="{ 'text-danger': Number(inv.difference) > 0 }">{{ m(inv.difference) }}</p>
                <p class="small text-secondary">{{ pct !== null ? `${pct}% of our figure` : '' }}</p>
            </div>
        </div>

        <section class="ss-stack-2">
            <Heading
                variant="small"
                title="By destination"
                :description="has_breakdown ? 'Their breakdown against our records, biggest differences first. Rows over 1% (or 0.01) are highlighted.' : 'Our records by destination. Upload their breakdown CSV when entering an invoice to compare line by line.'"
            />
            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Prefix</th>
                            <th>Destination</th>
                            <th v-if="has_breakdown" class="text-end">Their min</th>
                            <th class="text-end">Our min</th>
                            <th v-if="has_breakdown" class="text-end">Theirs</th>
                            <th class="text-end">Ours</th>
                            <th v-if="has_breakdown" class="text-end">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in comparison" :key="r.prefix ?? 'none'" :class="{ 'bg-danger-lt': has_breakdown && flagged(r) }">
                            <td class="font-monospace">{{ r.prefix ?? '—' }}</td>
                            <td>{{ r.destination ?? '' }}</td>
                            <td v-if="has_breakdown" class="text-end tabular-nums">{{ r.their_minutes ?? '—' }}</td>
                            <td class="text-end tabular-nums">{{ r.our_minutes }}</td>
                            <td v-if="has_breakdown" class="text-end tabular-nums">{{ m(r.their_amount) }}</td>
                            <td class="text-end tabular-nums">{{ m(r.our_amount) }}</td>
                            <td v-if="has_breakdown" class="text-end tabular-nums">{{ m(r.difference) }}</td>
                        </tr>
                        <tr v-if="comparison.length === 0">
                            <td colspan="7" class="text-center text-secondary">No calls to this vendor in the period.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="inv.notes" class="ss-stack-1">
            <h3 class="fw-medium">Notes</h3>
            <p class="ss-pre-line text-secondary">{{ inv.notes }}</p>
        </section>

        <section v-if="inv.status !== 'accepted'" class="ss-grid gap-3 ss-cols-sm-2">
            <div class="card card-body ss-stack-2">
                <h3 class="fw-medium">Accept</h3>
                <p class="text-secondary">
                    Agree to pay their figure. {{ Number(inv.difference) ? `The difference (${m(inv.difference)}) is booked as an adjustment to what you owe.` : 'It matches our records exactly.' }}
                </p>
                <Form v-bind="VendorBillingController.accept.form(inv.id)" v-slot="{ errors, processing }">
                    <Button :disabled="processing">Accept {{ m(inv.amount) }}</Button>
                    <InputError :message="errors.status" />
                </Form>
            </div>
            <div class="card card-body ss-stack-2">
                <h3 class="fw-medium">Dispute</h3>
                <p class="text-secondary">Keep it open as disputed with a note. Export our call records above as evidence.</p>
                <Form v-bind="VendorBillingController.dispute.form(inv.id)" reset-on-success class="d-flex flex-wrap align-items-start gap-2" v-slot="{ errors, processing }">
                    <div class="ss-grid flex-fill gap-1">
                        <Input name="note" placeholder="What's wrong" required />
                        <InputError :message="errors.note" />
                    </div>
                    <Button variant="outline" :disabled="processing">Mark disputed</Button>
                </Form>
            </div>
        </section>
        <p v-else class="text-secondary">Accepted {{ date(inv.resolved_at) }}. You now owe {{ m(inv.vendor.vendor_balance) }} in total.</p>
    </div>
</template>
