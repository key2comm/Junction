<script setup lang="ts">
/** Server settings (admin only): the pages under the top menu's Settings item, as tabs of one card. */
import { Link } from '@inertiajs/vue3';
import { BrickWall, CalendarClock, DatabaseBackup, ScrollText, FileAudio, KeyRound, Mail, Palette, Receipt, ShieldCheck, UserCog, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useAccess } from '@/composables/useAccess';
import type { AdminArea } from '@/composables/useAccess';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { index as adminUsers } from '@/routes/admin-users';
import { index as apiTokens } from '@/routes/api-tokens';
import { index as auditLog } from '@/routes/audit';
import { edit as editBackups } from '@/routes/backups';
import { edit as editBilling } from '@/routes/billing';
import { edit as editBranding } from '@/routes/branding';
import { edit as editCaptures } from '@/routes/captures';
import { edit as editCertificate } from '@/routes/certificate';
import { edit as editClientDefaults } from '@/routes/client-defaults';
import { edit as editEmail } from '@/routes/email';
import { index as firewall } from '@/routes/firewall';
import { index as reports } from '@/routes/reports';

const allTabs: { title: string; href: ReturnType<typeof firewall>; icon: typeof Mail; area: AdminArea }[] = [
    // Same order as the top menu's Settings dropdown.
    { title: 'SSL certificate', href: editCertificate(), icon: ShieldCheck, area: 'settings' },
    { title: 'Firewall', href: firewall(), icon: BrickWall, area: 'settings' },
    { title: 'Billing', href: editBilling(), icon: Receipt, area: 'billing' },
    { title: 'Branding', href: editBranding(), icon: Palette, area: 'settings' },
    { title: 'Call captures', href: editCaptures(), icon: FileAudio, area: 'settings' },
    { title: 'Client defaults', href: editClientDefaults(), icon: UserCog, area: 'settings' },
    { title: 'Email (SMTP)', href: editEmail(), icon: Mail, area: 'settings' },
    { title: 'Scheduled reports', href: reports(), icon: CalendarClock, area: 'settings' },
    { title: 'Backups', href: editBackups(), icon: DatabaseBackup, area: 'settings' },
    { title: 'API tokens', href: apiTokens(), icon: KeyRound, area: 'settings' },
    { title: 'Admin users', href: adminUsers(), icon: Users, area: 'users' },
    { title: 'Audit log', href: auditLog(), icon: ScrollText, area: 'audit' },
];
// Only the tabs this admin's access level can open.
const { can } = useAccess();
const tabs = computed(() => allTabs.filter((t) => can(t.area)));
const { isCurrentOrParentUrl } = useCurrentUrl();
// The firewall and reports pages have wide tables; the others are single forms.
const wide = computed(() => [firewall(), reports(), adminUsers(), editBackups(), auditLog()].some((h) => isCurrentOrParentUrl(h)));
</script>

<template>
    <div class="mb-3">
        <h2 class="page-title">Settings</h2>
        <div class="text-secondary mt-1">Settings for the whole switch</div>
    </div>
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs flex-wrap">
                <li v-for="t in tabs" :key="toUrl(t.href)" class="nav-item">
                    <Link :href="t.href" class="nav-link" :class="{ active: isCurrentOrParentUrl(t.href) }"><component :is="t.icon" class="icon me-1" /> {{ t.title }}</Link>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div :style="wide ? undefined : 'max-width: 48rem'" class="d-flex flex-column gap-5">
                <slot />
            </div>
        </div>
    </div>
</template>
