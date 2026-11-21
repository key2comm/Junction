<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { reactive, ref } from 'vue';
import AuditLogController from '@/actions/App/Http/Controllers/Settings/AuditLogController';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SearchSelect } from '@/components/ui/search-select';
import type { SearchSelectOption } from '@/components/ui/search-select';
import { useAccess } from '@/composables/useAccess';
import { dateTime } from '@/lib/format';
import type { Paginated } from '@/lib/ui';
import { selectClass } from '@/lib/ui';
import { show as showAccount } from '@/routes/accounts';
import { index } from '@/routes/audit';

type Change = { event: 'created' | 'updated' | 'deleted'; type: string; id: number | string | null; label: string | null; fields: Record<string, [unknown, unknown]> };
type Log = {
    id: number;
    created_at: string;
    user_id: number | null;
    user_name: string | null;
    user_email: string | null;
    via: 'web' | 'portal' | 'api';
    event: 'change' | 'login' | 'logout' | 'login_failed';
    action: string | null;
    method: string | null;
    path: string | null;
    ip: string | null;
    subject_type: string | null;
    subject_label: string | null;
    changes: Change[] | null;
    input: Record<string, unknown> | null;
    account: { id: number; name: string; code: string } | null;
    summary: string;
};

const props = defineProps<{
    logs: Paginated<Log>;
    filters: { q: string; user: number | null; event: string; via: string; type: string; from: string; to: string };
    users: { id: number; name: string; email: string }[];
    types: string[];
    events: Record<string, string>;
    via: Record<string, string>;
    retention: number;
    retentionChoices: number[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Audit log', href: index() }] } });

const { can } = useAccess();
const f = reactive({ ...props.filters, user: props.filters.user ?? ('' as number | '') });
const userOptions: SearchSelectOption<number | ''>[] = [{ value: '', label: 'Anyone' }, ...props.users.map((u) => ({ value: u.id, label: u.name, hint: u.email }))];
let timer: ReturnType<typeof setTimeout> | undefined;
function apply(delay = 0) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null));
        router.get(index.url({ query }), {}, { preserveState: true, replace: true });
    }, delay);
}

const open = ref<Set<number>>(new Set());
const toggle = (id: number) => (open.value.has(id) ? open.value.delete(id) : open.value.add(id));
const detailed = (l: Log) => (l.changes?.length ?? 0) > 0 || (l.input && Object.keys(l.input).length > 0);
const show = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));
const eventBadge = (e: Log['event']) => (e === 'login_failed' ? 'destructive' : e === 'change' ? 'default' : 'secondary');
const years = (d: number) => (d % 365 === 0 ? `${d / 365} year${d === 365 ? '' : 's'}` : `${d} days`);
</script>

<template>
    <Head title="Audit log" />

    <h1 class="visually-hidden">Audit log</h1>

    <div class="ss-stack-4">
        <Heading
            variant="small"
            title="Audit log"
            description="Who changed what, from where: every change made in the admin pages, the customer portal and the REST API, plus sign-ins. Passwords and keys are never shown, only that they changed. Visible to owners and admins."
        />

        <div class="d-flex flex-wrap gap-2">
            <Input v-model="f.q" type="search" placeholder="Search name, number, IP, value…" aria-label="Search the audit log" class="ss-maxw-xs" @input="apply(300)" />
            <SearchSelect v-model="f.user" :options="userOptions" class="ss-w-56" placeholder="Anyone" search-placeholder="Search admins…" @update:model-value="apply()" />
            <select v-model="f.event" :class="selectClass" class="w-auto" aria-label="What" @change="apply()">
                <option value="">Everything</option>
                <option v-for="(label, k) in events" :key="k" :value="k">{{ label }}</option>
            </select>
            <select v-model="f.type" :class="selectClass" class="w-auto" aria-label="Record type" @change="apply()">
                <option value="">Any record</option>
                <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
            <select v-model="f.via" :class="selectClass" class="w-auto" aria-label="Where" @change="apply()">
                <option value="">Admin, portal and API</option>
                <option v-for="(label, k) in via" :key="k" :value="k">{{ label }}</option>
            </select>
            <Input v-model="f.from" type="date" aria-label="From" class="w-auto" @change="apply()" />
            <Input v-model="f.to" type="date" aria-label="To" class="w-auto" @change="apply()" />
        </div>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1"></th>
                        <th>When</th>
                        <th>Who</th>
                        <th>What</th>
                        <th>From</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="l in logs.data" :key="l.id">
                        <tr :class="{ 'cursor-pointer': detailed(l) }" @click="detailed(l) && toggle(l.id)">
                            <td>
                                <template v-if="detailed(l)">
                                    <ChevronDown v-if="open.has(l.id)" class="icon" />
                                    <ChevronRight v-else class="icon" />
                                </template>
                            </td>
                            <td class="text-nowrap small">{{ dateTime(l.created_at) }}</td>
                            <td>
                                <div class="fw-medium">{{ l.user_name ?? l.user_email ?? 'Unknown' }}</div>
                                <div class="small text-secondary">
                                    <template v-if="l.user_name">{{ l.user_email }}</template>
                                    <template v-if="l.account">
                                        · <Link :href="showAccount(l.account.id)" @click.stop>{{ l.account.name }}</Link>
                                    </template>
                                </div>
                            </td>
                            <td>
                                <Badge v-if="l.event !== 'change'" :variant="eventBadge(l.event)" class="me-1">{{ events[l.event] }}</Badge>
                                <Badge v-if="l.via !== 'web'" variant="outline" class="me-1">{{ via[l.via] }}</Badge>
                                {{ l.event === 'change' ? l.summary : l.event === 'login_failed' ? (l.user_email ?? '') : '' }}
                                <div v-if="l.path && l.event === 'change'" class="small text-secondary font-monospace">{{ l.method }} {{ l.path }}</div>
                            </td>
                            <td class="font-monospace small">{{ l.ip }}</td>
                        </tr>
                        <tr v-if="open.has(l.id)">
                            <td></td>
                            <td colspan="4" class="bg-body-tertiary">
                                <div v-for="(c, i) in l.changes ?? []" :key="i" class="mb-2">
                                    <div class="fw-medium small">
                                        {{ { created: 'Added', updated: 'Changed', deleted: 'Deleted' }[c.event] }} {{ c.type }} {{ c.label ?? (c.id ? `#${c.id}` : '') }}
                                    </div>
                                    <table class="table table-sm small mb-0 ss-maxw-4xl">
                                        <tbody>
                                            <tr v-for="(pair, field) in c.fields" :key="field">
                                                <td class="font-monospace text-secondary text-nowrap w-1">{{ field }}</td>
                                                <td v-if="c.event === 'updated'" class="text-break">
                                                    <span class="text-danger">{{ show(pair[0]) }}</span> → <span class="text-success">{{ show(pair[1]) }}</span>
                                                </td>
                                                <td v-else class="text-break">{{ show(c.event === 'created' ? pair[1] : pair[0]) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-if="l.input && Object.keys(l.input).length">
                                    <div class="fw-medium small">Sent</div>
                                    <table class="table table-sm small mb-0 ss-maxw-4xl">
                                        <tbody>
                                            <tr v-for="(v, k) in l.input" :key="k">
                                                <td class="font-monospace text-secondary text-nowrap w-1">{{ k }}</td>
                                                <td class="text-break">{{ show(v) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="logs.data.length === 0">
                        <td colspan="5" class="text-center text-secondary">Nothing logged{{ Object.values(filters).some((v) => v) ? ' matches' : ' yet' }}.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="logs.links" :from="logs.from" :to="logs.to" :total="logs.total" />

        <Form v-if="can('audit', true)" v-bind="AuditLogController.update.form()" class="d-flex flex-wrap align-items-center gap-2 small" v-slot="{ processing }">
            <label for="retention_days" class="text-secondary">Keep entries for</label>
            <select id="retention_days" name="retention_days" :class="selectClass" class="w-auto form-select-sm">
                <option v-for="d in retentionChoices" :key="d" :value="d" :selected="d === retention">{{ years(d) }}</option>
            </select>
            <Button size="sm" variant="outline" :disabled="processing">Save</Button>
            <span class="text-secondary">Older entries are deleted each night. Only owners can change this.</span>
        </Form>
        <p v-else class="small text-secondary mb-0">Entries are kept {{ years(retention) }}.</p>
    </div>
</template>
