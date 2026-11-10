<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DidController from '@/actions/App/Http/Controllers/Admin/DidController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DestinationPicker from '@/components/pbx/DestinationPicker.vue';
import type { Destination, PbxEndpoint, PbxNumber } from '@/components/pbx/DestinationPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { create as createEndpoint } from '@/routes/endpoints';
import { index } from '@/routes/dids';

type Account = { id: number; name: string; code: string };
type Did = {
    id: number;
    account_id: number;
    provider_account_id: number | null;
    number: string;
    destination: Destination;
    language: string;
    status: 'active' | 'deactivated' | 'banned';
    status_reason: string | null;
    description: string | null;
};

const props = defineProps<{
    did: Did | null;
    defaults: { account_id?: number | null; language?: string };
    customers: Account[];
    providers: Account[];
    languages: { code: string; name: string; top: boolean }[];
    statuses: Record<string, string>;
    targets: Record<string, { endpoints: PbxEndpoint[]; numbers: PbxNumber[] }>;
}>();

defineOptions({
    layout: (p: { did: Did | null }) => ({
        breadcrumbs: [
            { title: 'DIDs', href: index() },
            { title: p.did ? p.did.number : 'New DID', href: index() },
        ],
    }),
});

const account = ref<number | ''>(props.did?.account_id ?? props.defaults.account_id ?? '');
const status = ref(props.did?.status ?? 'active');
const target = computed(() => props.targets[String(account.value)] ?? { endpoints: [], numbers: [] });
const hasTargets = computed(() => target.value.endpoints.length > 0 || target.value.numbers.length > 0);
// New DIDs go to a PBX/Device when the customer has one.
const initial = computed<Destination>(() =>
    props.did && props.did.account_id === account.value ? props.did.destination : target.value.endpoints.length ? { type: 'endpoint' } : { type: 'number' },
);
const topLanguages = props.languages.filter((l) => l.top);
const otherLanguages = props.languages.filter((l) => !l.top);
const action = props.did ? DidController.update.form(props.did.id) : DidController.store.form();
</script>

<template>
    <Head :title="did ? `DID ${did.number}` : 'New DID'" />

    <div class="ss-maxw-3xl ss-stack-4">
        <Heading :title="did ? `DID ${did.number}` : 'New DID'" description="A number a provider delivers to this switch, and where its calls go." />

        <Form v-bind="action" class="ss-stack-4" v-slot="{ errors, processing }">
            <section class="ss-grid gap-3 ss-cols-sm-2">
                <div class="ss-grid gap-1">
                    <Label for="number">DID</Label>
                    <Input id="number" name="number" :default-value="did?.number ?? ''" required inputmode="numeric" class="font-monospace" placeholder="14165551234" />
                    <p class="small text-secondary mb-0">The full number with country code, as the provider delivers it.</p>
                    <InputError :message="errors.number" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="provider_account_id">Provider</Label>
                    <select id="provider_account_id" name="provider_account_id" :class="selectClass" required>
                        <option value="" :selected="!did?.provider_account_id" disabled>Choose the carrier…</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id" :selected="p.id === did?.provider_account_id">{{ p.name }} ({{ p.code }})</option>
                    </select>
                    <InputError :message="errors.provider_account_id" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="account_id">Customer</Label>
                    <select id="account_id" v-model="account" name="account_id" :class="selectClass" required>
                        <option value="" disabled>Choose…</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                    </select>
                    <InputError :message="errors.account_id" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="language">Language</Label>
                    <select id="language" name="language" :class="selectClass">
                        <option v-for="l in topLanguages" :key="l.code" :value="l.code" :selected="l.code === (did?.language ?? defaults.language)">
                            {{ l.code.toUpperCase() }} — {{ l.name }}
                        </option>
                        <option disabled>──────────</option>
                        <option v-for="l in otherLanguages" :key="l.code" :value="l.code" :selected="l.code === did?.language">{{ l.code.toUpperCase() }} — {{ l.name }}</option>
                    </select>
                    <InputError :message="errors.language" />
                </div>
            </section>

            <section class="ss-grid gap-1">
                <Label for="destination">Send calls to</Label>
                <template v-if="account === ''">
                    <p class="text-secondary mb-0">Choose the customer first.</p>
                </template>
                <template v-else-if="hasTargets">
                    <DestinationPicker id="destination" :key="String(account)" name="destination" :numbers="target.numbers" :endpoints="target.endpoints" :value="initial" show-ids />
                    <p class="small text-secondary mb-0">
                        One of the customer's PBX/Devices, or an extension, IVR or voicemail on this switch's PBX.
                    </p>
                </template>
                <p v-else class="text-secondary mb-0">
                    This customer has no PBX/Devices or PBX numbers yet.
                    <Link :href="createEndpoint({ query: { account: account } })" class="fw-medium">Add a PBX/Device</Link> first.
                </p>
                <InputError :message="errors['destination.type']" />
            </section>

            <section class="ss-stack-2">
                <fieldset class="ss-grid gap-1">
                    <legend class="fw-medium fs-5 mb-1">Status</legend>
                    <label class="d-flex align-items-start gap-2">
                        <input v-model="status" type="radio" name="status" value="active" class="form-check-input m-0 mt-1" />
                        <span>Active <span class="d-block small text-secondary">Calls are delivered.</span></span>
                    </label>
                    <label class="d-flex align-items-start gap-2">
                        <input v-model="status" type="radio" name="status" value="deactivated" class="form-check-input m-0 mt-1" />
                        <span>Deactivated <span class="d-block small text-secondary">Calls are refused as "not found" (404).</span></span>
                    </label>
                    <label class="d-flex align-items-start gap-2">
                        <input v-model="status" type="radio" name="status" value="banned" class="form-check-input m-0 mt-1" />
                        <span>Banned <span class="d-block small text-secondary">Calls are refused (403), e.g. while the customer hasn't paid.</span></span>
                    </label>
                </fieldset>
                <div v-if="status !== 'active'" class="ss-grid gap-1">
                    <Label for="status_reason">Reason <span v-if="status !== 'banned'" class="small text-secondary">(optional)</span></Label>
                    <Input id="status_reason" name="status_reason" :default-value="did?.status_reason ?? ''" :required="status === 'banned'" placeholder="Unpaid invoice #1042" />
                    <InputError :message="errors.status_reason" />
                </div>
            </section>

            <div class="ss-grid gap-1">
                <Label for="description">Comment</Label>
                <Input id="description" name="description" :default-value="did?.description ?? ''" maxlength="255" placeholder="Main line" />
                <InputError :message="errors.description" />
            </div>

            <div class="d-flex gap-2">
                <Button :disabled="processing">{{ did ? 'Save' : 'Add DID' }}</Button>
                <Button variant="ghost" as-child><Link :href="index()">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
