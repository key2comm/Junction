<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editCertificate } from '@/routes/certificate';
import { edit as editEmail } from '@/routes/email';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const allNavItems: (NavItem & { adminOnly?: boolean })[] = [
    { title: 'Profile', href: editProfile() },
    { title: 'Security', href: editSecurity() },
    { title: 'Appearance', href: editAppearance() },
    { title: 'API tokens', adminOnly: true, href: apiTokens() },
    { title: 'Email', adminOnly: true, href: editEmail() },
    { title: 'SSL certificate', adminOnly: true, href: editCertificate() },
];

const page = usePage();
// Portal (customer) users only see their own profile settings here.
const isAdmin = computed(() => !(page.props.auth as { user?: { account_id?: number | null } }).user?.account_id);
const items = computed(() => allNavItems.filter((item) => isAdmin.value || !item.adminOnly));

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="page-header p-0 mb-3">
        <h2 class="page-title">Settings</h2>
        <div class="text-secondary">Your account and the server's settings</div>
    </div>
    <div class="card">
        <div class="row g-0">
            <div class="col-12 col-md-3 border-end">
                <div class="card-body">
                    <nav class="list-group list-group-transparent" aria-label="Settings">
                        <Link
                            v-for="item in items"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            class="list-group-item list-group-item-action d-flex align-items-center"
                            :class="{ active: isCurrentOrParentUrl(item.href) }"
                        >
                            {{ item.title }}
                        </Link>
                    </nav>
                </div>
            </div>
            <div class="col-12 col-md-9 d-flex flex-column">
                <div class="card-body">
                    <div style="max-width: 44rem" class="d-flex flex-column gap-5">
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
