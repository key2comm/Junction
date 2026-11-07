<script setup lang="ts">
/** Server settings (admin only): the pages under the top menu's Settings item, as tabs of one card. */
import { Link } from '@inertiajs/vue3';
import { KeyRound, Mail, ShieldCheck } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as editCertificate } from '@/routes/certificate';
import { edit as editEmail } from '@/routes/email';

const tabs = [
    { title: 'SSL certificate', href: editCertificate(), icon: ShieldCheck },
    { title: 'Email (SMTP)', href: editEmail(), icon: Mail },
    { title: 'API tokens', href: apiTokens(), icon: KeyRound },
];
const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mb-3">
        <h2 class="page-title">Settings</h2>
        <div class="text-secondary mt-1">How this switch talks to the outside world</div>
    </div>
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs">
                <li v-for="t in tabs" :key="toUrl(t.href)" class="nav-item">
                    <Link :href="t.href" class="nav-link" :class="{ active: isCurrentOrParentUrl(t.href) }"><component :is="t.icon" class="icon me-1" /> {{ t.title }}</Link>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div style="max-width: 48rem" class="d-flex flex-column gap-5">
                <slot />
            </div>
        </div>
    </div>
</template>
