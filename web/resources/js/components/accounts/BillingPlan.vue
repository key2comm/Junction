<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Pencil, Trash2 } from '@lucide/vue';
import AccountChargeController from '@/actions/App/Http/Controllers/Admin/AccountChargeController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date, money } from '@/lib/format';
import { index as itemsIndex } from '@/routes/billing-items';
import { index as profilesIndex } from '@/routes/billing-profiles';

/** A customer's billing plan: their Billing profile and the items on their bill. */
export type Plan = {
    profile: { id: number; name: string; nanp_plan: string; nanp_minutes: number | null; label: string } | null;
    included_used: number | null;
    charges: {
        id: number;
        item: { id: number; name: string; unit: string; frequency: 'monthly' | 'once' };
        quantity: string;
        price: string | null;
        unit_price: string;
        total: string;
        from_profile: boolean;
    }[];
    monthly_total: string;
    profiles: { id: number; name: string }[];
    items: { id: number; name: string; unit: string; price: string; frequency: 'monthly' | 'once' }[];
    recent: { id: number; kind: string; description: string; amount: string; effective_at: string }[];
    tax: { region: string; summary: string } | null;
};

const props = defineProps<{ accountId: number; currency: string; timezone: string; plan: Plan }>();

const m = (v: string) => money(v, props.currency);
const qty = (v: string) => Number(v).toLocaleString();
const editing = ref<Plan['charges'][number] | null>(null);
const editOpen = computed({ get: () => editing.value !== null, set: (open: boolean) => !open && (editing.value = null) });
const bundle = computed(() => {
    const p = props.plan.profile;
    if (p?.nanp_plan !== 'bundle' || !p.nanp_minutes) {
        return null;
    }
    const used = Math.floor((props.plan.included_used ?? 0) / 60);

    return { used, total: p.nanp_minutes, pct: Math.min(100, Math.round((used / p.nanp_minutes) * 100)) };
});
const newItem = ref<number | ''>('');
const chosen = computed(() => props.plan.items.find((i) => i.id === newItem.value));
</script>

<template>
    <section class="ss-stack-3">
        <Heading
            variant="small"
            title="Billing plan"
            description="Monthly items are charged in advance on the 1st; changes during the month are prorated by days."
        />

        <Form
            v-bind="AccountChargeController.profile.form(accountId)"
            :options="{ preserveScroll: true }"
            class="d-flex flex-wrap align-items-end gap-2"
            v-slot="{ errors, processing }"
        >
            <div class="ss-grid gap-1">
                <Label for="billing_profile_id">Billing profile</Label>
                <select id="billing_profile_id" name="billing_profile_id" class="form-select w-auto">
                    <option value="">— None (pay per minute) —</option>
                    <option v-for="p in plan.profiles" :key="p.id" :value="p.id" :selected="p.id === plan.profile?.id">{{ p.name }}</option>
                </select>
                <InputError :message="errors.billing_profile_id" />
            </div>
            <Button variant="outline" :disabled="processing">Apply</Button>
            <Link :href="profilesIndex()" class="small ms-1 mb-2">Manage profiles</Link>
        </Form>
        <p class="small text-secondary mb-0">Changing the profile replaces the items that came from the old one (credited for the rest of the month) with the new one's.</p>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <Badge :variant="plan.profile && plan.profile.nanp_plan !== 'metered' ? 'default' : 'secondary'">
                {{ plan.profile?.label ?? 'Pay per minute' }}
            </Badge>
            <span class="small text-secondary">International calls pay the tariff rate.</span>
        </div>
        <div>
            <h3 class="mb-0">Taxes</h3>
            <p class="text-secondary mb-0" style="font-size: 0.75rem">
                <template v-if="plan.tax">{{ plan.tax.region }} — {{ plan.tax.summary }}</template>
                <template v-else>None (no tax region; set one with Edit)</template>
            </p>
        </div>
        <div v-if="bundle">
            <div class="d-flex justify-content-between small mb-1">
                <span>Included minutes this month</span>
                <span class="tabular-nums">{{ bundle.used.toLocaleString() }} of {{ bundle.total.toLocaleString() }}</span>
            </div>
            <div class="progress progress-sm" role="progressbar" aria-label="Included minutes used" :aria-valuenow="bundle.pct" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" :class="bundle.pct >= 100 ? 'bg-danger' : bundle.pct >= 80 ? 'bg-warning' : 'bg-primary'" :style="{ width: `${bundle.pct}%` }"></div>
            </div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in plan.charges" :key="c.id">
                        <td>
                            {{ c.item.name }}
                            <span class="small text-secondary">{{ c.item.frequency === 'monthly' ? 'monthly' : 'one-time' }}{{ c.from_profile ? ' · profile' : '' }}</span>
                        </td>
                        <td class="text-end tabular-nums">{{ qty(c.quantity) }}<span v-if="c.item.unit !== 'each'" class="text-secondary"> {{ c.item.unit }}</span></td>
                        <td class="text-end tabular-nums">{{ m(c.unit_price) }}<span v-if="c.price !== null" class="d-block small text-secondary">own price</span></td>
                        <td class="text-end tabular-nums">{{ m(c.total) }}</td>
                        <td class="text-end text-nowrap">
                            <Button size="sm" variant="ghost" :aria-label="`Change ${c.item.name}`" @click="editing = { ...c }"><Pencil class="icon" /></Button>
                            <Form v-bind="AccountChargeController.destroy.form([accountId, c.id])" :options="{ preserveScroll: true }" class="d-inline" v-slot="{ processing }">
                                <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Remove ${c.item.name}`"><Trash2 class="icon" /></Button>
                            </Form>
                        </td>
                    </tr>
                    <tr v-if="plan.charges.length === 0">
                        <td colspan="5" class="text-center text-secondary">Nothing on the bill but calls.</td>
                    </tr>
                </tbody>
                <tfoot v-if="plan.charges.length">
                    <tr class="fw-medium">
                        <td colspan="3">Each month</td>
                        <td class="text-end tabular-nums">{{ m(plan.monthly_total) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <Form
            v-bind="AccountChargeController.store.form(accountId)"
            reset-on-success
            :options="{ preserveScroll: true }"
            class="ss-grid gap-2 ss-gtc-sm-2fr-1fr-1fr-auto align-items-sm-start"
            v-slot="{ errors, processing }"
            @success="newItem = ''"
        >
            <div class="ss-grid gap-1">
                <Label for="charge_item" class="visually-hidden">Item</Label>
                <select id="charge_item" v-model="newItem" name="billing_item_id" class="form-select" required>
                    <option value="" disabled>Add an item…</option>
                    <option v-for="i in plan.items" :key="i.id" :value="i.id">{{ i.name }} ({{ Number(i.price).toFixed(2) }}{{ i.unit !== 'each' ? ` per ${i.unit}` : '' }}, {{ i.frequency === 'monthly' ? 'monthly' : 'one-time' }})</option>
                </select>
                <InputError :message="errors.billing_item_id" />
                <span v-if="plan.items.length === 0" class="small text-secondary">No <Link :href="itemsIndex()">Billing items</Link> yet.</span>
            </div>
            <div class="ss-grid gap-1">
                <Label for="charge_qty" class="visually-hidden">Quantity</Label>
                <Input id="charge_qty" name="quantity" type="number" step="0.01" min="0.01" default-value="1" required :placeholder="chosen?.unit !== 'each' ? `Qty (${chosen?.unit ?? ''})` : 'Qty'" />
                <InputError :message="errors.quantity" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="charge_price" class="visually-hidden">Own price</Label>
                <Input id="charge_price" name="price" type="number" step="0.01" min="0" :placeholder="chosen ? `Price ${Number(chosen.price).toFixed(2)}` : 'Own price (optional)'" />
                <InputError :message="errors.price" />
            </div>
            <Button :disabled="processing">Add</Button>
        </Form>

        <div v-if="plan.recent.length">
            <p class="small text-secondary mb-1">Recent plan charges</p>
            <ul class="list-group">
                <li v-for="r in plan.recent" :key="r.id" class="list-group-item d-flex justify-content-between gap-2 px-2 py-1 small">
                    <span><span class="text-secondary">{{ date(r.effective_at, timezone) }}</span> {{ r.description }}</span>
                    <span class="tabular-nums" :class="{ 'text-success': Number(r.amount) < 0 }">{{ m(r.amount) }}</span>
                </li>
            </ul>
        </div>

        <Dialog v-model:open="editOpen">
            <DialogContent>
                <Form
                    v-if="editing"
                    v-bind="AccountChargeController.update.form([accountId, editing.id])"
                    :options="{ preserveScroll: true }"
                    @success="editing = null"
                    v-slot="{ errors, processing }"
                >
                    <DialogHeader>
                        <DialogTitle>Change {{ editing.item.name }}</DialogTitle>
                        <DialogDescription v-if="editing.item.frequency === 'monthly'">
                            The difference is charged (or credited) for the rest of this month, prorated by days.
                        </DialogDescription>
                        <DialogDescription v-else>One-time items were charged when added; changing this doesn't charge again.</DialogDescription>
                    </DialogHeader>
                    <div class="ss-grid gap-3 ss-cols-2">
                        <div class="ss-grid gap-1">
                            <Label for="edit_qty">Quantity<span v-if="editing.item.unit !== 'each'"> ({{ editing.item.unit }})</span></Label>
                            <Input id="edit_qty" name="quantity" type="number" step="0.01" min="0.01" :default-value="editing.quantity" required />
                            <InputError :message="errors.quantity" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="edit_price">Own price</Label>
                            <Input id="edit_price" name="price" type="number" step="0.01" min="0" :default-value="editing.price ?? ''" placeholder="Catalog price" />
                            <InputError :message="errors.price" />
                        </div>
                    </div>
                    <DialogFooter class="mt-3">
                        <DialogClose as-child><Button variant="outline" type="button">Cancel</Button></DialogClose>
                        <Button type="submit" :disabled="processing">Save</Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </section>
</template>
