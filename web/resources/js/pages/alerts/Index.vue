<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import AlertController from '@/actions/App/Http/Controllers/Admin/AlertController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateTime } from '@/lib/format';
import { show as showAccount } from '@/routes/accounts';
import { index } from '@/routes/alerts';
import { index as cdrIndex } from '@/routes/cdrs';
import { show as showTrunk } from '@/routes/trunks';

type AlertRow = {
    id: number;
    type: string;
    label: string;
    kind: 'fraud' | 'operational';
    severity: 'warning' | 'critical';
    status: 'open' | 'acknowledged' | 'resolved';
    title: string;
    details: Record<string, unknown> | null;
    action_taken: string | null;
    opened_at: string;
    last_seen_at: string;
    resolved_at: string | null;
    emailed_at: string | null;
    account: { id: number; code: string; name: string; status: string } | null;
    trunk: { id: number; name: string } | null;
    gateway: string | null;
};

type Rule = {
    type: string;
    label: string;
    kind: 'fraud' | 'operational';
    severity: string;
    help: string;
    enabled: boolean;
    auto_suspend: boolean;
    params: Record<string, number>;
};

const props = defineProps<{ alerts: AlertRow[]; status: string; rules: Rule[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Alerts', href: index() }] } });

const setStatus = (s: string) => router.get(index.url({ query: { status: s } }), {}, { preserveState: true, replace: true });
const detail = (v: unknown) => (Array.isArray(v) ? v.join(', ') : String(v));
// The customer's calls for the last hour, to investigate a fraud alert.
const calls = (a: AlertRow) =>
    cdrIndex({ query: { customer: a.account?.id ?? '', from: new Date(new Date(a.last_seen_at).getTime() - 3600_000).toISOString(), to: new Date().toISOString() } });
const helpText = (r: Rule) => r.help.replace(/\{(\w+)\}/g, (_, k) => String(r.params[k] ?? k));
const paramLabel: Record<string, string> = {
    multiplier: 'Times usual spend', min_amount: 'Minimum spend', count: 'Count', rate: 'Rate per minute', max_seconds: 'Max seconds',
    asr: 'ASR below (%)', min_attempts: 'Minimum attempts', pdd_ms: 'PDD above (ms)', attempts: 'Attempts',
};
</script>

<template>
    <Head title="Alerts" />

    <div class="space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading title="Alerts" description="Fraud and vendor problems, checked every minute" />
            <div class="flex gap-1">
                <Button v-for="s in ['live', 'resolved', 'all']" :key="s" :variant="props.status === s ? 'default' : 'ghost'" size="sm" @click="setStatus(s)">{{ s }}</Button>
            </div>
        </div>

        <ul class="space-y-2">
            <li v-for="a in alerts" :key="a.id" class="rounded-lg border p-3 text-sm" :class="{ 'border-destructive': a.status === 'open' && a.severity === 'critical', 'opacity-60': a.status === 'resolved' }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <Badge :variant="a.severity === 'critical' ? 'destructive' : 'secondary'">{{ a.label }}</Badge>
                            <Badge variant="outline">{{ a.status }}</Badge>
                            <span class="font-medium">{{ a.title }}</span>
                        </p>
                        <p v-if="a.action_taken" class="font-medium text-destructive">{{ a.action_taken }}</p>
                        <p class="text-xs text-muted-foreground">
                            Since {{ dateTime(a.opened_at) }} · last seen {{ dateTime(a.last_seen_at) }}
                            <span v-if="a.resolved_at"> · resolved {{ dateTime(a.resolved_at) }}</span>
                            <span v-if="a.emailed_at"> · emailed</span>
                        </p>
                        <p v-if="a.details" class="flex flex-wrap gap-x-4 text-xs text-muted-foreground">
                            <span v-for="(v, k) in a.details" :key="k">{{ String(k).replace(/_/g, ' ') }}: <span class="font-mono text-foreground">{{ detail(v) }}</span></span>
                        </p>
                        <p class="flex flex-wrap gap-3 text-xs">
                            <Link v-if="a.account" :href="showAccount(a.account.id)" class="hover:underline">
                                {{ a.account.code }}<span v-if="a.account.status !== 'active'"> ({{ a.account.status }})</span>
                            </Link>
                            <Link v-if="a.trunk" :href="showTrunk(a.trunk.id)" class="hover:underline">trunk {{ a.trunk.name }}</Link>
                            <span v-if="a.gateway" class="font-mono">{{ a.gateway }}</span>
                            <Link v-if="a.kind === 'fraud' && a.account" :href="calls(a)" class="hover:underline">their calls in the last hour →</Link>
                        </p>
                    </div>
                    <div v-if="a.status !== 'resolved'" class="flex gap-2">
                        <Form v-if="a.status === 'open'" v-bind="AlertController.acknowledge.form(a.id)" v-slot="{ processing }">
                            <Button size="sm" variant="outline" :disabled="processing">Acknowledge</Button>
                        </Form>
                        <Form v-bind="AlertController.resolve.form(a.id)" v-slot="{ processing }">
                            <Button size="sm" variant="ghost" :disabled="processing">Resolve</Button>
                        </Form>
                    </div>
                </div>
            </li>
            <li v-if="alerts.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                {{ status === 'live' ? 'No live alerts.' : 'Nothing here.' }}
            </li>
        </ul>

        <section class="space-y-3">
            <Heading
                variant="small"
                title="Rules"
                description="Thresholds for each check. Fraud rules can suspend the customer automatically (calls are refused until you reactivate the account)."
            />
            <Form
                v-for="r in rules"
                :key="r.type"
                v-bind="AlertController.updateRule.form(r.type)"
                :options="{ preserveScroll: true }"
                class="space-y-3 rounded-lg border p-4 text-sm"
                v-slot="{ errors, processing }"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="flex items-center gap-2 font-medium">
                        {{ r.label }} <Badge variant="outline">{{ r.kind }}</Badge>
                    </p>
                    <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" :checked="r.enabled" class="size-4" /> Enabled</label>
                </div>
                <p class="text-muted-foreground">{{ helpText(r) }}</p>
                <div class="flex flex-wrap items-end gap-3">
                    <label v-for="(v, k) in r.params" :key="k" class="grid gap-1 text-xs">
                        {{ paramLabel[k] ?? k }}
                        <Input :name="`params[${k}]`" type="number" step="any" min="0" :default-value="v" class="w-32" />
                        <span v-if="errors[`params.${k}`]" class="text-destructive">{{ errors[`params.${k}`] }}</span>
                    </label>
                    <label v-if="r.kind === 'fraud'" class="flex items-center gap-2 pb-2">
                        <input type="checkbox" name="auto_suspend" value="1" :checked="r.auto_suspend" class="size-4" /> Suspend the customer automatically
                    </label>
                    <Button size="sm" variant="outline" :disabled="processing">Save</Button>
                </div>
            </Form>
        </section>
    </div>
</template>
