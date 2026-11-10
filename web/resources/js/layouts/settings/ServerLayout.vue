<script setup lang="ts">
/** Server settings (admin only): the pages under the top menu's Settings item, as tabs of one card. */
import { Link } from '@inertiajs/vue3';
import { BrickWall, CalendarClock, KeyRound, Mail, Palette, Receipt, ShieldCheck, UserCog } from '@lucide/vue';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as editBilling } from '@/routes/billing';
import { edit as editBranding } from '@/routes/branding';
import { edit as editCertificate } from '@/routes/certificate';
import { edit as editClientDefaults } from '@/routes/client-defaults';
import { edit as editEmail } from '@/routes/email';
import { index as firewall } from '@/routes/firewall';
import { index as reports } from '@/routes/reports';

const tabs = [
    // Same order as the top menu's Settings dropdown.
    { title: 'SSL certificate', href: editCertificate(), icon: ShieldCheck },
    { title: 'Firewall', href: firewall(), icon: BrickWall },
    { title: 'Billing', href: editBilling(), icon: Receipt },
    { title: 'Branding', href: editBranding(), icon: Palette },
    { title: 'Client defaults', href: editClientDefaults(), icon: UserCog },
    { title: 'Email (SMTP)', href: editEmail(), icon: Mail },
    { title: 'Scheduled reports', href: reports(), icon: CalendarClock },
    { title: 'API tokens', href: apiTokens(), icon: KeyRound },
];
const { isCurrentOrParentUrl } = useCurrentUrl();
// The firewall and reports pages have wide tables; the others are single forms.
const wide = computed(() => isCurrentOrParentUrl(firewall()) || isCurrentOrParentUrl(reports()));
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
