<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import ReportController from '@/actions/App/Http/Controllers/Settings/ReportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeZone } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { edit as editEmail } from '@/routes/email';
import { create, index } from '@/routes/reports';

type Account = { id: number; name: string; code: string };
type Schedule = {
    id: number;
    name: string;
    enabled: boolean;
    frequency: string;
    timezone: string;
    filters: { customer?: number; vendor?: number; direction?: string; status?: string };
    retention_days: number;
    email_to: string | null;
    sftp_enabled: boolean;
    sftp_host: string | null;
    sftp_port: number;
    sftp_username: string | null;
    sftp_path: string | null;
    sftp_fingerprint: string | null;
    has_password: boolean;
    has_key: boolean;
};

const props = defineProps<{
    schedule: Schedule | null;
    frequencies: Record<string, string>;
    timezones: string[];
    customers: Account[];
    carriers: Account[];
    directions: Record<string, string>;
    statuses: Record<string, string>;
    mailConfigured: boolean;
}>();

defineOptions({
    layout: (props: { schedule: { id: number; name: string } | null }) => ({
        breadcrumbs: [
            { title: 'Scheduled reports', href: index() },
            { title: props.schedule ? props.schedule.name : 'New schedule', href: create() },
        ],
    }),
});

const s = props.schedule;
const f = s?.filters ?? {};
const sftp = ref(s?.sftp_enabled ?? false);
const action = s ? ReportController.update.form(s.id) : ReportController.store.form();
// New schedules start in the user's own time zone (Settings → Profile).
const tz = s?.timezone ?? (props.timezones.includes(timeZone.value) ? timeZone.value : 'UTC');
</script>

<template>
    <Head :title="s ? `Edit ${s.name}` : 'New schedule'" />

    <Form v-bind="action" class="ss-stack-4 ss-maxw-3xl" v-slot="{ errors, processing }">
        <Heading variant="small" :title="s ? `Edit ${s.name}` : 'New scheduled report'" description="A call records (CDR) CSV, the same columns as the Call records export." />

        <section class="card card-body ss-grid gap-3 ss-cols-sm-2">
            <div class="ss-grid gap-1 ss-span-sm-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" :default-value="s?.name" required maxlength="100" placeholder="e.g. Daily CDRs, all customers" />
                <InputError :message="errors.name" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="frequency">How often</Label>
                <select id="frequency" name="frequency" :class="selectClass">
                    <option v-for="(label, k) in frequencies" :key="k" :value="k" :selected="k === (s?.frequency ?? 'daily')">{{ label }}</option>
                </select>
                <p class="small text-secondary mb-0">Made shortly after each period ends.</p>
                <InputError :message="errors.frequency" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="timezone">Time zone</Label>
                <select id="timezone" name="timezone" :class="selectClass">
                    <option v-for="z in timezones" :key="z" :value="z" :selected="z === tz">{{ z }}</option>
                </select>
                <p class="small text-secondary mb-0">Where each day, week and month starts and ends.</p>
                <InputError :message="errors.timezone" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="retention_days">Keep files on the server for</Label>
                <div class="input-group">
                    <Input id="retention_days" name="retention_days" type="number" min="1" max="3650" :default-value="s?.retention_days ?? 90" required />
                    <span class="input-group-text">days</span>
                </div>
                <InputError :message="errors.retention_days" />
            </div>
            <div class="d-flex align-items-end pb-2">
                <label class="d-flex align-items-center gap-2"><input type="checkbox" name="enabled" value="1" :checked="s?.enabled ?? true" class="form-check-input m-0" /> Enabled</label>
            </div>
        </section>

        <section class="card card-body ss-stack-3">
            <div>
                <h2 class="fw-semibold fs-4 mb-1">Which calls</h2>
                <p class="small text-secondary mb-0">Leave all on "All" for every call.</p>
            </div>
            <div class="ss-grid gap-3 ss-cols-sm-2">
                <div class="ss-grid gap-1">
                    <Label for="f_customer">Customer</Label>
                    <select id="f_customer" name="filters[customer]" :class="selectClass">
                        <option value="">All customers</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id" :selected="c.id === f.customer">{{ c.name }} ({{ c.code }})</option>
                    </select>
                    <InputError :message="errors['filters.customer']" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="f_vendor">Carrier</Label>
                    <select id="f_vendor" name="filters[vendor]" :class="selectClass">
                        <option value="">All carriers</option>
                        <option v-for="c in carriers" :key="c.id" :value="c.id" :selected="c.id === f.vendor">{{ c.name }}</option>
                    </select>
                    <InputError :message="errors['filters.vendor']" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="f_direction">Direction</Label>
                    <select id="f_direction" name="filters[direction]" :class="selectClass">
                        <option value="">All</option>
                        <option v-for="(label, k) in directions" :key="k" :value="k" :selected="k === f.direction">{{ label }}</option>
                    </select>
                </div>
                <div class="ss-grid gap-1">
                    <Label for="f_status">Result</Label>
                    <select id="f_status" name="filters[status]" :class="selectClass">
                        <option value="">All</option>
                        <option v-for="(label, k) in statuses" :key="k" :value="k" :selected="k === f.status">{{ label }}</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="card card-body ss-stack-3">
            <div>
                <h2 class="fw-semibold fs-4 mb-1">Email</h2>
                <p class="small text-secondary mb-0">
                    Optional. The CSV is attached (files over 10 MB get a link to this page instead).
                    <template v-if="!mailConfigured">
                        Email isn't set up yet: <Link :href="editEmail()" class="fw-medium">Settings → Email (SMTP)</Link>.
                    </template>
                </p>
            </div>
            <div class="ss-grid gap-1">
                <Label for="email_to">Send to</Label>
                <Input id="email_to" name="email_to" :default-value="s?.email_to ?? ''" placeholder="noc@example.com, billing@example.com" />
                <InputError :message="errors.email_to" />
            </div>
        </section>

        <section class="card card-body ss-stack-3">
            <label class="d-flex align-items-center gap-2 fw-semibold">
                <input v-model="sftp" type="checkbox" name="sftp_enabled" value="1" class="form-check-input m-0" /> Also upload by SFTP
            </label>
            <div v-show="sftp" class="ss-grid gap-3 ss-cols-sm-2">
                <div class="ss-grid gap-1">
                    <Label for="sftp_host">Host</Label>
                    <Input id="sftp_host" name="sftp_host" :default-value="s?.sftp_host ?? ''" placeholder="sftp.example.com" class="font-monospace" />
                    <InputError :message="errors.sftp_host" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="sftp_port">Port</Label>
                    <Input id="sftp_port" name="sftp_port" type="number" min="1" max="65535" :default-value="s?.sftp_port ?? 22" />
                    <InputError :message="errors.sftp_port" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="sftp_username">Username</Label>
                    <Input id="sftp_username" name="sftp_username" :default-value="s?.sftp_username ?? ''" autocomplete="off" />
                    <InputError :message="errors.sftp_username" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="sftp_password">Password</Label>
                    <Input id="sftp_password" name="sftp_password" type="password" autocomplete="new-password" :placeholder="s?.has_password ? '•••••• (unchanged)' : 'Or use a private key'" />
                    <label v-if="s?.has_password" class="d-flex align-items-center gap-2 small"><input type="checkbox" name="clear_password" value="1" class="form-check-input m-0" /> Remove the saved password</label>
                    <InputError :message="errors.sftp_password" />
                </div>
                <div class="ss-grid gap-1 ss-span-sm-2">
                    <Label for="sftp_private_key">Private key</Label>
                    <textarea
                        id="sftp_private_key"
                        name="sftp_private_key"
                        rows="3"
                        class="form-control font-monospace small"
                        :placeholder="s?.has_key ? '(saved key unchanged)' : '-----BEGIN OPENSSH PRIVATE KEY----- ... (optional, instead of a password)'"
                    ></textarea>
                    <label v-if="s?.has_key" class="d-flex align-items-center gap-2 small"><input type="checkbox" name="clear_key" value="1" class="form-check-input m-0" /> Remove the saved key</label>
                    <InputError :message="errors.sftp_private_key" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="sftp_path">Remote folder</Label>
                    <Input id="sftp_path" name="sftp_path" :default-value="s?.sftp_path ?? ''" placeholder="/uploads/cdrs (blank = home folder)" class="font-monospace" />
                    <InputError :message="errors.sftp_path" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="sftp_fingerprint">Host key fingerprint</Label>
                    <Input id="sftp_fingerprint" name="sftp_fingerprint" :default-value="s?.sftp_fingerprint ?? ''" placeholder="Optional: refuse any other server" class="font-monospace" />
                    <InputError :message="errors.sftp_fingerprint" />
                </div>
                <p class="small text-secondary mb-0 ss-span-sm-2">Use Run now on the list to try it: any SFTP or email problem is shown next to the report.</p>
            </div>
        </section>

        <div class="d-flex gap-2">
            <Button :disabled="processing">{{ s ? 'Save changes' : 'Create schedule' }}</Button>
            <Button variant="ghost" as-child><Link :href="index()">Cancel</Link></Button>
        </div>
    </Form>
</template>
