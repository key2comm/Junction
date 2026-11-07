<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

// The signed-in user's own account. Server settings live under the top menu's Settings.
const items: NavItem[] = [
    { title: 'Profile', href: editProfile() },
    { title: 'Security', href: editSecurity() },
    { title: 'Appearance', href: editAppearance() },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="page-header p-0 mb-3">
        <h2 class="page-title">My account</h2>
        <div class="text-secondary">Your profile, password and sign-in security</div>
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
