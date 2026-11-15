<script setup lang="ts">
/** Settings → Admin users (owners only): who manages the server, their access level, and invitations. */
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminUserController from '@/actions/App/Http/Controllers/Settings/AdminUserController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date, dateTime } from '@/lib/format';
import { selectClass } from '@/lib/ui';
import { index } from '@/routes/admin-users';

type Role = { value: string; label: string; description: string };
type AdminUser = { id: number; name: string; email: string; role: string; two_factor: boolean; last_seen: string | null; created_at: string; me: boolean };
type Invitation = { id: number; email: string; role: string; invited_by: string | null; expires_at: string; created_at: string; expired: boolean };

const props = defineProps<{ users: AdminUser[]; invitations: Invitation[]; roles: Role[]; mailConfigured: boolean }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Admin users', href: index() }] } });

const label = (role: string) => props.roles.find((r) => r.value === role)?.label ?? role;
const inviteRole = ref('admin');
const inviteDescription = computed(() => props.roles.find((r) => r.value === inviteRole.value)?.description);

// When email isn't set up (or sending failed), the owner gets the link to pass on.
const page = usePage();
const inviteLink = computed(() => (page.flash as Record<string, unknown> | undefined)?.inviteLink as { email: string; url: string } | undefined);
const copied = ref(false);
async function copy() {
    if (!inviteLink.value) return;
    try {
        await navigator.clipboard.writeText(inviteLink.value.url);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

function remove(u: AdminUser) {
    if (confirm(`Remove ${u.name} (${u.email})? They are signed out and can no longer sign in.`)) {
        router.delete(AdminUserController.destroy.url(u.id), { preserveScroll: true });
    }
}
function revoke(i: Invitation) {
    if (confirm(`Cancel the invitation for ${i.email}? The link stops working.`)) {
        router.delete(AdminUserController.revoke.url(i.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Admin users" />

    <h1 class="visually-hidden">Admin users</h1>

    <div class="ss-stack-3">
        <Heading variant="small" title="Admin users" description="People who can sign in to manage this server, and what each one can do" />

        <Alert v-if="inviteLink">
            <AlertTitle>Send this invitation link to {{ inviteLink.email }}</AlertTitle>
            <AlertDescription class="ss-stack-2">
                <p>{{ mailConfigured ? "The email couldn't be sent" : "Email isn't set up (Settings → Email), so it wasn't emailed" }}. The link works once and expires in 7 days.</p>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <code class="rounded bg-body-tertiary px-2 py-1 font-monospace small text-break">{{ inviteLink.url }}</code>
                    <Button size="sm" variant="outline" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</Button>
                </div>
            </AlertDescription>
        </Alert>

        <div class="table-responsive">
            <table class="table table-vcenter mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Access level</th>
                        <th>2FA</th>
                        <th>Last active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id">
                        <td>
                            <div class="fw-medium">{{ u.name }} <Badge v-if="u.me" variant="outline" class="ms-1">you</Badge></div>
                            <div class="small text-secondary">{{ u.email }}</div>
                        </td>
                        <td>
                            <span v-if="u.me">{{ label(u.role) }}</span>
                            <Form v-else v-bind="AdminUserController.update.form(u.id)" class="d-flex gap-2" :options="{ preserveScroll: true }" v-slot="{ processing }">
                                <select name="role" :value="u.role" :class="selectClass" class="form-select-sm" style="min-width: 11rem" :aria-label="`Access level for ${u.name}`">
                                    <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                                </select>
                                <Button size="sm" variant="outline" :disabled="processing">Save</Button>
                            </Form>
                        </td>
                        <td>
                            <Badge v-if="u.two_factor" variant="outline" class="text-success">On</Badge>
                            <span v-else class="text-secondary">Off</span>
                        </td>
                        <td class="text-secondary">{{ u.last_seen ? dateTime(u.last_seen) : '—' }}</td>
                        <td class="text-end">
                            <Button v-if="!u.me" size="sm" variant="outline" @click="remove(u)">Remove</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div v-if="invitations.length" class="ss-stack-3">
        <Heading variant="small" title="Invitations" description="Sent, but not accepted yet" />
        <div class="table-responsive">
            <table class="table table-vcenter mb-0">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Access level</th>
                        <th>Invited</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in invitations" :key="i.id">
                        <td class="fw-medium">{{ i.email }}</td>
                        <td>{{ label(i.role) }}</td>
                        <td class="text-secondary">{{ date(i.created_at) }}{{ i.invited_by ? ` by ${i.invited_by}` : '' }}</td>
                        <td>
                            <Badge v-if="i.expired" variant="secondary">expired</Badge>
                            <span v-else class="text-secondary">{{ dateTime(i.expires_at) }}</span>
                        </td>
                        <td class="text-end text-nowrap">
                            <Form v-bind="AdminUserController.resend.form(i.id)" class="d-inline" :options="{ preserveScroll: true }" v-slot="{ processing }">
                                <Button size="sm" variant="outline" :disabled="processing">{{ mailConfigured ? 'Resend' : 'New link' }}</Button>
                            </Form>
                            <Button size="sm" variant="outline" class="ms-2" @click="revoke(i)">Cancel</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ss-stack-3">
        <Heading variant="small" title="Invite someone" :description="mailConfigured ? 'They get an email with a link to choose their name and password.' : 'Email isn\'t set up, so you\'ll get the link to send them yourself.'" />
        <Form v-bind="AdminUserController.invite.form()" class="ss-stack-3" :reset-on-success="['email']" :options="{ preserveScroll: true }" v-slot="{ errors, processing }">
            <div class="ss-grid gap-3 ss-cols-sm-2">
                <div class="ss-grid gap-1">
                    <Label for="email">Email</Label>
                    <Input id="email" name="email" type="email" required placeholder="name@example.com" />
                    <InputError :message="errors.email" />
                </div>
                <div class="ss-grid gap-1">
                    <Label for="role">Access level</Label>
                    <select id="role" v-model="inviteRole" name="role" :class="selectClass">
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                    <InputError :message="errors.role" />
                </div>
            </div>
            <p class="text-secondary small mb-0">{{ inviteDescription }}</p>
            <div><Button :disabled="processing">Send invitation</Button></div>
        </Form>
    </div>

    <div class="ss-stack-2">
        <Heading variant="small" title="Access levels" />
        <dl class="row mb-0">
            <template v-for="r in roles" :key="r.value">
                <dt class="col-sm-3">{{ r.label }}</dt>
                <dd class="col-sm-9 text-secondary">{{ r.description }}</dd>
            </template>
        </dl>
    </div>
</template>
