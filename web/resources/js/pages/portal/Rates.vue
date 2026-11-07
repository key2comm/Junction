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

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading title="Your rates" description="Per-minute prices in effect now" />
            <Button v-if="rates" variant="outline" as-child><a :href="download.url()"><Download class="icon" /> Download CSV</a></Button>
        </div>

        <p v-if="!rates" class="rounded border ss-border-dashed p-4 text-center text-secondary">No rates are set up for your account yet. Please contact us.</p>

        <template v-else>
            <p v-if="scheduled" class="text-secondary">{{ scheduled }} upcoming rate change(s) are scheduled; the CSV includes them with their effective dates.</p>
            <Input v-model="q" placeholder="Search by prefix, e.g. 44" class="ss-maxw-xs font-monospace" @input="search" />
            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Prefix</th>
                            <th>Destination</th>
                            <th class="text-end">Rate/min</th>
                            <th class="text-end">Connect</th>
                            <th>Billing</th>
                            <th>Until</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rates.data" :key="r.prefix + r.effective_from">
                            <td class="px-2 py-1 font-monospace">{{ r.prefix }}</td>
                            <td class="px-2 py-1">{{ r.destination }}</td>
                            <td class="px-2 py-1 text-end tabular-nums">{{ money(r.rate, currency) }}</td>
                            <td class="px-2 py-1 text-end tabular-nums">{{ Number(r.connect_fee) ? money(r.connect_fee, currency) : '—' }}</td>
                            <td class="px-2 py-1 font-monospace">{{ r.initial_increment }}/{{ r.increment }}</td>
                            <td class="px-2 py-1 text-secondary">{{ r.effective_to ? date(r.effective_to) : '—' }}</td>
                        </tr>
                        <tr v-if="rates.data.length === 0">
                            <td colspan="6" class="px-2 py-4 text-center text-secondary">No rates match.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :links="rates.links" :from="rates.from" :to="rates.to" :total="rates.total" />
        </template>
    </div>
</template>
