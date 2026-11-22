<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleHelp, CircleMinus, Download, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariant } from '@/components/ui/badge/Badge.vue';
import { dateTime } from '@/lib/format';
import { index as alerts } from '@/routes/alerts';
import { index as apiTokens } from '@/routes/api-tokens';
import { grafana, index } from '@/routes/system-health';

type State = 'ok' | 'warning' | 'critical' | 'off' | 'unknown';
type Check = { key: string; label: string; state: State; summary: string; rule: string | null; alert: boolean };
type Net = { rx: number; tx: number; rx_bps: number | null; tx_bps: number | null };
type Fast = {
    at: string;
    cpu: number | null;
    cores: number;
    load: [number, number, number];
    memory: { total: number; used: number };
    swap: { total: number; used: number };
    disk: { total: number; used: number };
    network: Record<string, Net>;
    freeswitch: {
        up: boolean;
        error?: string;
        version?: string | null;
        uptime?: number | null;
        sessions?: number | null;
        sessions_peak?: number | null;
        sessions_per_second?: number | null;
        max_sessions?: number | null;
        max_sessions_per_second?: number | null;
        profiles?: Record<string, string>;
    };
};
type Slow = {
    at: string;
    database: { bytes: number; months: { month: string; rows: number; bytes: number }[] };
};

const props = defineProps<{ checks: Check[]; fast: Fast | null; slow: Slow | null; standby: boolean; metricsUrl: string; metricsTokens: number }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'System health', href: index() }] } });

usePoll(15000, { only: ['checks', 'fast', 'slow'] });

const look: Record<State, { icon: typeof CircleCheck; text: string; badge: BadgeVariant; word: string }> = {
    ok: { icon: CircleCheck, text: 'text-success', badge: 'success', word: 'OK' },
    warning: { icon: TriangleAlert, text: 'text-warning', badge: 'warning', word: 'Warning' },
    critical: { icon: CircleAlert, text: 'text-danger', badge: 'destructive', word: 'Problem' },
    off: { icon: CircleMinus, text: 'text-secondary', badge: 'secondary', word: 'Off' },
    unknown: { icon: CircleHelp, text: 'text-secondary', badge: 'outline', word: 'Unknown' },
};
const problems = computed(() => props.checks.filter((c) => c.state === 'critical').length);

const bytes = (b: number) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1024 && i < units.length - 1) {
        b /= 1024;
        i++;
    }

    return `${b.toFixed(i >= 2 ? 1 : 0)} ${units[i]}`;
};
const bits = (b: number | null) => {
    if (b === null) {
        return '–';
    }
    const units = ['bit/s', 'kbit/s', 'Mbit/s', 'Gbit/s'];
    let i = 0;
    while (b >= 1000 && i < units.length - 1) {
        b /= 1000;
        i++;
    }

    return `${b.toFixed(i ? 1 : 0)} ${units[i]}`;
};
const uptime = (s: number | null | undefined) => {
    if (!s) {
        return '–';
    }
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);

    return d > 0 ? `${d}d ${h}h` : `${h}h ${Math.floor((s % 3600) / 60)}m`;
};
const pct = (used: number, total: number) => (total > 0 ? Math.round((used / total) * 100) : 0);
const month = (key: string) => {
    const m = key.match(/^y(\d{4})m(\d{2})$/);

    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, 1)).toLocaleDateString(undefined, { year: 'numeric', month: 'long', timeZone: 'UTC' }) : key === 'default' ? 'Catch-all (filed nightly)' : key;
};
const scrape = computed(() => {
    const u = new URL(props.metricsUrl);

    return [
        'scrape_configs:',
        '  - job_name: junction',
        '    scrape_interval: 15s',
        `    scheme: ${u.protocol.replace(':', '')}`,
        `    metrics_path: ${u.pathname}`,
        '    authorization:',
        '      credentials: YOUR_API_TOKEN',
        '    static_configs:',
        `      - targets: ['${u.host}']`,
    ].join('\n');
});
</script>

<template>
    <Head title="System health" />

    <div class="ss-stack-4">
        <Heading title="System health" description="Checked by the scheduler: server figures every 15 seconds, everything else every minute. Problems also raise alerts." />

        <div v-if="standby" class="alert alert-info mb-0">
            This is the backup server. Its scheduler only runs once it takes over, so these figures are from when it last ran. Check the main server.
        </div>

        <section class="ss-stack-2">
            <Heading variant="small" title="Checks" :description="problems ? `${problems} need attention.` : 'Everything checked is working.'" />
            <ul class="list-group">
                <li v-for="c in checks" :key="c.key" class="list-group-item d-flex align-items-start gap-2">
                    <component :is="look[c.state].icon" class="icon mt-1 flex-shrink-0" :class="look[c.state].text" />
                    <div class="flex-grow-1">
                        <p class="d-flex flex-wrap align-items-center gap-2 mb-0">
                            <span class="fw-medium">{{ c.label }}</span>
                            <Badge :variant="look[c.state].badge">{{ look[c.state].word }}</Badge>
                            <Link v-if="c.alert" :href="alerts()" class="small">alert raised →</Link>
                        </p>
                        <p class="text-secondary mb-0">{{ c.summary }}</p>
                    </div>
                </li>
            </ul>
            <p class="small text-secondary mb-0">The thresholds (and whether each alert is on) are under <Link :href="alerts()">Alerts → Rules</Link>.</p>
        </section>

        <section v-if="fast" class="ss-stack-2">
            <Heading variant="small" title="Server" :description="`Read ${dateTime(fast.at)}`" />
            <div class="row g-2">
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm card-body">
                        <p class="small text-secondary mb-1">CPU ({{ fast.cores }} cores)</p>
                        <p class="h3 mb-0">{{ fast.cpu ?? '–' }}%</p>
                        <p class="small text-secondary mb-0">Load {{ fast.load.map((l) => l.toFixed(2)).join(' / ') }}</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm card-body">
                        <p class="small text-secondary mb-1">Memory</p>
                        <p class="h3 mb-0">{{ pct(fast.memory.used, fast.memory.total) }}%</p>
                        <p class="small text-secondary mb-0">
                            {{ bytes(fast.memory.used) }} of {{ bytes(fast.memory.total) }}<template v-if="fast.swap.total"> · swap {{ bytes(fast.swap.used) }}</template>
                        </p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm card-body">
                        <p class="small text-secondary mb-1">Disk</p>
                        <p class="h3 mb-0">{{ pct(fast.disk.used, fast.disk.total) }}%</p>
                        <p class="small text-secondary mb-0">{{ bytes(fast.disk.total - fast.disk.used) }} free of {{ bytes(fast.disk.total) }}</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-sm card-body">
                        <p class="small text-secondary mb-1">Bandwidth</p>
                        <p v-for="(n, name) in fast.network" :key="name" class="small mb-0">
                            <span class="font-monospace">{{ name }}</span> ↓ {{ bits(n.rx_bps) }} · ↑ {{ bits(n.tx_bps) }}
                        </p>
                        <p v-if="Object.keys(fast.network).length === 0" class="small text-secondary mb-0">No network interfaces read.</p>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="fast" class="ss-stack-2">
            <Heading variant="small" title="FreeSWITCH" />
            <p v-if="!fast.freeswitch.up" class="text-danger mb-0">Not answering<template v-if="fast.freeswitch.error">: {{ fast.freeswitch.error }}</template></p>
            <dl v-else class="row mb-0">
                <dt class="col-sm-4 col-lg-3 fw-normal text-secondary">Version</dt>
                <dd class="col-sm-8 col-lg-9">{{ fast.freeswitch.version }} · up {{ uptime(fast.freeswitch.uptime) }}</dd>
                <dt class="col-sm-4 col-lg-3 fw-normal text-secondary">Call legs</dt>
                <dd class="col-sm-8 col-lg-9">
                    {{ fast.freeswitch.sessions }} now · peak {{ fast.freeswitch.sessions_peak }} · limit {{ fast.freeswitch.max_sessions }}
                    <span class="small text-secondary">(a call through the switch is two legs)</span>
                </dd>
                <dt class="col-sm-4 col-lg-3 fw-normal text-secondary">New legs per second</dt>
                <dd class="col-sm-8 col-lg-9">{{ fast.freeswitch.sessions_per_second }} · limit {{ fast.freeswitch.max_sessions_per_second }}</dd>
                <dt class="col-sm-4 col-lg-3 fw-normal text-secondary">SIP profiles</dt>
                <dd class="col-sm-8 col-lg-9 d-flex flex-wrap gap-2">
                    <Badge v-for="(state, name) in fast.freeswitch.profiles" :key="name" :variant="state === 'RUNNING' ? 'success' : 'destructive'">{{ name }}: {{ state }}</Badge>
                </dd>
            </dl>
        </section>

        <section v-if="slow" class="ss-stack-2">
            <Heading variant="small" title="Database" :description="`${bytes(slow.database.bytes)} in all. Call records are kept forever, one table per month.`" />
            <div class="table-responsive">
                <table class="table table-sm table-vcenter mb-0">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Calls (approx.)</th>
                            <th class="text-end">Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in slow.database.months" :key="m.month">
                            <td>{{ month(m.month) }}</td>
                            <td class="text-end">{{ m.rows.toLocaleString() }}</td>
                            <td class="text-end">{{ bytes(m.bytes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ss-stack-2">
            <Heading
                variant="small"
                title="Grafana"
                description="Graph CPU, memory, disk, bandwidth, active calls, calls per second and call quality (ASR, ACD, PDD per carrier and customer) in Grafana."
            />
            <p class="mb-0 text-secondary">
                Grafana can't read this server directly: something has to collect the metrics for it first (Grafana Cloud's Metrics Endpoint, Prometheus or Grafana Alloy). Don't point a Grafana
                data source at this server's address; it won't work, whatever token you give it.
            </p>
            <ol class="ss-stack-2 ps-3 mb-0">
                <li>
                    Create an API token with the <strong>Read server metrics</strong> permission under <Link :href="apiTokens()">Settings → API tokens</Link>.
                    <span v-if="metricsTokens" class="text-success">({{ metricsTokens }} active token{{ metricsTokens === 1 ? '' : 's' }} can read metrics.)</span>
                </li>
                <li>
                    Have the metrics collected from <span class="font-monospace">{{ metricsUrl }}</span> with that token:
                    <ul class="ss-stack-2 mt-2 ps-3">
                        <li>
                            <strong>Grafana Cloud</strong> (nothing to install): Connections → Add new connection → <strong>Metrics Endpoint</strong>. Enter the address above, choose
                            <strong>Bearer</strong> authentication and paste the token (without the word "Bearer"), then Test Connection, Save and Install. Its data source is the
                            one ending in <span class="font-monospace">-prom</span>.
                        </li>
                        <li>
                            <strong>Your own Prometheus</strong>, e.g. in <span class="font-monospace">prometheus.yml</span>:
                            <pre class="mt-2 mb-0 small">{{ scrape }}</pre>
                        </li>
                    </ul>
                    The address can only be read from outside if the firewall lets the collector (Grafana Cloud or your Prometheus server) reach this server's HTTPS port. If you
                    replace the token, update it there too.
                </li>
                <li>
                    In Grafana, import the ready-made dashboard (Dashboards → New → Import) and pick that Prometheus data source:
                    <div class="mt-2">
                        <a :href="grafana().url" class="btn btn-outline-primary btn-sm"><Download class="icon me-1" /> Download the Grafana dashboard</a>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</template>
