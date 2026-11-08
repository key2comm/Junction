<script setup lang="ts">
/**
 * Step 2 of a rate import: match the uploaded file's columns to fields
 * (Prefix, NPA, NXX, Interval, Rate, Date effective...), check the preview,
 * then start the import. The prefix rule matches App\Services\RateImporter::prefixFor().
 */
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import RateImportController from '@/actions/App/Http/Controllers/Admin/RateImportController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';

type Field = 'prefix' | 'npa' | 'nxx' | 'interval' | 'increment' | 'rate' | 'effective_from' | 'destination' | 'connect_fee';

const props = defineProps<{
    deckId: number;
    mapping: {
        id: number;
        filename: string;
        preview: { header: string[]; rows: string[][]; suggested: Record<Field, number | null> };
        fields: Record<Field, string>;
    };
}>();

const fields = Object.entries(props.mapping.fields) as [Field, string][];
const required: Partial<Record<Field, string>> = { rate: 'required', prefix: 'or NPA', npa: 'or Prefix', increment: 'if separate' };

const form = useForm({
    columns: Object.fromEntries(fields.map(([f]) => [f, props.mapping.preview.suggested[f] ?? null])) as Record<Field, number | null>,
    has_header: true,
    npa_country: '1',
    mode: 'update',
    effective_from: '',
});

const width = computed(() => Math.max(props.mapping.preview.header.length, ...props.mapping.preview.rows.map((r) => r.length)));
const columnOptions = computed(() =>
    Array.from({ length: width.value }, (_, i) => {
        const sample = dataRows.value.find((r) => (r[i] ?? '') !== '')?.[i] ?? '';
        const name = form.has_header ? props.mapping.preview.header[i] || `Column ${i + 1}` : `Column ${i + 1}`;

        return { index: i, label: sample ? `${name}  (e.g. ${sample})` : name };
    }),
);
const dataRows = computed(() => (form.has_header ? props.mapping.preview.rows : [props.mapping.preview.header, ...props.mapping.preview.rows]));

const cell = (row: string[], f: Field) => (form.columns[f] === null ? '' : (row[form.columns[f] as number] ?? '').trim());
const clean = (v: string) => v.replace(/[\s+\-()]/g, '');
function prefixOf(row: string[]) {
    const prefix = clean(cell(row, 'prefix'));
    const npa = clean(cell(row, 'npa'));
    const tail = npa + clean(cell(row, 'nxx'));
    if (npa === '') return prefix;
    if (prefix === '') return clean(form.npa_country) + tail;

    return prefix.endsWith(tail) ? prefix : prefix + tail;
}
function intervalOf(row: string[]) {
    const v = cell(row, 'interval');
    if (v === '') return '60/60';
    const [a, b] = v.split(/\s*[/\-:x]\s*/i);
    const next = cell(row, 'increment');

    return `${a}/${b ?? (next || a)}`;
}
const preview = computed(() =>
    dataRows.value.map((row) => ({
        prefix: prefixOf(row),
        rate: cell(row, 'rate'),
        interval: intervalOf(row),
        effective: cell(row, 'effective_from') || (form.effective_from ? form.effective_from.replace('T', ' ') : 'the import time'),
        destination: cell(row, 'destination'),
    })),
);
const usesNpa = computed(() => form.columns.npa !== null);

function start() {
    form.post(RateImportController.start.url([props.deckId, props.mapping.id]), { preserveScroll: true });
}
function cancel() {
    router.delete(RateImportController.cancel.url([props.deckId, props.mapping.id]), { preserveScroll: true });
}
const errorFor = (f: Field) => (form.errors as Record<string, string>)[`columns.${f}`];
</script>

<template>
    <section class="ss-stack-3 rounded border border-primary p-3">
        <Heading variant="small" :title="`Match the columns in ${mapping.filename}`" description="Choose which column in the file holds each field, check the preview, then import." />

        <label class="d-flex align-items-center gap-2">
            <input v-model="form.has_header" type="checkbox" class="form-check-input m-0" />
            The first row is a header (column names), not rates
        </label>

        <div class="ss-grid gap-2 ss-cols-sm-2">
            <div v-for="[f, label] in fields" :key="f" class="ss-grid gap-1">
                <Label :for="`col_${f}`">
                    {{ label }} <span v-if="required[f]" class="small text-secondary">({{ required[f] }})</span>
                </Label>
                <select :id="`col_${f}`" v-model="form.columns[f]" :class="selectClass">
                    <option :value="null">— Not in file —</option>
                    <option v-for="c in columnOptions" :key="c.index" :value="c.index">{{ c.label }}</option>
                </select>
                <InputError :message="errorFor(f)" />
            </div>
        </div>
        <InputError :message="form.errors.columns" />

        <div class="ss-grid gap-3 ss-cols-sm-3">
            <div v-if="usesNpa" class="ss-grid gap-1">
                <Label for="npa_country">Country code before NPA</Label>
                <Input id="npa_country" v-model="form.npa_country" class="ss-w-24 font-monospace" />
                <p class="small text-secondary">Used when a row has no prefix of its own.</p>
                <InputError :message="form.errors.npa_country" />
            </div>
            <div class="ss-grid gap-1">
                <Label for="map_mode">Mode</Label>
                <select id="map_mode" v-model="form.mode" :class="selectClass">
                    <option value="update">Update: change only the prefixes in the file</option>
                    <option value="replace">Replace: the file is the whole list</option>
                </select>
            </div>
            <div class="ss-grid gap-1">
                <Label for="map_effective">Effective from (UTC)</Label>
                <Input id="map_effective" v-model="form.effective_from" type="datetime-local" />
                <p class="small text-secondary">Blank = immediately. A "Date effective" column overrides this per row.</p>
                <InputError :message="form.errors.effective_from" />
            </div>
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Prefix</th>
                        <th>Destination</th>
                        <th class="text-end">Rate</th>
                        <th>Interval</th>
                        <th>Effective</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(p, n) in preview" :key="n">
                        <td class="font-monospace" :class="{ 'text-danger': !/^[0-9]{1,32}$/.test(p.prefix) }">{{ p.prefix || '(none)' }}</td>
                        <td>{{ p.destination }}</td>
                        <td class="text-end font-monospace">{{ p.rate || '(none)' }}</td>
                        <td class="font-monospace">{{ p.interval }}</td>
                        <td class="text-secondary">{{ p.effective }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="small text-secondary">The first {{ preview.length }} row(s) of the file, as they will be imported.</p>

        <div class="d-flex gap-2">
            <Button :disabled="form.processing" @click="start">Import</Button>
            <Button variant="ghost" :disabled="form.processing" @click="cancel">Cancel</Button>
        </div>
    </section>
</template>
