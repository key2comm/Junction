<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
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
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
    {
        title: 'API tokens',
        adminOnly: true,
        href: apiTokens(),
    },
    {
        title: 'Email',
        adminOnly: true,
        href: editEmail(),
    },
    {
        title: 'SSL certificate',
        adminOnly: true,
        href: editCertificate(),
    },
];

const page = usePage();
// Portal (customer) users only see their own profile settings here.
const isAdmin = computed(() => !(page.props.auth as { user?: { account_id?: number | null } }).user?.account_id);
const sidebarNavItems = computed(() => allNavItems.filter((item) => isAdmin.value || !item.adminOnly));

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your account and server settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
