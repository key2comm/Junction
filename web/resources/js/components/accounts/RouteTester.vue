<script setup lang="ts">
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { money } from '@/lib/format';
import { routeTest } from '@/routes/accounts';

type Result = {
    status: 'ok' | 'reject';
    reason: string | null;
    message: string | null;
    destination: string | null;
    sell_prefix: string | null;
    sell_rate: string | null;
    max_duration: number | null;
    attempts: {
        attempt: number;
        trunk: string;
        dial_number: string;
        buy_prefix: string;
        buy_rate: string;
        margin: string | null;
        quality_demoted: boolean;
        gateways: { id: number; name: string }[];
    }[];
};

const props = defineProps<{ accountId: number; currency: string }>();

const number = ref('');
const result = ref<Result | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);

async function test() {
    if (!number.value.trim()) return;
    loading.value = true;
    error.value = null;
    try {
        const response = await fetch(routeTest.url(props.accountId, { query: { number: number.value } }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await response.json();
        if (!response.ok) {
            error.value = body.errors?.number?.[0] ?? body.message ?? 'Route test failed.';
            result.value = null;
        } else {
            result.value = body;
        }
    } catch {
        error.value = 'Route test failed.';
    } finally {
        loading.value = false;
    }
}

const duration = (s: number | null) => (s === null ? '—' : s >= 3600 ? `${Math.floor(s / 3600)}h ${Math.round((s % 3600) / 60)}m` : s >= 60 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${s}s`);
</script>

<template>
    <section class="space-y-4">
        <Heading variant="small" title="Test a number" description="What a call from this customer to a number would do right now — the same decision FreeSWITCH makes." />

        <form class="flex gap-2" @submit.prevent="test">
            <Input v-model="number" placeholder="e.g. +44 20 7946 0000" class="max-w-xs font-mono" aria-label="Number to test" />
            <Button :disabled="loading || !number.trim()"><Spinner v-if="loading" /> Test</Button>
        </form>
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <div v-if="result" class="space-y-3 rounded-lg border p-4 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <Badge :variant="result.status === 'ok' ? 'default' : 'destructive'">{{ result.status === 'ok' ? 'Routable' : 'Rejected' }}</Badge>
                <span v-if="result.destination" class="font-mono">{{ result.destination }}</span>
                <span v-if="result.message" class="text-destructive">{{ result.message }}</span>
            </div>
            <p v-if="result.sell_rate" class="text-muted-foreground">
                Sell {{ money(result.sell_rate, currency) }}/min (prefix {{ result.sell_prefix }})
                <span v-if="result.max_duration !== null"> · affordable call length {{ duration(result.max_duration) }}</span>
            </p>

            <table v-if="result.attempts.length" class="w-full">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-1 font-medium">#</th>
                        <th class="py-1 font-medium">Trunk</th>
                        <th class="py-1 font-medium">Gateways (in order)</th>
                        <th class="py-1 font-medium">Sent as</th>
                        <th class="py-1 text-right font-medium">Buy</th>
                        <th class="py-1 text-right font-medium">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in result.attempts" :key="a.attempt" class="border-t">
                        <td class="py-1">{{ a.attempt }}</td>
                        <td class="py-1">
                            {{ a.trunk }}
                            <Badge v-if="a.quality_demoted" variant="secondary" class="ml-1" title="Performing below the plan's quality bar over the last hour, so tried after the others">demoted</Badge>
                        </td>
                        <td class="py-1 font-mono text-xs">{{ a.gateways.map((g) => g.name).join(' → ') }}</td>
                        <td class="py-1 font-mono">{{ a.dial_number }}</td>
                        <td class="py-1 text-right tabular-nums">{{ money(a.buy_rate, currency) }}</td>
                        <td class="py-1 text-right tabular-nums">{{ a.margin !== null ? money(a.margin, currency) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="result.attempts.length > 1" class="text-xs text-muted-foreground">With weighted load balancing, the order changes from call to call.</p>
        </div>
    </section>
</template>
