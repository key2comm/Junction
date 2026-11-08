<script setup lang="ts">
import { Form, Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import RateDeckController from '@/actions/App/Http/Controllers/Admin/RateDeckController';
import RateImportController from '@/actions/App/Http/Controllers/Admin/RateImportController';
import TariffController from '@/actions/App/Http/Controllers/Admin/TariffController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import RateImportMapping from '@/components/rate-decks/RateImportMapping.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dateTime, money } from '@/lib/format';
import { deckKind } from '@/lib/rateDecks';
import type { Paginated } from '@/lib/ui';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { edit, show } from '@/routes/rate-decks';
import { show as showTrunk } from '@/routes/trunks';

type Rate = {
    id: number;
    prefix: string;
    destination: string | null;
    rate: string;
    connect_fee: string;
    initial_increment: number;
    increment: number;
    effective_from: string;
    effective_to: string | null;
};

type Import = {
    id: number;
    filename: string;
    mode: string;
    status: 'queued' | 'running' | 'done' | 'failed';
    stats: Record<string, number> | null;
    errors: string[] | null;
    effective_from: string;
    created_at: string;
    user: string | null;
};

const props = defineProps<{
    deck: { id: number; name: string; kind: 'tariff' | 'carrier'; currency: string; description: string | null };
    rates: Paginated<Rate>;
    filters: { q: string; when: string };
    imports: Import[];
    importing: boolean;
    usage: { customers: { id: number; name: string; code: string }[]; trunks: { id: number; name: string; account: { code: string } }[] };
    mapping: {
        id: number;
        filename: string;
        preview: { header: string[]; rows: string[][]; suggested: Record<string, number | null> };
        fields: Record<string, string>;
    } | null;
    build: {
        sources: number[];
        markup_type: 'percent' | 'fixed';
        markup_value: string | null;
        built_at: string | null;
        carrier_rates: { id: number; name: string; currency: string }[];
    } | null;
}>();

defineOptions({
    layout: (props: { deck: { id: number; name: string; kind: 'tariff' | 'carrier' } }) => ({
        breadcrumbs: [
            { title: deckKind(props.deck.kind).plural, href: deckKind(props.deck.kind).index() },
            { title: props.deck.name, href: show(props.deck.id) },
        ],
    }),
});

const { start, stop } = usePoll(2000, { only: ['imports', 'importing', 'rates'] }, { autoStart: false });
watch(() => props.importing, (busy) => (busy ? start() : stop()), { immediate: true });

const filters = reactive({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;
function apply(delay = 0) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(show.url(props.deck.id, { query: { q: filters.q || undefined, when: filters.when } }), {}, { preserveState: true, replace: true, only: ['rates', 'filters'] });
    }, delay);
}

const increments = (r: Rate) => `${r.initial_increment}/${r.increment}`;

// Tariffs: markup choice on the build form.
const markupType = ref<'percent' | 'fixed'>(props.build?.markup_type ?? 'percent');
// Returning false cancels the visit.
const clearOpen = ref(false);
const clearConfirm = ref('');
watch(clearOpen, (open) => {
    if (!open) clearConfirm.value = '';
});
const rebuildOk = () => !props.build?.built_at || confirm('Rebuild this tariff? Every rate is replaced, including any you changed by hand.');

// Changing one prefix's rate by hand (tariffs).
const editing = ref<number | null>(null);
const editRate = ref('');
const editError = ref<string | null>(null);
const ended = (r: Rate) => r.effective_to !== null && new Date(r.effective_to) <= new Date();
function startEdit(r: Rate) {
    editing.value = r.id;
    editRate.value = String(Number(r.rate));
    editError.value = null;
}
function saveEdit(r: Rate) {
    router.patch(
        TariffController.updateRate.url([props.deck.id, r.id]),
        { rate: editRate.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['rates'],
            onSuccess: () => (editing.value = null),
            onError: (errors) => (editError.value = errors.rate ?? 'Could not save.'),
        },
    );
}
</script>

<template>
    <Head :title="deck.name" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading :title="deck.name" :description="deck.description ?? `Rates in ${deck.currency} per minute`" />
            <div class="d-flex gap-2">
                <Button variant="outline" as-child><Link :href="edit(deck.id)"><Pencil class="icon" /> Edit</Link></Button>
                <Dialog v-model:open="clearOpen">
                    <DialogTrigger as-child>
                        <Button variant="outline" class="text-danger" :disabled="importing || rates.total === 0"><Trash2 class="icon" /> Delete all rates</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="RateDeckController.clearRates.form(deck.id)"
                            :options="{ preserveScroll: true }"
                            @success="clearOpen = false"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>Delete all {{ rates.total.toLocaleString() }} rates from {{ deck.name }}?</DialogTitle>
                                <DialogDescription>
                                    Every rate in this list is deleted, including past and scheduled ones. This cannot be undone. The list itself stays, so you can
                                    import a new file afterwards.
                                </DialogDescription>
                            </DialogHeader>
                            <div v-if="deck.kind === 'carrier' && usage.trunks.length" class="alert alert-warning">
                                Until new rates are imported, calls can't be sent to
                                {{ usage.trunks.map((t) => t.name).join(', ') }}: a carrier with no rate for a number is skipped.
                            </div>
                            <div v-if="deck.kind === 'tariff' && usage.customers.length" class="alert alert-warning">
                                Until new rates are added, calls from {{ usage.customers.map((c) => c.name).join(', ') }} will be rejected: there is no price for them.
                            </div>
                            <div class="ss-grid gap-1">
                                <Label for="clear_confirm">Type <strong class="user-select-all">{{ deck.name }}</strong> to confirm</Label>
                                <Input id="clear_confirm" v-model="clearConfirm" name="confirm" autocomplete="off" />
                                <InputError :message="errors.confirm" />
                            </div>
                            <DialogFooter>
                                <DialogClose as-child><Button variant="outline">Cancel</Button></DialogClose>
                                <Button type="submit" variant="destructive" :disabled="processing || clearConfirm.trim() !== deck.name.trim()">Delete all rates</Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <!-- Tariffs: build from Carrier Rates plus a markup -->
        <section v-if="build" class="ss-stack-3 rounded border p-3">
            <Heading
                variant="small"
                title="Build from Carrier Rates"
                :description="
                    build.built_at
                        ? `Last built ${dateTime(build.built_at)}. Rebuild after carrier rates change; this replaces every rate here, including ones you changed by hand.`
                        : 'Work out this tariff\'s prices from your Carrier Rates plus a markup. They are only used to calculate prices: the tariff isn\'t tied to any carrier, and calls still go out through the customer\'s LCR group, cheapest carrier first. Each prefix is priced from the most expensive carrier rate, so every carrier in the group stays profitable.'
                "
            />
            <p v-if="build.carrier_rates.length === 0" class="text-secondary">There are no Carrier Rates yet. Create them under Routing → Carrier Rates first.</p>
            <Form v-else v-bind="TariffController.build.form(deck.id)" class="ss-grid gap-3 ss-cols-md-2" v-slot="{ errors, processing }" :on-before="rebuildOk">
                <fieldset class="ss-grid gap-1">
                    <legend class="fw-medium fs-5 mb-1">Price from these Carrier Rates</legend>
                    <label v-for="c in build.carrier_rates" :key="c.id" class="d-flex align-items-center gap-2" :class="{ 'text-secondary': c.currency !== deck.currency }">
                        <input type="checkbox" name="sources[]" :value="c.id" :checked="build.sources.includes(c.id)" :disabled="c.currency !== deck.currency" class="form-check-input m-0" />
                        {{ c.name }} <span class="small text-secondary">{{ c.currency }}</span>
                    </label>
                    <InputError :message="errors.sources ?? errors['sources.0']" />
                </fieldset>
                <div class="ss-stack-2">
                    <fieldset class="ss-grid gap-1">
                        <legend class="fw-medium fs-5 mb-1">Markup</legend>
                        <label class="d-flex align-items-center gap-2">
                            <input v-model="markupType" type="radio" name="markup_type" value="percent" class="form-check-input m-0" /> Percentage of the carrier rate
                        </label>
                        <label class="d-flex align-items-center gap-2">
                            <input v-model="markupType" type="radio" name="markup_type" value="fixed" class="form-check-input m-0" /> Fixed amount added per minute
                        </label>
                    </fieldset>
                    <div class="d-flex align-items-center gap-2">
                        <span v-if="markupType === 'fixed'" class="text-secondary">{{ deck.currency }}</span>
                        <Input
                            name="markup_value"
                            type="number"
                            min="0"
                            :step="markupType === 'percent' ? '0.01' : '0.0001'"
                            :default-value="build.markup_value !== null ? String(Number(build.markup_value)) : ''"
                            :placeholder="markupType === 'percent' ? '15' : '0.10'"
                            required
                            class="ss-w-32"
                            aria-label="Markup"
                        />
                        <span class="text-secondary">{{ markupType === 'percent' ? '%' : 'per minute' }}</span>
                    </div>
                    <InputError :message="errors.markup_value" />
                    <div class="ss-grid gap-1">
                        <Label for="build_effective">Effective from (UTC)</Label>
                        <Input id="build_effective" name="effective_from" type="datetime-local" class="ss-w-64" />
                        <p class="small text-secondary">Blank = immediately.</p>
                    </div>
                    <div>
                        <Button :disabled="processing || importing">{{ build.built_at ? 'Rebuild tariff' : 'Build tariff' }}</Button>
                    </div>
                </div>
            </Form>
        </section>

        <div class="ss-grid gap-4 ss-gtc-lg-2fr-1fr">
            <!-- Import -->
            <section class="ss-stack-3">
                <Heading
                    variant="small"
                    title="Import rates"
                    description="Upload a CSV file. Next you choose which column is the Prefix, NPA, NXX, Interval, Rate and Date effective, and see a preview before anything is imported."
                />
                <RateImportMapping v-if="mapping" :key="mapping.id" :deck-id="deck.id" :mapping="mapping" />
                <Form
                    v-else
                    v-bind="RateImportController.store.form(deck.id)"
                    reset-on-success
                    class="d-flex flex-wrap align-items-end gap-2"
                    v-slot="{ errors, processing, progress }"
                >
                    <div class="ss-grid gap-2">
                        <Label for="file">CSV file</Label>
                        <Input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required />
                        <progress v-if="progress" :value="progress.percentage" max="100" class="w-100" />
                        <InputError :message="errors.file" />
                    </div>
                    <Button :disabled="processing || importing">Upload and choose columns</Button>
                </Form>

                <ul v-if="imports.length" class="list-group">
                    <li v-for="i in imports" :key="i.id" class="ss-stack-1 px-2 py-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <Spinner v-if="i.status === 'queued' || i.status === 'running'" />
                            <Badge :variant="i.status === 'failed' ? 'destructive' : i.status === 'done' ? 'default' : 'secondary'">{{ i.status }}</Badge>
                            <span class="fw-medium">{{ i.filename }}</span>
                            <span class="text-secondary">{{ i.mode === 'build' ? 'built' : i.mode }}, effective {{ dateTime(i.effective_from) }}, by {{ i.user ?? 'unknown' }}</span>
                        </div>
                        <p v-if="i.status === 'done' && i.stats" class="text-secondary">
                            {{ i.stats.added }} added · {{ i.stats.changed }} changed · {{ i.stats.unchanged }} unchanged<span v-if="i.mode !== 'update'"> · {{ i.stats.ended }} ended</span
                            ><span v-if="i.stats.duplicates_skipped"> · {{ i.stats.duplicates_skipped }} duplicate row(s) skipped</span>
                            <span v-if="i.stats.increases_within_notice" class="text-danger">
                                · {{ i.stats.increases_within_notice }} increase(s) take effect in under 7 days
                            </span>
                        </p>
                        <ul v-if="i.status === 'failed' && i.errors" class="text-danger">
                            <li v-for="(e, n) in i.errors" :key="n">{{ e }}</li>
                        </ul>
                    </li>
                </ul>
            </section>

            <!-- Usage -->
            <section class="ss-stack-3">
                <Heading variant="small" title="Used by" />
                <div v-if="deck.kind === 'tariff'">
                    <p class="text-secondary">Customers</p>
                    <ul>
                        <li v-for="c in usage.customers" :key="c.id"><Link :href="showAccount(c.id)">{{ c.name }}</Link></li>
                        <li v-if="!usage.customers.length" class="text-secondary">—</li>
                    </ul>
                </div>
                <div v-else>
                    <p class="text-secondary">Carrier trunks</p>
                    <ul>
                        <li v-for="t in usage.trunks" :key="t.id"><Link :href="showTrunk(t.id)">{{ t.account.code }} / {{ t.name }}</Link></li>
                        <li v-if="!usage.trunks.length" class="text-secondary">—</li>
                    </ul>
                </div>
            </section>
        </div>

        <!-- Rates -->
        <section class="ss-stack-3">
            <div class="d-flex flex-wrap gap-2">
                <Input v-model="filters.q" placeholder="Search prefix or destination" class="ss-maxw-xs" @input="apply(300)" />
                <select v-model="filters.when" :class="selectClass" class="w-auto" aria-label="Show" @change="apply()">
                    <option value="current">In effect now</option>
                    <option value="future">Scheduled</option>
                    <option value="expired">Expired</option>
                    <option value="all">All</option>
                </select>
            </div>

            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Prefix</th>
                            <th>Destination</th>
                            <th class="text-end">Rate / min</th>
                            <th class="text-end">Connect</th>
                            <th>Billing</th>
                            <th>Effective</th>
                            <th v-if="deck.kind === 'tariff'"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rates.data" :key="r.id">
                            <td class="font-monospace">{{ r.prefix }}</td>
                            <td>{{ r.destination }}</td>
                            <td class="text-end tabular-nums">
                                <template v-if="editing === r.id">
                                    <div class="d-flex justify-content-end align-items-center gap-1">
                                        <Input
                                            v-model="editRate"
                                            type="number"
                                            min="0"
                                            step="0.000001"
                                            class="ss-w-32 text-end"
                                            :aria-label="`New rate for ${r.prefix}`"
                                            @keydown.enter.prevent="saveEdit(r)"
                                            @keydown.esc="editing = null"
                                        />
                                    </div>
                                    <div v-if="editError" class="small text-danger">{{ editError }}</div>
                                </template>
                                <template v-else>{{ money(r.rate, deck.currency) }}</template>
                            </td>
                            <td class="text-end tabular-nums">{{ Number(r.connect_fee) ? money(r.connect_fee, deck.currency) : '—' }}</td>
                            <td class="font-monospace">{{ increments(r) }}</td>
                            <td class="text-nowrap text-secondary">
                                {{ dateTime(r.effective_from) }}<span v-if="r.effective_to"> → {{ dateTime(r.effective_to) }}</span>
                            </td>
                            <td v-if="deck.kind === 'tariff'" class="text-end text-nowrap">
                                <template v-if="editing === r.id">
                                    <Button size="sm" @click="saveEdit(r)">Save</Button>
                                    <Button size="sm" variant="ghost" @click="editing = null">Cancel</Button>
                                </template>
                                <Button v-else-if="!ended(r)" size="sm" variant="ghost" :disabled="importing" @click="startEdit(r)">Edit</Button>
                            </td>
                        </tr>
                        <tr v-if="rates.data.length === 0">
                            <td :colspan="deck.kind === 'tariff' ? 7 : 6" class="text-center text-secondary">No rates{{ filters.q ? ' match' : '' }}.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rates.links" :from="rates.from" :to="rates.to" :total="rates.total" />
        </section>
    </div>
</template>
