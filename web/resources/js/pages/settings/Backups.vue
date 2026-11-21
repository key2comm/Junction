<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Download, Play, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import BackupController from '@/actions/App/Http/Controllers/Settings/BackupController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateTime } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { destroy, download, edit, run } from '@/routes/backups';
import { edit as editEmail } from '@/routes/email';

type Settings = {
    enabled: boolean;
    time: string;
    timezone: string;
    retention_days: number;
    unencrypted_ok: boolean;
    email_to: string | null;
    sftp_enabled: boolean;
    sftp_host: string | null;
    sftp_port: number;
    sftp_username: string | null;
    sftp_path: string | null;
    sftp_fingerprint: string | null;
    sftp_keep_days: number;
    s3_enabled: boolean;
    s3_provider: string;
    s3_endpoint: string | null;
    s3_region: string | null;
    s3_bucket: string | null;
    s3_key: string | null;
    s3_path: string | null;
    s3_keep_days: number;
    has_password: boolean;
    has_sftp_password: boolean;
    has_sftp_key: boolean;
    has_s3_secret: boolean;
};
type Backup = {
    id: number;
    filename: string | null;
    size: number | null;
    encrypted: boolean;
    status: 'running' | 'done' | 'failed';
    trigger: 'schedule' | 'manual';
    started_at: string;
    finished_at: string | null;
    emailed_at: string | null;
    sftp_at: string | null;
    s3_at: string | null;
    error: string | null;
    available: boolean;
};

const props = defineProps<{
    settings: Settings;
    backups: Backup[];
    next: string | null;
    requested: boolean;
    standby: boolean;
    providers: Record<string, { label: string; endpoint: string | null; region: string }>;
    timezones: string[];
    mailConfigured: boolean;
    diskFree: number | null;
    restore: string;
    restoreEncrypted: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Backups', href: edit() }] } });

const s = props.settings;
const sftp = ref(s.sftp_enabled);
const s3 = ref(s.s3_enabled);
const provider = ref(s.s3_provider);
const p = computed(() => props.providers[provider.value]);
const needsEndpoint = computed(() => !p.value?.endpoint);
const endpointHint = computed(() =>
    p.value?.endpoint
        ? p.value.endpoint.replace('{region}', p.value.region)
        : provider.value === 'cloudflare'
          ? 'https://ACCOUNT-ID.r2.cloudflarestorage.com'
          : 'https://s3.example.com',
);

const size = (b: number | null) => (b === null ? '—' : b >= 1073741824 ? `${(b / 1073741824).toFixed(2)} GB` : `${(b / 1048576).toFixed(1)} MB`);
const badge = (b: Backup) => (b.status === 'failed' ? 'destructive' : b.status === 'running' ? 'secondary' : b.error ? 'outline' : 'default');
const statusLabel = (b: Backup) => (b.status === 'running' ? 'Running' : b.status === 'failed' ? 'Failed' : b.error ? 'Made, some copies failed' : 'Done');

function remove(b: Backup) {
    if (confirm(`Delete ${b.filename} from this server? Copies sent by email, SFTP or S3 are kept.`)) {
        router.delete(destroy.url(b.id), { preserveScroll: true });
    }
}
const runNow = () => router.post(run.url(), {}, { preserveScroll: true });
</script>

<template>
    <Head title="Backups" />

    <h1 class="visually-hidden">Backups</h1>

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading
                variant="small"
                title="Database backups"
                description="A backup of the whole database (accounts, rates, routing, DIDs, PBX, billing and call records) made every night, kept on this server and optionally sent elsewhere."
            />
            <Button variant="outline" :disabled="requested || standby" @click="runNow"><Play class="icon" /> {{ requested ? 'Starting…' : 'Run now' }}</Button>
        </div>

        <div v-if="standby" class="alert alert-info mb-0">This is the backup (standby) server: backups are made on the main server, with the same settings.</div>

        <Form v-bind="BackupController.update.form()" class="ss-stack-4 ss-maxw-3xl" v-slot="{ errors, processing }">
            <section class="card card-body ss-grid gap-3 ss-cols-sm-2">
                <label class="d-flex align-items-center gap-2 ss-span-sm-2 fw-semibold">
                    <input type="checkbox" name="enabled" value="1" :checked="s.enabled" class="form-check-input m-0" /> Back up every night
                </label>
                <div class="ss-grid gap-1">
                    <Label for="time">At</Label>
                    <Input id="time" name="time" type="time" :default-value="s.time" required />
                    <InputError :message="errors.time" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="timezone">Time zone</Label>
                    <select id="timezone" name="timezone" :class="selectClass">
                        <option v-for="z in timezones" :key="z" :value="z" :selected="z === s.timezone">{{ z }}</option>
                    </select>
                    <InputError :message="errors.timezone" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="retention_days">Keep on this server for</Label>
                    <div class="input-group">
                        <Input id="retention_days" name="retention_days" type="number" min="1" max="365" :default-value="s.retention_days" required />
                        <span class="input-group-text">days</span>
                    </div>
                    <p class="small text-secondary mb-0">
                        <template v-if="diskFree">{{ size(diskFree) }} free on this server. </template>A backup is skipped rather than fill the disk.
                    </p>
                    <InputError :message="errors.retention_days" />
                </div>
                <p v-if="next && !standby" class="small text-secondary mb-0 align-self-end ss-span-sm-2">Next backup: {{ dateTime(next) }}.</p>
            </section>

            <section class="card card-body ss-stack-3">
                <div>
                    <h2 class="fw-semibold fs-4 mb-1">Encryption</h2>
                    <p class="small text-secondary mb-0">
                        With a password, each backup is encrypted (AES-256) and also holds this switch's app key, which a new server needs to read saved SIP and
                        SMTP passwords. Keep the password somewhere safe: without it a backup can't be restored. Strongly recommended for backups sent off the
                        server.
                    </p>
                </div>
                <div class="ss-grid gap-1 ss-maxw-md">
                    <Label for="password">Backup password</Label>
                    <Input id="password" name="password" type="password" autocomplete="new-password" :placeholder="s.has_password ? '•••••• (unchanged)' : 'At least 12 characters'" />
                    <label v-if="s.has_password" class="d-flex align-items-center gap-2 small">
                        <input type="checkbox" name="clear_password" value="1" class="form-check-input m-0" /> Remove the password (new backups aren't encrypted)
                    </label>
                    <label v-else class="d-flex align-items-center gap-2 small">
                        <input type="checkbox" name="unencrypted_ok" value="1" :checked="s.unencrypted_ok" class="form-check-input m-0" /> Send them unencrypted (not recommended)
                    </label>
                    <InputError :message="errors.password" />
                </div>
            </section>

            <section class="card card-body ss-stack-3">
                <div>
                    <h2 class="fw-semibold fs-4 mb-1">Email</h2>
                    <p class="small text-secondary mb-0">
                        Optional. Backups up to 10 MB are attached; bigger ones get a link to this page. A failed backup is emailed too.
                        <template v-if="!mailConfigured">
                            Email isn't set up yet: <Link :href="editEmail()" class="fw-medium">Settings → Email (SMTP)</Link>.
                        </template>
                    </p>
                </div>
                <div class="ss-grid gap-1">
                    <Label for="email_to">Send to</Label>
                    <Input id="email_to" name="email_to" :default-value="s.email_to ?? ''" placeholder="noc@example.com, it@example.com" />
                    <InputError :message="errors.email_to" />
                </div>
            </section>

            <section class="card card-body ss-stack-3">
                <label class="d-flex align-items-center gap-2 fw-semibold">
                    <input v-model="sftp" type="checkbox" name="sftp_enabled" value="1" class="form-check-input m-0" /> Upload by SFTP
                </label>
                <div v-show="sftp" class="ss-grid gap-3 ss-cols-sm-2">
                    <div class="ss-grid gap-1">
                        <Label for="sftp_host">Host</Label>
                        <Input id="sftp_host" name="sftp_host" :default-value="s.sftp_host ?? ''" placeholder="backup.example.com" class="font-monospace" />
                        <InputError :message="errors.sftp_host" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="sftp_port">Port</Label>
                        <Input id="sftp_port" name="sftp_port" type="number" min="1" max="65535" :default-value="s.sftp_port ?? 22" />
                        <InputError :message="errors.sftp_port" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="sftp_username">Username</Label>
                        <Input id="sftp_username" name="sftp_username" :default-value="s.sftp_username ?? ''" autocomplete="off" />
                        <InputError :message="errors.sftp_username" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="sftp_password">Password</Label>
                        <Input id="sftp_password" name="sftp_password" type="password" autocomplete="new-password" :placeholder="s.has_sftp_password ? '•••••• (unchanged)' : 'Or use a private key'" />
                        <label v-if="s.has_sftp_password" class="d-flex align-items-center gap-2 small"><input type="checkbox" name="clear_sftp_password" value="1" class="form-check-input m-0" /> Remove the saved password</label>
                        <InputError :message="errors.sftp_password" />
                    </div>
                    <div class="ss-grid gap-1 ss-span-sm-2">
                        <Label for="sftp_private_key">Private key</Label>
                        <textarea
                            id="sftp_private_key"
                            name="sftp_private_key"
                            rows="3"
                            class="form-control font-monospace small"
                            :placeholder="s.has_sftp_key ? '(saved key unchanged)' : '-----BEGIN OPENSSH PRIVATE KEY----- ... (optional, instead of a password)'"
                        ></textarea>
                        <label v-if="s.has_sftp_key" class="d-flex align-items-center gap-2 small"><input type="checkbox" name="clear_sftp_key" value="1" class="form-check-input m-0" /> Remove the saved key</label>
                        <InputError :message="errors.sftp_private_key" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="sftp_path">Remote folder</Label>
                        <Input id="sftp_path" name="sftp_path" :default-value="s.sftp_path ?? ''" placeholder="/backups/softswitch (blank = home folder)" class="font-monospace" />
                        <InputError :message="errors.sftp_path" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="sftp_fingerprint">Host key fingerprint</Label>
                        <Input id="sftp_fingerprint" name="sftp_fingerprint" :default-value="s.sftp_fingerprint ?? ''" placeholder="Optional: refuse any other server" class="font-monospace" />
                        <InputError :message="errors.sftp_fingerprint" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="sftp_keep_days">Keep there for</Label>
                        <div class="input-group">
                            <Input id="sftp_keep_days" name="sftp_keep_days" type="number" min="0" max="3650" :default-value="s.sftp_keep_days" required />
                            <span class="input-group-text">days</span>
                        </div>
                        <p class="small text-secondary mb-0">0 = never delete them there.</p>
                        <InputError :message="errors.sftp_keep_days" />
                    </div>
                </div>
            </section>

            <section class="card card-body ss-stack-3">
                <label class="d-flex align-items-center gap-2 fw-semibold">
                    <input v-model="s3" type="checkbox" name="s3_enabled" value="1" class="form-check-input m-0" /> Upload to S3 or S3-compatible storage
                </label>
                <div v-show="s3" class="ss-grid gap-3 ss-cols-sm-2">
                    <div class="ss-grid gap-1 ss-span-sm-2">
                        <Label for="s3_provider">Storage</Label>
                        <select id="s3_provider" v-model="provider" name="s3_provider" :class="selectClass">
                            <option v-for="(pr, k) in providers" :key="k" :value="k">{{ pr.label }}</option>
                        </select>
                        <InputError :message="errors.s3_provider" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_region">Region</Label>
                        <Input id="s3_region" name="s3_region" :default-value="s.s3_region ?? ''" :placeholder="p?.region" class="font-monospace" />
                        <InputError :message="errors.s3_region" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_endpoint">Endpoint{{ needsEndpoint ? '' : ' (optional)' }}</Label>
                        <Input
                            id="s3_endpoint"
                            name="s3_endpoint"
                            :default-value="s.s3_endpoint ?? ''"
                            :placeholder="endpointHint"
                            class="font-monospace"
                        />
                        <InputError :message="errors.s3_endpoint" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_bucket">Bucket (Space)</Label>
                        <Input id="s3_bucket" name="s3_bucket" :default-value="s.s3_bucket ?? ''" placeholder="my-switch-backups" class="font-monospace" />
                        <InputError :message="errors.s3_bucket" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_path">Folder in the bucket</Label>
                        <Input id="s3_path" name="s3_path" :default-value="s.s3_path ?? ''" placeholder="softswitch (optional)" class="font-monospace" />
                        <InputError :message="errors.s3_path" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_key">Access key</Label>
                        <Input id="s3_key" name="s3_key" :default-value="s.s3_key ?? ''" autocomplete="off" class="font-monospace" />
                        <InputError :message="errors.s3_key" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_secret">Secret key</Label>
                        <Input id="s3_secret" name="s3_secret" type="password" autocomplete="new-password" :placeholder="s.has_s3_secret ? '•••••• (unchanged)' : ''" />
                        <InputError :message="errors.s3_secret" />
                    </div>
                    <div class="ss-grid gap-1">
                        <Label for="s3_keep_days">Keep there for</Label>
                        <div class="input-group">
                            <Input id="s3_keep_days" name="s3_keep_days" type="number" min="0" max="3650" :default-value="s.s3_keep_days" required />
                            <span class="input-group-text">days</span>
                        </div>
                        <p class="small text-secondary mb-0">0 = never delete them (or let a lifecycle rule on the bucket do it).</p>
                        <InputError :message="errors.s3_keep_days" />
                    </div>
                    <p class="small text-secondary mb-0 ss-span-sm-2">
                        The key needs to list, upload and delete in that bucket. Use Run now to try it: any problem is shown next to the backup below.
                    </p>
                </div>
            </section>

            <div>
                <Button :disabled="processing">Save</Button>
            </div>
        </Form>

        <section class="ss-stack-2">
            <h2 class="fw-semibold fs-4 mb-0">Backups on this server</h2>
            <div class="card table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Made</th>
                            <th>File</th>
                            <th class="text-end">Size</th>
                            <th>Status</th>
                            <th>Copies</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in backups" :key="b.id">
                            <td class="text-nowrap">
                                {{ dateTime(b.started_at) }}
                                <div v-if="b.trigger === 'manual'" class="small text-secondary">Run now</div>
                            </td>
                            <td class="font-monospace small">
                                {{ b.filename }}
                                <Badge v-if="b.encrypted" variant="outline" class="ms-1">Encrypted</Badge>
                            </td>
                            <td class="text-end text-nowrap">{{ size(b.size) }}</td>
                            <td>
                                <Badge :variant="badge(b)">{{ statusLabel(b) }}</Badge>
                                <div v-if="b.error" class="small text-danger mt-1">{{ b.error }}</div>
                            </td>
                            <td class="small text-nowrap">
                                <div v-if="b.emailed_at">Emailed</div>
                                <div v-if="b.sftp_at">SFTP</div>
                                <div v-if="b.s3_at">S3</div>
                                <span v-if="!b.emailed_at && !b.sftp_at && !b.s3_at" class="text-secondary">—</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <Button v-if="b.available" size="sm" variant="ghost" as-child>
                                    <a :href="download.url(b.id)" :aria-label="`Download ${b.filename}`"><Download class="icon" /></a>
                                </Button>
                                <Button v-if="b.status !== 'running'" size="sm" variant="ghost" :aria-label="`Delete ${b.filename}`" @click="remove(b)"><Trash2 class="icon" /></Button>
                            </td>
                        </tr>
                        <tr v-if="backups.length === 0">
                            <td colspan="6" class="text-center text-secondary">No backups yet. Use Run now to make the first one.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card card-body small ss-stack-2 ss-maxw-3xl">
            <h2 class="fw-semibold fs-4 mb-0">Restoring a backup</h2>
            <p class="mb-0 text-secondary">
                On this server, or a new one installed with the same or a newer version (as root). This replaces everything in the database with the backup.
            </p>
            <div class="fw-medium">Encrypted backup (.tar.enc)</div>
            <pre class="mb-0 small">{{ restoreEncrypted }}</pre>
            <div class="fw-medium">Unencrypted backup (.tar)</div>
            <pre class="mb-0 small">{{ restore }}</pre>
            <p class="mb-0 text-secondary">
                On a new server, also put the APP_KEY line from app-key.txt (encrypted backups only) into /var/www/html/.env, then run
                <code>sudo -u softswitch php /var/www/html/artisan config:cache</code>. README.txt in each backup has the same steps.
            </p>
        </section>
    </div>
</template>
