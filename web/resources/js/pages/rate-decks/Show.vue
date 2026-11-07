<script setup lang="ts">
import { Form, Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { reactive, watch } from 'vue';
import RateImportController from '@/actions/App/Http/Controllers/Admin/RateImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dateTime, money } from '@/lib/format';
import type { Paginated } from '@/lib/ui';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { edit, index, show } from '@/routes/rate-decks';
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
    deck: { id: number; name: string; currency: string; description: string | null };
    rates: Paginated<Rate>;
    filters: { q: string; when: string };
    imports: Import[];
    importing: boolean;
    usage: { customers: { id: number; name: string; code: string }[]; trunks: { id: number; name: string; account: { code: string } }[] };
}>();

defineOptions({
    layout: (props: { deck: { id: number; name: string } }) => ({
        breadcrumbs: [
            { title: 'Rate decks', href: index() },
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
</script>

<template>
    <Head :title="deck.name" />

    <div class="space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="deck.name" :description="deck.description ?? `Rates in ${deck.currency} per minute`" />
            <Button variant="outline" as-child><Link :href="edit(deck.id)"><Pencil class="size-4" /> Edit</Link></Button>
        </div>

        <div class="grid gap-8 lg:grid-cols-[2fr_1fr]">
            <!-- Import -->
            <section class="space-y-4">
                <Heading
                    variant="small"
                    title="Import rates"
                    description="CSV with a header row. Required columns: prefix, rate. Optional: destination, connect_fee, initial_increment, increment (seconds, default 60/60), effective_from."
                />
                <Form
                    v-bind="RateImportController.store.form(deck.id)"
                    reset-on-success
                    class="grid gap-4 sm:grid-cols-2"
                    v-slot="{ errors, processing, progress }"
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="file">File</Label>
                        <Input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required />
                        <progress v-if="progress" :value="progress.percentage" max="100" class="w-full" />
                        <InputError :message="errors.file" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="mode">Mode</Label>
                        <select id="mode" name="mode" :class="selectClass">
                            <option value="update">Update: change only the prefixes in the file</option>
                            <option value="replace">Replace: the file is the whole deck</option>
                        </select>
                        <InputError :message="errors.mode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="effective_from">Effective from (UTC)</Label>
                        <Input id="effective_from" name="effective_from" type="datetime-local" />
                        <p class="text-xs text-muted-foreground">Blank = immediately. Rows with their own effective_from override this.</p>
                        <InputError :message="errors.effective_from" />
                    </div>
                    <div>
                        <Button :disabled="processing || importing">Upload and import</Button>
                    </div>
                </Form>

                <ul v-if="imports.length" class="divide-y rounded-lg border text-sm">
                    <li v-for="i in imports" :key="i.id" class="space-y-1 px-3 py-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <Spinner v-if="i.status === 'queued' || i.status === 'running'" />
                            <Badge :variant="i.status === 'failed' ? 'destructive' : i.status === 'done' ? 'default' : 'secondary'">{{ i.status }}</Badge>
                            <span class="font-medium">{{ i.filename }}</span>
                            <span class="text-muted-foreground">{{ i.mode }}, effective {{ dateTime(i.effective_from) }}, by {{ i.user ?? 'unknown' }}</span>
                        </div>
                        <p v-if="i.status === 'done' && i.stats" class="text-muted-foreground">
                            {{ i.stats.added }} added · {{ i.stats.changed }} changed · {{ i.stats.unchanged }} unchanged<span v-if="i.mode === 'replace'"> · {{ i.stats.ended }} ended</span>
                            <span v-if="i.stats.increases_within_notice" class="text-destructive">
                                · {{ i.stats.increases_within_notice }} increase(s) take effect in under 7 days
                            </span>
                        </p>
                        <ul v-if="i.status === 'failed' && i.errors" class="list-inside list-disc text-destructive">
                            <li v-for="(e, n) in i.errors" :key="n">{{ e }}</li>
                        </ul>
                    </li>
                </ul>
            </section>

            <!-- Usage -->
            <section class="space-y-4 text-sm">
                <Heading variant="small" title="Used by" />
                <div>
                    <p class="text-muted-foreground">Customers (sell)</p>
                    <ul>
                        <li v-for="c in usage.customers" :key="c.id"><Link :href="showAccount(c.id)" class="hover:underline">{{ c.name }}</Link></li>
                        <li v-if="!usage.customers.length" class="text-muted-foreground">—</li>
                    </ul>
                </div>
                <div>
                    <p class="text-muted-foreground">Vendor trunks (buy)</p>
                    <ul>
                        <li v-for="t in usage.trunks" :key="t.id"><Link :href="showTrunk(t.id)" class="hover:underline">{{ t.account.code }} / {{ t.name }}</Link></li>
                        <li v-if="!usage.trunks.length" class="text-muted-foreground">—</li>
                    </ul>
                </div>
            </section>
        </div>

        <!-- Rates -->
        <section class="space-y-4">
            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.q" placeholder="Search prefix or destination" class="max-w-xs" @input="apply(300)" />
                <select v-model="filters.when" :class="selectClass" class="w-auto" aria-label="Show" @change="apply()">
                    <option value="current">In effect now</option>
                    <option value="future">Scheduled</option>
                    <option value="expired">Expired</option>
                    <option value="all">All</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Prefix</th>
                            <th class="px-3 py-2 font-medium">Destination</th>
                            <th class="px-3 py-2 text-right font-medium">Rate / min</th>
                            <th class="px-3 py-2 text-right font-medium">Connect</th>
                            <th class="px-3 py-2 font-medium">Billing</th>
                            <th class="px-3 py-2 font-medium">Effective</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rates.data" :key="r.id" class="border-t">
                            <td class="px-3 py-1.5 font-mono">{{ r.prefix }}</td>
                            <td class="px-3 py-1.5">{{ r.destination }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ money(r.rate, deck.currency) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ Number(r.connect_fee) ? money(r.connect_fee, deck.currency) : '—' }}</td>
                            <td class="px-3 py-1.5 font-mono">{{ increments(r) }}</td>
                            <td class="px-3 py-1.5 whitespace-nowrap text-muted-foreground">
                                {{ dateTime(r.effective_from) }}<span v-if="r.effective_to"> → {{ dateTime(r.effective_to) }}</span>
                            </td>
                        </tr>
                        <tr v-if="rates.data.length === 0">
                            <td colspan="6" class="px-3 py-10 text-center text-muted-foreground">No rates{{ filters.q ? ' match' : '' }}.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rates.links" :from="rates.from" :to="rates.to" :total="rates.total" />
        </section>
    </div>
</template>
