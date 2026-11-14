<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import TaxController from '@/actions/App/Http/Controllers/Admin/TaxController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/taxes';
import { edit as billingSettings } from '@/routes/billing';

type TaxRow = { id: number; name: string; rate: string; active: boolean };
type Region = { code: string; name: string; customers: number; taxes: TaxRow[] };

const props = defineProps<{ countries: { country: string; regions: Region[] }[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Taxes', href: index() }] } });

const search = ref('');
const onlyTaxed = ref(false);
const shown = computed(() =>
    props.countries.map((c) => ({
        ...c,
        regions: c.regions.filter(
            (r) =>
                (!search.value || r.name.toLowerCase().includes(search.value.toLowerCase()) || r.code.toLowerCase().includes(search.value.toLowerCase())) &&
                (!onlyTaxed.value || r.taxes.some((t) => t.active && Number(t.rate) > 0) || r.customers > 0),
        ),
    })),
);
const total = (r: Region) => r.taxes.filter((t) => t.active).reduce((s, t) => s + Number(t.rate), 0);

const editing = ref<(TaxRow & { region: string }) | null>(null);
const editOpen = computed({ get: () => editing.value !== null, set: (o: boolean) => !o && (editing.value = null) });
function remove(id: number) {
    router.delete(TaxController.destroy.url(id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
}
const adding = ref<Region | null>(null);
const addOpen = computed({ get: () => adding.value !== null, set: (o: boolean) => !o && (adding.value = null) });
</script>

<template>
    <Head title="Taxes" />

    <div class="ss-stack-4">
        <Heading title="Taxes" description="Sales taxes per province and state, charged on plan items and calls as they're billed, using the customer's tax region. Rate changes apply from the next charge." />

        <div class="alert alert-info mb-0">
            Canada is filled in with GST/HST/PST/QST rates for telecom services; US states start at 0%. Check them with your accountant, and add other
            taxes or fees (e.g. a 911 fee) where they apply. Your tax registration numbers for invoices are in
            <Link :href="billingSettings()" class="fw-medium">Settings → Billing</Link>.
        </div>

        <div class="d-flex flex-wrap align-items-center gap-3">
            <Input v-model="search" type="search" placeholder="Find a province or state…" class="ss-maxw-xs" aria-label="Find a province or state" />
            <label class="d-flex align-items-center gap-2 small">
                <input v-model="onlyTaxed" type="checkbox" class="form-check-input m-0" /> Only regions with taxes or customers
            </label>
        </div>

        <section v-for="c in shown" :key="c.country" class="card">
            <div class="card-header"><h2 class="card-title">{{ c.country }}</h2></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Region</th>
                            <th>Taxes</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Customers</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in c.regions" :key="r.code">
                            <td>
                                <span class="fw-medium">{{ r.name }}</span> <span class="small text-secondary font-monospace">{{ r.code }}</span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <button
                                        v-for="t in r.taxes"
                                        :key="t.id"
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary py-0 px-2"
                                        :class="{ 'opacity-50 text-decoration-line-through': !t.active }"
                                        :aria-label="`Edit ${t.name} for ${r.name}`"
                                        @click="editing = { ...t, region: r.name }"
                                    >
                                        {{ t.name }} {{ t.rate }}% <Pencil class="icon ms-1" />
                                    </button>
                                    <span v-if="r.taxes.length === 0" class="text-secondary small">No tax</span>
                                </div>
                            </td>
                            <td class="text-end tabular-nums">
                                <Badge v-if="total(r) > 0" variant="outline">{{ Number(total(r).toFixed(4)) }}%</Badge>
                                <span v-else class="text-secondary">0%</span>
                            </td>
                            <td class="text-end tabular-nums">{{ r.customers }}</td>
                            <td class="text-end">
                                <Button size="sm" variant="ghost" :aria-label="`Add a tax to ${r.name}`" @click="adding = r"><Plus class="icon" /></Button>
                            </td>
                        </tr>
                        <tr v-if="c.regions.length === 0">
                            <td colspan="5" class="text-center text-secondary">Nothing matches.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog v-model:open="editOpen">
            <DialogContent>
                <Form v-if="editing" v-bind="TaxController.update.form(editing.id)" :options="{ preserveScroll: true }" @success="editing = null" v-slot="{ errors, processing }">
                    <DialogHeader>
                        <DialogTitle>{{ editing.name }} — {{ editing.region }}</DialogTitle>
                        <DialogDescription>A new rate applies to charges from now on; tax already charged keeps its rate.</DialogDescription>
                    </DialogHeader>
                    <div class="ss-grid gap-3 ss-cols-2">
                        <div class="ss-grid gap-1">
                            <Label for="tax_name">Name</Label>
                            <Input id="tax_name" name="name" :default-value="editing.name" required />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="tax_rate">Rate</Label>
                            <div class="input-group">
                                <Input id="tax_rate" name="rate" type="number" step="0.001" min="0" max="100" :default-value="editing.rate" required />
                                <span class="input-group-text">%</span>
                            </div>
                            <InputError :message="errors.rate" />
                        </div>
                    </div>
                    <label class="d-flex align-items-center gap-2 mt-3">
                        <input type="hidden" name="active" value="0" />
                        <input type="checkbox" name="active" value="1" :checked="editing.active" class="form-check-input m-0" /> Charge this tax
                    </label>
                    <DialogFooter class="mt-3">
                        <Button type="button" variant="ghost" class="text-danger me-auto" @click="remove(editing.id)"><Trash2 class="icon" /> Remove</Button>
                        <DialogClose as-child><Button variant="outline" type="button">Cancel</Button></DialogClose>
                        <Button type="submit" :disabled="processing">Save</Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="addOpen">
            <DialogContent>
                <Form v-if="adding" v-bind="TaxController.store.form()" :options="{ preserveScroll: true }" @success="adding = null" v-slot="{ errors, processing }">
                    <input type="hidden" name="region" :value="adding.code" />
                    <DialogHeader>
                        <DialogTitle>Add a tax to {{ adding.name }}</DialogTitle>
                        <DialogDescription>Charged on top of the region's other taxes, on plan items and calls.</DialogDescription>
                    </DialogHeader>
                    <div class="ss-grid gap-3 ss-cols-2">
                        <div class="ss-grid gap-1">
                            <Label for="new_tax_name">Name</Label>
                            <Input id="new_tax_name" name="name" required placeholder="Sales tax, 911 fee…" />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="new_tax_rate">Rate</Label>
                            <div class="input-group">
                                <Input id="new_tax_rate" name="rate" type="number" step="0.001" min="0" max="100" required />
                                <span class="input-group-text">%</span>
                            </div>
                            <InputError :message="errors.rate" />
                        </div>
                    </div>
                    <DialogFooter class="mt-3">
                        <DialogClose as-child><Button variant="outline" type="button">Cancel</Button></DialogClose>
                        <Button type="submit" :disabled="processing">Add tax</Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
