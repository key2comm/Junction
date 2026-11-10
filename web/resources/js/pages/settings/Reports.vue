<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Download, Pencil, Play, Plus, Trash2 } from '@lucide/vue';
import ReportController from '@/actions/App/Http/Controllers/Settings/ReportController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { create, edit, index } from '@/routes/reports';
import { download } from '@/routes/reports/files';

type Schedule = {
    id: number;
    name: string;
    enabled: boolean;
    frequency: string;
    timezone: string;
    retention_days: number;
    sftp_enabled: boolean;
    sftp_host: string | null;
    last_run_at: string | null;
    last_error: string | null;
    files_count: number;
    emails: string[];
    filters: string[];
};
type File = {
    id: number;
    filename: string;
    size: number;
    rows: number;
    period_start: string;
    period_end: string;
    emailed_at: string | null;
    uploaded_at: string | null;
    error: string | null;
    created_at: string;
    schedule: { id: number; name: string; timezone: string };
};

defineProps<{ schedules: Schedule[]; files: File[]; frequencies: Record<string, string> }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Scheduled reports', href: index() }] } });

function remove(s: Schedule) {
    if (confirm(`Delete the schedule ${s.name} and its ${s.files_count} saved report(s)?`)) router.delete(ReportController.destroy.url(s.id));
}
const size = (b: number) => (b < 1024 ? `${b} B` : b < 1048576 ? `${(b / 1024).toFixed(1)} KB` : `${(b / 1048576).toFixed(1)} MB`);
const day = (iso: string, tz: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone: tz }).format(new Date(iso));
// The period's last day (the end is the next period's start).
const lastDay = (iso: string, tz: string) => day(new Date(new Date(iso).getTime() - 1000).toISOString(), tz);
</script>

<template>
    <Head title="Scheduled reports" />

    <h1 class="visually-hidden">Scheduled reports</h1>

    <section class="ss-stack-3">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
            <Heading
                variant="small"
                title="Scheduled reports"
                description="Call records (CDR) CSV files made every day, week or month for the period that just ended, kept on this server, and optionally emailed and uploaded by SFTP."
            />
            <Button as-child><Link :href="create()"><Plus class="icon" /> New schedule</Link></Button>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>When</th>
                        <th>Delivery</th>
                        <th>Last run</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in schedules" :key="s.id" :class="{ 'opacity-50': !s.enabled }">
                        <td>
                            {{ s.name }} <Badge v-if="!s.enabled" variant="secondary" class="ms-1">paused</Badge>
                            <div class="small text-secondary">{{ s.filters.length ? s.filters.join(' · ') : 'All calls' }}</div>
                        </td>
                        <td class="small">
                            {{ frequencies[s.frequency] }}
                            <div class="text-secondary">{{ s.timezone }} · kept {{ s.retention_days }} days</div>
                        </td>
                        <td class="small">
                            Saved on the server
                            <div v-if="s.emails.length" class="text-secondary">Email: {{ s.emails.join(', ') }}</div>
                            <div v-if="s.sftp_enabled" class="text-secondary">SFTP: {{ s.sftp_host }}</div>
                        </td>
                        <td class="small">
                            <span v-if="s.last_run_at">{{ dateTime(s.last_run_at) }}</span>
                            <span v-else class="text-secondary">Not yet</span>
                            <div v-if="s.last_error" class="text-danger">{{ s.last_error }}</div>
                        </td>
                        <td class="text-end text-nowrap">
                            <Form v-bind="ReportController.run.form(s.id)" class="d-inline" v-slot="{ processing }">
                                <Button size="sm" variant="ghost" :disabled="processing" title="Make the report for the last period now"><Play class="icon" /> Run now</Button>
                            </Form>
                            <Button size="sm" variant="ghost" as-child><Link :href="edit(s.id)" :aria-label="`Edit ${s.name}`"><Pencil class="icon" /></Link></Button>
                            <Button size="sm" variant="ghost" :aria-label="`Delete ${s.name}`" @click="remove(s)"><Trash2 class="icon" /></Button>
                        </td>
                    </tr>
                    <tr v-if="schedules.length === 0">
                        <td colspan="5" class="text-center text-secondary">No schedules yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="ss-stack-3">
        <Heading variant="small" title="Saved reports" description="The latest 200 files on the server. Each is deleted automatically after its schedule's keep period." />
        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Period</th>
                        <th class="text-end">Calls</th>
                        <th class="text-end">Size</th>
                        <th>Sent</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in files" :key="f.id">
                        <td>
                            <a :href="download(f.id).url" class="font-monospace small">{{ f.filename }}</a>
                            <div class="small text-secondary">{{ f.schedule.name }}</div>
                        </td>
                        <td class="small text-nowrap">
                            {{ day(f.period_start, f.schedule.timezone) }}<template v-if="lastDay(f.period_end, f.schedule.timezone) !== day(f.period_start, f.schedule.timezone)">
                                – {{ lastDay(f.period_end, f.schedule.timezone) }}</template
                            >
                        </td>
                        <td class="text-end tabular-nums">{{ f.rows.toLocaleString() }}</td>
                        <td class="text-end tabular-nums text-nowrap">{{ size(f.size) }}</td>
                        <td class="small">
                            <div v-if="f.emailed_at">Emailed</div>
                            <div v-if="f.uploaded_at">Uploaded (SFTP)</div>
                            <div v-if="f.error" class="text-danger">{{ f.error }}</div>
                            <span v-if="!f.emailed_at && !f.uploaded_at && !f.error" class="text-secondary">Saved only</span>
                        </td>
                        <td class="text-end text-nowrap">
                            <Button size="sm" variant="ghost" as-child><a :href="download(f.id).url" :aria-label="`Download ${f.filename}`"><Download class="icon" /></a></Button>
                            <Form v-bind="ReportController.destroyFile.form(f.id)" class="d-inline" v-slot="{ processing }">
                                <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Delete ${f.filename}`"><Trash2 class="icon" /></Button>
                            </Form>
                        </td>
                    </tr>
                    <tr v-if="files.length === 0">
                        <td colspan="6" class="text-center text-secondary">No reports yet. Use Run now to make one straight away.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
