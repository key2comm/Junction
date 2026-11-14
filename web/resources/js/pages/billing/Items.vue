<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Pencil, Trash2 } from '@lucide/vue';
import BillingItemController from '@/actions/App/Http/Controllers/Admin/BillingItemController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/billing-items';

type Item = {
    id: number;
    name: string;
    description: string | null;
    unit: string;
    price: string;
    frequency: 'monthly' | 'once';
    active: boolean;
    customers: number;
    profiles: number;
};

defineProps<{ items: Item[]; frequencies: Record<string, string> }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Billing items', href: index() }] } });

const page = usePage();
const pageErrors = computed(() => page.props.errors as Record<string, string>);
const editing = ref<Item | null>(null);
const editOpen = computed({ get: () => editing.value !== null, set: (open: boolean) => !open && (editing.value = null) });
// Prices are charged in each customer's own currency.
const price = (v: string) => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
const per = (i: Pick<Item, 'unit' | 'frequency'>) => [i.unit !== 'each' ? i.unit : null, i.frequency === 'monthly' ? 'per month' : 'one-time'].filter(Boolean).join(', ');
</script>

<template>
    <Head title="Billing items" />

    <div class="ss-stack-4">
        <Heading
            title="Billing items"
            description="Things you can put on any customer's bill, alone or in a Billing profile: hosting packages, per-extension plans, DIDs, setup fees. Prices are in each customer's own currency."
        />

        <p v-if="pageErrors.item" class="alert alert-danger mb-0">{{ pageErrors.item }}</p>

        <Form v-bind="BillingItemController.store.form()" reset-on-success class="card card-body ss-stack-3" v-slot="{ errors, processing }">
            <h2 class="fs-4 fw-semibold mb-0">Add an item</h2>
            <div class="ss-grid gap-3 ss-cols-md-2 ss-cols-xl-4">
                <div class="ss-grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" required placeholder="Unlimited hosting package" />
                    <InputError :message="errors.name" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="price">Price</Label>
                    <Input id="price" name="price" type="number" step="0.01" min="0" required placeholder="25.00" />
                    <InputError :message="errors.price" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="unit">Per</Label>
                    <Input id="unit" name="unit" placeholder="ext, DID, seat… (blank: each)" />
                    <InputError :message="errors.unit" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="frequency">Charged</Label>
                    <select id="frequency" name="frequency" class="form-select">
                        <option v-for="(label, key) in frequencies" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <InputError :message="errors.frequency" />
                </div>
                <div class="ss-grid gap-1 ss-span-md-2 ss-span-xl-4">
                    <Label for="description">Description <span class="text-secondary fw-normal">(optional)</span></Label>
                    <Input id="description" name="description" placeholder="Unlimited Canada/USA calling, voicemail, auto attendant" />
                    <InputError :message="errors.description" />
                </div>
            </div>
            <div>
                <Button :disabled="processing">Add item</Button>
            </div>
        </Form>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Price</th>
                        <th>Charged</th>
                        <th class="text-end">Customers</th>
                        <th class="text-end">Profiles</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in items" :key="i.id" :class="{ 'opacity-50': !i.active }">
                        <td>
                            <div class="fw-medium">{{ i.name }} <Badge v-if="!i.active" variant="secondary">inactive</Badge></div>
                            <div v-if="i.description" class="small text-secondary">{{ i.description }}</div>
                        </td>
                        <td class="text-end tabular-nums">{{ price(i.price) }}</td>
                        <td>{{ per(i) }}</td>
                        <td class="text-end tabular-nums">{{ i.customers }}</td>
                        <td class="text-end tabular-nums">{{ i.profiles }}</td>
                        <td class="text-end text-nowrap">
                            <Button size="sm" variant="ghost" :aria-label="`Edit ${i.name}`" @click="editing = { ...i }"><Pencil class="icon" /></Button>
                            <Form v-if="i.customers === 0 && i.profiles === 0" v-bind="BillingItemController.destroy.form(i.id)" class="d-inline" v-slot="{ processing }">
                                <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Delete ${i.name}`"><Trash2 class="icon" /></Button>
                            </Form>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0">
                        <td colspan="6" class="text-center text-secondary py-4">No items yet. Add your first one above.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog v-model:open="editOpen">
            <DialogContent>
                <Form v-if="editing" v-bind="BillingItemController.update.form(editing.id)" :options="{ preserveScroll: true }" @success="editing = null" v-slot="{ errors, processing }">
                    <DialogHeader>
                        <DialogTitle>Edit {{ editing.name }}</DialogTitle>
                        <DialogDescription>
                            A new price applies from the next charge, for every customer who pays the catalog price. Customers with their own price keep it.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="ss-stack-3">
                        <div class="ss-grid gap-1">
                            <Label for="edit_name">Name</Label>
                            <Input id="edit_name" name="name" :default-value="editing.name" required />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="ss-grid gap-3 ss-cols-2">
                            <div class="ss-grid gap-1">
                                <Label for="edit_price">Price</Label>
                                <Input id="edit_price" name="price" type="number" step="0.01" min="0" :default-value="editing.price" required />
                                <InputError :message="errors.price" />
                            </div>
                            <div class="ss-grid gap-1">
                                <Label for="edit_unit">Per</Label>
                                <Input id="edit_unit" name="unit" :default-value="editing.unit === 'each' ? '' : editing.unit" placeholder="each" />
                            </div>
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="edit_frequency">Charged</Label>
                            <select id="edit_frequency" name="frequency" class="form-select">
                                <option v-for="(label, key) in frequencies" :key="key" :value="key" :selected="key === editing.frequency">{{ label }}</option>
                            </select>
                        </div>
                        <div class="ss-grid gap-1">
                            <Label for="edit_description">Description</Label>
                            <Input id="edit_description" name="description" :default-value="editing.description ?? ''" />
                        </div>
                        <label class="d-flex align-items-center gap-2">
                            <input type="hidden" name="active" value="0" />
                            <input type="checkbox" name="active" value="1" :checked="editing.active" class="form-check-input m-0" />
                            Active (offered when adding items to a bill)
                        </label>
                    </div>
                    <DialogFooter class="mt-3">
                        <DialogClose as-child><Button variant="outline" type="button">Cancel</Button></DialogClose>
                        <Button type="submit" :disabled="processing">Save</Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
