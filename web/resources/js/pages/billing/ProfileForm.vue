<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Plus, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as itemsIndex } from '@/routes/billing-items';
import { destroy, index, store, update } from '@/routes/billing-profiles';

type Item = { id: number; name: string; unit: string; price: string; frequency: 'monthly' | 'once'; active: boolean };
type Line = { billing_item_id: number | ''; quantity: string; price: string };

const props = defineProps<{
    plans: Record<string, string>;
    items: Item[];
    profile: {
        id: number;
        name: string;
        description: string | null;
        nanp_plan: string;
        nanp_minutes: number | null;
        accounts_count: number;
        items: Line[];
    } | null;
}>();

defineOptions({
    layout: (p: { profile: { name: string } | null }) => ({
        breadcrumbs: [{ title: 'Billing profiles', href: index() }, { title: p.profile ? p.profile.name : 'New profile', href: '#' }],
    }),
});

const form = useForm({
    name: props.profile?.name ?? '',
    description: props.profile?.description ?? '',
    nanp_plan: props.profile?.nanp_plan ?? 'unlimited',
    nanp_minutes: props.profile?.nanp_minutes ? String(props.profile.nanp_minutes) : '',
    items: (props.profile?.items ?? []) as Line[],
});

const byId = computed(() => new Map(props.items.map((i) => [i.id, i])));
const choosable = computed(() => props.items.filter((i) => i.active || form.items.some((l) => l.billing_item_id === i.id)));
const errors = computed(() => form.errors as Record<string, string | undefined>);

function addLine() {
    form.items.push({ billing_item_id: '', quantity: '1', price: '' });
}
function lineTotal(l: Line) {
    const item = l.billing_item_id === '' ? undefined : byId.value.get(l.billing_item_id);
    const price = l.price !== '' ? Number(l.price) : Number(item?.price ?? 0);

    return (Number(l.quantity) || 0) * price;
}
const monthly = computed(() =>
    form.items.reduce((t, l) => (l.billing_item_id !== '' && byId.value.get(l.billing_item_id)?.frequency === 'monthly' ? t + lineTotal(l) : t), 0),
);
const fmt = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function submit() {
    if (props.profile) {
        form.put(update(props.profile.id).url);
    } else {
        form.post(store().url);
    }
}
const confirmDelete = ref(false);
function remove() {
    if (props.profile) {
        router.delete(destroy(props.profile.id).url);
    }
}
</script>

<template>
    <Head :title="profile ? profile.name : 'New billing profile'" />

    <form class="ss-stack-4 ss-maxw-4xl" @submit.prevent="submit">
        <Heading
            :title="profile ? `Edit ${profile.name}` : 'New billing profile'"
            :description="
                profile && profile.accounts_count
                    ? `${profile.accounts_count} customer(s) are on this profile. A new Canada/USA plan applies to them straight away; item changes apply to customers given the profile from now on (each customer's own items are changed on their page).`
                    : 'Choose how Canada/USA calls are charged and which items go on the bill.'
            "
        />

        <section class="card card-body ss-stack-3">
            <div class="ss-grid gap-3 ss-cols-md-2">
                <div class="ss-grid gap-1">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" required placeholder="Hosted PBX, unlimited" />
                    <InputError :message="errors.name" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="description">Description <span class="text-secondary fw-normal">(optional)</span></Label>
                    <Input id="description" v-model="form.description" />
                    <InputError :message="errors.description" />
                </div>
            </div>
        </section>

        <section class="card card-body ss-stack-3">
            <h2 class="fs-4 fw-semibold mb-0">Canada/USA calls</h2>
            <div class="ss-stack-2">
                <label v-for="(label, key) in plans" :key="key" class="d-flex align-items-start gap-2">
                    <input v-model="form.nanp_plan" type="radio" name="nanp_plan" :value="key" class="form-check-input mt-1" />
                    <span>
                        <span class="fw-medium">{{ label }}</span>
                        <span class="d-block small text-secondary">
                            <template v-if="key === 'metered'">Every call pays the customer's tariff rate.</template>
                            <template v-else-if="key === 'unlimited'">Calls to Canada and the USA cost nothing. International calls pay the tariff rate.</template>
                            <template v-else>A number of Canada/USA minutes each month (shared by the whole account), then the tariff rate. International calls pay the tariff rate.</template>
                        </span>
                    </span>
                </label>
            </div>
            <div v-if="form.nanp_plan === 'bundle'" class="ss-grid gap-1 ss-maxw-xs">
                <Label for="nanp_minutes">Included minutes a month</Label>
                <Input id="nanp_minutes" v-model="form.nanp_minutes" type="number" min="1" required placeholder="5000" />
                <InputError :message="errors.nanp_minutes" />
            </div>
            <p class="small text-secondary mb-0">Canada/USA means +1 numbers except the Caribbean countries, which are international. Months follow each customer's time zone.</p>
        </section>

        <section class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Items</h2>
                    <p class="card-subtitle mb-0">
                        From <Link :href="itemsIndex()">Billing items</Link>. Leave the price blank to use the catalog price.
                    </p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="ss-w-24">Qty</th>
                            <th class="ss-w-32">Price</th>
                            <th class="text-end">Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in form.items" :key="i">
                            <td>
                                <select v-model="l.billing_item_id" class="form-select" :aria-label="`Item ${i + 1}`" required>
                                    <option value="" disabled>Choose an item…</option>
                                    <option v-for="it in choosable" :key="it.id" :value="it.id">
                                        {{ it.name }} ({{ Number(it.price).toFixed(2) }}{{ it.unit !== 'each' ? ` per ${it.unit}` : '' }}, {{ it.frequency === 'monthly' ? 'monthly' : 'one-time' }})
                                    </option>
                                </select>
                                <InputError :message="errors[`items.${i}.billing_item_id`]" />
                            </td>
                            <td>
                                <Input v-model="l.quantity" type="number" step="0.01" min="0.01" required :aria-label="`Quantity ${i + 1}`" />
                                <InputError :message="errors[`items.${i}.quantity`]" />
                            </td>
                            <td>
                                <Input
                                    v-model="l.price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :placeholder="l.billing_item_id !== '' ? Number(byId.get(l.billing_item_id)?.price ?? 0).toFixed(2) : ''"
                                    :aria-label="`Price ${i + 1}`"
                                />
                                <InputError :message="errors[`items.${i}.price`]" />
                            </td>
                            <td class="text-end tabular-nums">{{ fmt(lineTotal(l)) }}</td>
                            <td class="text-end">
                                <Button type="button" size="sm" variant="ghost" :aria-label="`Remove item ${i + 1}`" @click="form.items.splice(i, 1)"><Trash2 class="icon" /></Button>
                            </td>
                        </tr>
                        <tr v-if="form.items.length === 0">
                            <td colspan="5" class="text-center text-secondary">No items: this profile only sets how calls are charged.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="form.items.length">
                        <tr class="fw-medium">
                            <td colspan="3">Monthly total</td>
                            <td class="text-end tabular-nums">{{ fmt(monthly) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-body">
                <Button type="button" variant="outline" size="sm" :disabled="items.length === 0" @click="addLine"><Plus class="icon" /> Add item</Button>
                <span v-if="items.length === 0" class="ms-2 small text-secondary">Create <Link :href="itemsIndex()">Billing items</Link> first.</span>
            </div>
        </section>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <Button type="submit" :disabled="form.processing">{{ profile ? 'Save' : 'Create profile' }}</Button>
            <Button variant="outline" as-child><Link :href="index()">Cancel</Link></Button>
            <template v-if="profile && profile.accounts_count === 0">
                <Button v-if="!confirmDelete" type="button" variant="ghost" class="ms-auto text-danger" @click="confirmDelete = true"><Trash2 class="icon" /> Delete</Button>
                <Button v-else type="button" variant="destructive" class="ms-auto" @click="remove">Delete {{ profile.name }}?</Button>
            </template>
        </div>
    </form>
</template>
