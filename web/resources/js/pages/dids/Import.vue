<script setup lang="ts">
/**
 * Routing -> DIDs -> Import. Step 1: upload a CSV (a sample file shows the
 * layout). Step 2: match the file's columns to the DID fields, choose values
 * for columns the file doesn't have, check the preview, then import. Matching
 * rules are App\Services\DidImporter's.
 */
import { Form, Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, Upload } from '@lucide/vue';
import { computed } from 'vue';
import DidImportController from '@/actions/App/Http/Controllers/Admin/DidImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';
import { sample, show } from '@/routes/did-import';
import { index } from '@/routes/dids';

type Field = 'number' | 'customer' | 'provider' | 'device_id' | 'extension' | 'language' | 'status' | 'status_reason' | 'comment';
type Account = { id: number; name: string; code: string };

const props = defineProps<{
    upload: { filename: string; preview: { header: string[]; rows: string[][]; suggested: Record<Field, number | null>; count: number } } | null;
    fields: Record<Field, string>;
    maxRows: number;
    customers: Account[];
    providers: Account[];
    languages: { code: string; name: string }[];
    statuses: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'DIDs', href: index() },
            { title: 'Import', href: show() },
        ],
    },
});

const fields = Object.entries(props.fields) as [Field, string][];
const preview = props.upload?.preview;

const form = useForm({
    has_header: true,
    add_one: true,
    existing: 'skip',
    columns: Object.fromEntries(fields.map(([f]) => [f, preview?.suggested[f] ?? null])) as Record<Field, number | null>,
    defaults: { account_id: null as number | null, provider_account_id: null as number | null, device_id: '' as string, language: 'en', status: 'active' },
});

const dataRows = computed(() => (!preview ? [] : form.has_header ? preview.rows : [preview.header, ...preview.rows]));
const width = computed(() => (!preview ? 0 : Math.max(preview.header.length, ...preview.rows.map((r) => r.length))));
const columnOptions = computed(() =>
    Array.from({ length: width.value }, (_, i) => {
        const sampleValue = dataRows.value.find((r) => (r[i] ?? '') !== '')?.[i] ?? '';
        const name = form.has_header ? preview!.header[i] || `Column ${i + 1}` : `Column ${i + 1}`;

        return { index: i, label: sampleValue ? `${name}  (e.g. ${sampleValue})` : name };
    }),
);

const cell = (row: string[], f: Field) => (form.columns[f] === null ? '' : (row[form.columns[f] as number] ?? '').trim());
function numberOf(row: string[]) {
    const n = cell(row, 'number').replace(/\D/g, '');
    return form.add_one && /^[2-9]\d{2}[2-9]\d{6}$/.test(n) ? `1${n}` : n;
}
const nameOf = (list: Account[], id: number | null) => list.find((a) => a.id === id)?.code ?? '';
const rows = computed(() =>
    dataRows.value.map((row) => {
        const device = cell(row, 'device_id');
        const extension = cell(row, 'extension');
        return {
            number: numberOf(row),
            customer: cell(row, 'customer') || nameOf(props.customers, form.defaults.account_id),
            provider: cell(row, 'provider') || nameOf(props.providers, form.defaults.provider_account_id),
            destination: device ? `Device ID ${device}` : extension ? `Extension ${extension}` : form.defaults.device_id ? `Device ID ${form.defaults.device_id}` : '',
            language: (cell(row, 'language') || form.defaults.language).toLowerCase(),
            status: (cell(row, 'status') || form.defaults.status).toLowerCase(),
            comment: cell(row, 'comment'),
        };
    }),
);
const rowErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([k]) => k.startsWith('rows.'))
        .sort(([a], [b]) => Number(a.slice(5)) - Number(b.slice(5)))
        .map(([, v]) => v),
);
const columnError = (f: Field) => (form.errors as Record<string, string>)[`columns.${f}`];
const noCustomer = computed(() => form.columns.customer === null);
const noProvider = computed(() => form.columns.provider === null);

function start() {
    form.post(DidImportController.store.url(), { preserveScroll: true });
}
function cancel() {
    router.post(DidImportController.cancel.url());
}
</script>

<template>
    <Head title="Import DIDs" />

    <div class="ss-maxw-5xl ss-stack-4">
        <Heading title="Import DIDs" description="Add or update many DIDs from a CSV file." />

        <!-- Step 1: upload -->
        <section v-if="!upload" class="card card-body ss-stack-3">
            <p class="mb-0">
                One DID per row. Columns can be in any order and have any names: you match them to the fields after uploading. Customers are matched by
                account code, providers by carrier code or name, and the destination is a <strong>PBX/Device ID</strong> (shown on the DID edit page) or an
                extension/IVR number. Up to {{ maxRows.toLocaleString() }} DIDs per file.
            </p>
            <div>
                <Button variant="outline" as-child><a :href="sample().url" download><Download class="icon" /> Download sample file</a></Button>
            </div>
            <Form v-bind="DidImportController.upload.form()" class="d-flex flex-wrap align-items-start gap-2" v-slot="{ errors, processing }">
                <div class="ss-grid gap-1">
                    <Label for="file" class="visually-hidden">CSV file</Label>
                    <Input id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required />
                    <InputError :message="errors.file" />
                </div>
                <Button :disabled="processing"><Upload class="icon" /> Upload</Button>
            </Form>
        </section>

        <!-- Step 2: match columns -->
        <section v-else class="ss-stack-3 rounded border border-primary p-3">
            <Heading
                variant="small"
                :title="`Match the columns in ${upload.filename}`"
                :description="`${upload.preview.count} row(s) after the first. Choose which column holds each field, or a value for all rows, check the preview, then import.`"
            />

            <label class="d-flex align-items-center gap-2">
                <input v-model="form.has_header" type="checkbox" class="form-check-input m-0" />
                The first row is a header (column names), not a DID
            </label>

            <div class="ss-grid gap-2 ss-cols-sm-3">
                <div v-for="[f, label] in fields" :key="f" class="ss-grid gap-1">
                    <Label :for="`col_${f}`">{{ label }} <span v-if="f === 'number'" class="small text-secondary">(required)</span></Label>
                    <select :id="`col_${f}`" v-model="form.columns[f]" :class="selectClass">
                        <option :value="null">— Not in file —</option>
                        <option v-for="c in columnOptions" :key="c.index" :value="c.index">{{ c.label }}</option>
                    </select>
                    <InputError :message="columnError(f)" />
                </div>
            </div>

            <h3 class="fw-semibold fs-5 mb-0">For every row</h3>
            <p class="small text-secondary mb-0">Used where the file has no such column, or the cell is empty.</p>
            <div class="ss-grid gap-2 ss-cols-sm-3">
                <div class="ss-grid gap-1">
                    <Label for="def_customer">Customer</Label>
                    <select id="def_customer" v-model="form.defaults.account_id" :class="selectClass" :disabled="!noCustomer">
                        <option :value="null">{{ noCustomer ? '— Choose —' : 'From the file' }}</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                    </select>
                </div>
                <div class="ss-grid gap-1">
                    <Label for="def_provider">Provider</Label>
                    <select id="def_provider" v-model="form.defaults.provider_account_id" :class="selectClass" :disabled="!noProvider">
                        <option :value="null">{{ noProvider ? '— Choose —' : 'From the file' }}</option>
                        <option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
                    </select>
                </div>
                <div class="ss-grid gap-1">
                    <Label for="def_device">PBX/Device ID</Label>
                    <Input id="def_device" v-model="form.defaults.device_id" type="number" min="1" placeholder="None" />
                    <InputError :message="(form.errors as Record<string, string>)['defaults.device_id']" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="def_language">Language</Label>
                    <select id="def_language" v-model="form.defaults.language" :class="selectClass">
                        <option v-for="l in languages" :key="l.code" :value="l.code">{{ l.name }}</option>
                    </select>
                </div>
                <div class="ss-grid gap-1">
                    <Label for="def_status">Status</Label>
                    <select id="def_status" v-model="form.defaults.status" :class="selectClass">
                        <option value="active">{{ statuses.active }}</option>
                        <option value="deactivated">{{ statuses.deactivated }}</option>
                    </select>
                </div>
                <div class="ss-grid gap-1">
                    <Label for="existing">DIDs already on the switch</Label>
                    <select id="existing" v-model="form.existing" :class="selectClass">
                        <option value="skip">Skip them (leave as they are)</option>
                        <option value="update">Update them from the file</option>
                    </select>
                </div>
            </div>
            <label class="d-flex align-items-center gap-2">
                <input v-model="form.add_one" type="checkbox" class="form-check-input m-0" />
                Add 1 to 10-digit North American numbers (4165551234 → 14165551234)
            </label>

            <div v-if="rowErrors.length" class="alert alert-danger mb-0">
                <p class="fw-medium mb-1">Nothing was imported. Fix these rows and upload the file again:</p>
                <ul class="mb-0">
                    <li v-for="(e, i) in rowErrors" :key="i">{{ e }}</li>
                </ul>
            </div>
            <InputError :message="(form.errors as Record<string, string>).file" />

            <div class="card table-responsive">
                <table class="table table-vcenter card-table table-tight">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Customer</th>
                            <th>Provider</th>
                            <th>Destination</th>
                            <th>Language</th>
                            <th>Status</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, n) in rows" :key="n">
                            <td class="font-monospace" :class="{ 'text-danger': !/^\d{7,20}$/.test(r.number) }">{{ r.number || '(none)' }}</td>
                            <td :class="{ 'text-danger': !r.customer }">{{ r.customer || '(none)' }}</td>
                            <td :class="{ 'text-danger': !r.provider }">{{ r.provider || '(none)' }}</td>
                            <td :class="{ 'text-danger': !r.destination }">{{ r.destination || '(none)' }}</td>
                            <td>{{ r.language }}</td>
                            <td>{{ statuses[r.status] ?? r.status }}</td>
                            <td class="text-secondary">{{ r.comment }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-secondary">The first {{ rows.length }} row(s) of the file, as they will be imported. Every row is checked before anything is saved.</p>

            <div class="d-flex gap-2">
                <Button :disabled="form.processing || form.columns.number === null" @click="start">Import</Button>
                <Button variant="ghost" :disabled="form.processing" @click="cancel">Choose another file</Button>
                <Button variant="ghost" as-child><Link :href="index()">Cancel</Link></Button>
            </div>
        </section>
    </div>
</template>
