<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import TrunkController from '@/actions/App/Http/Controllers/Admin/TrunkController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { index, show } from '@/routes/trunks';

type Trunk = {
    id: number;
    account_id: number;
    name: string;
    rate_deck_id: number | null;
    enabled: boolean;
    max_channels: number | null;
    max_cps: string | null;
    tech_prefix: string;
    strip_digits: number;
};

const props = defineProps<{
    trunk: Trunk | null;
    defaults: { account_id?: number | null };
    vendors: { id: number; name: string; code: string }[];
    rateDecks: { id: number; name: string; currency: string }[];
}>();

defineOptions({
    layout: (props: { trunk: { id: number; name: string } | null }) => ({
        breadcrumbs: [
            { title: 'Trunks', href: index() },
            props.trunk ? { title: props.trunk.name, href: show(props.trunk.id) } : { title: 'New', href: index() },
        ],
    }),
});

const t = props.trunk;
const action = t ? TrunkController.update.form(t.id) : TrunkController.store.form();
const vendorId = t?.account_id ?? props.defaults.account_id ?? null;
</script>

<template>
    <Head :title="t ? `Edit ${t.name}` : 'New trunk'" />

    <div class="ss-maxw-2xl ss-stack-4">
        <Heading :title="t ? `Edit ${t.name}` : 'New trunk'" description="A carrier product: what we pay (carrier rates) and where we send calls (gateways)" />

        <p v-if="vendors.length === 0" class="rounded border ss-border-dashed p-3 text-secondary">
            There are no carriers yet. Add one under Routing → Carriers first.
        </p>

        <Form v-else v-bind="action" class="ss-grid gap-4 ss-cols-sm-2" v-slot="{ errors, processing }">
            <div class="ss-grid gap-2">
                <Label for="account_id">Carrier</Label>
                <select id="account_id" name="account_id" :class="selectClass" required>
                    <option value="" disabled :selected="!vendorId">Choose a carrier</option>
                    <option v-for="v in vendors" :key="v.id" :value="v.id" :selected="v.id === vendorId">{{ v.name }} ({{ v.code }})</option>
                </select>
                <InputError :message="errors.account_id" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="name">Trunk name</Label>
                <Input id="name" name="name" :default-value="t?.name" required placeholder="Premium CLI" />
                <InputError :message="errors.name" />
            </div>
            <div class="ss-grid gap-2 ss-span-sm-2">
                <Label for="rate_deck_id">Carrier rates</Label>
                <select id="rate_deck_id" name="rate_deck_id" :class="selectClass">
                    <option value="">— None —</option>
                    <option v-for="d in rateDecks" :key="d.id" :value="d.id" :selected="d.id === t?.rate_deck_id">{{ d.name }} ({{ d.currency }})</option>
                </select>
                <p class="small text-secondary">What this carrier charges us. Least-cost routing skips trunks without a rate for the destination.</p>
                <InputError :message="errors.rate_deck_id" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="max_channels">Max concurrent calls</Label>
                <Input id="max_channels" name="max_channels" type="number" min="1" :default-value="t?.max_channels ?? ''" placeholder="Unlimited" />
                <InputError :message="errors.max_channels" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="max_cps">Max calls per second</Label>
                <Input id="max_cps" name="max_cps" type="number" step="0.1" min="0.1" :default-value="t?.max_cps ?? ''" placeholder="Unlimited" />
                <InputError :message="errors.max_cps" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="strip_digits">Strip leading digits</Label>
                <Input id="strip_digits" name="strip_digits" type="number" min="0" max="15" :default-value="t?.strip_digits ?? 0" />
                <InputError :message="errors.strip_digits" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="tech_prefix">Tech prefix to add</Label>
                <Input id="tech_prefix" name="tech_prefix" :default-value="t?.tech_prefix ?? ''" placeholder="e.g. 1234#" class="font-monospace" />
                <p class="small text-secondary">Sent in front of the number, after stripping.</p>
                <InputError :message="errors.tech_prefix" />
            </div>
            <label class="d-flex align-items-center gap-2 ss-span-sm-2">
                <input type="checkbox" name="enabled" value="1" :checked="t?.enabled ?? true" class="form-check-input m-0" /> Enabled
            </label>
            <div class="d-flex gap-2 ss-span-sm-2">
                <Button :disabled="processing">{{ t ? 'Save changes' : 'Create trunk' }}</Button>
                <Button variant="ghost" as-child><Link :href="t ? show(t.id) : index()">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
