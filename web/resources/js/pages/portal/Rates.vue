<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { date, money } from '@/lib/format';
import type { Paginated } from '@/lib/ui';
import { download, index } from '@/routes/portal/rates';

type Rate = { prefix: string; destination: string | null; rate: string; connect_fee: string; initial_increment: number; increment: number; effective_from: string; effective_to: string | null };

const props = defineProps<{ rates: Paginated<Rate> | null; scheduled: number; filters: { q: string }; currency: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Rates', href: index() }] } });

const q = ref(props.filters.q);
let timer: ReturnType<typeof setTimeout> | undefined;
function search() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(index.url({ query: { q: q.value || undefined } }), {}, { preserveState: true, replace: true }), 300);
}
</script>

<template>
    <Head title="Rates" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Your rates" description="Per-minute prices in effect now" />
            <Button v-if="rates" variant="outline" as-child><a :href="download.url()"><Download class="size-4" /> Download CSV</a></Button>
        </div>

        <p v-if="!rates" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No rates are set up for your account yet. Please contact us.</p>

        <template v-else>
            <p v-if="scheduled" class="text-sm text-muted-foreground">{{ scheduled }} upcoming rate change(s) are scheduled; the CSV includes them with their effective dates.</p>
            <Input v-model="q" placeholder="Search by prefix, e.g. 44" class="max-w-xs font-mono" @input="search" />
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Prefix</th>
                            <th class="px-3 py-2 font-medium">Destination</th>
                            <th class="px-3 py-2 text-right font-medium">Rate/min</th>
                            <th class="px-3 py-2 text-right font-medium">Connect</th>
                            <th class="px-3 py-2 font-medium">Billing</th>
                            <th class="px-3 py-2 font-medium">Until</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rates.data" :key="r.prefix + r.effective_from" class="border-t">
                            <td class="px-3 py-1.5 font-mono">{{ r.prefix }}</td>
                            <td class="px-3 py-1.5">{{ r.destination }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ money(r.rate, currency) }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ Number(r.connect_fee) ? money(r.connect_fee, currency) : '—' }}</td>
                            <td class="px-3 py-1.5 font-mono">{{ r.initial_increment }}/{{ r.increment }}</td>
                            <td class="px-3 py-1.5 text-muted-foreground">{{ r.effective_to ? date(r.effective_to) : '—' }}</td>
                        </tr>
                        <tr v-if="rates.data.length === 0">
                            <td colspan="6" class="px-3 py-8 text-center text-muted-foreground">No rates match.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rates.links" :from="rates.from" :to="rates.to" :total="rates.total" />
        </template>
    </div>
</template>
