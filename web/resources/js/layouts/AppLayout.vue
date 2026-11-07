<script setup lang="ts">
/**
 * The signed-in shell, in Tabler's "vertical" layout: dark sidebar with the
 * menu, a top bar with the user menu, then the page header (breadcrumbs) and
 * body. Admins and customer-portal users get different menus.
 */
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AudioLines,
    BellRing,
    Building2,
    Cable,
    ChevronDown,
    FileText,
    Headphones,
    KeyRound,
    LayoutGrid,
    LogOut,
    Menu,
    Moon,
    Phone,
    PhoneCall,
    Route,
    Settings,
    Sun,
    Tags,
} from '@lucide/vue';
import { onClickOutside } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/composables/useAppearance';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard, logout } from '@/routes';
import { index as accounts } from '@/routes/accounts';
import { index as alerts } from '@/routes/alerts';
import { index as cdrs } from '@/routes/cdrs';
import { index as invoices } from '@/routes/invoices';
import { index as live } from '@/routes/live';
import { index as pbx } from '@/routes/pbx';
import { home as portalHome, phone as portalPhone } from '@/routes/portal';
import { index as portalTokens } from '@/routes/portal/api-tokens';
import { index as portalCalls } from '@/routes/portal/calls';
import { index as portalInvoices } from '@/routes/portal/invoices';
import { index as portalRates } from '@/routes/portal/rates';
import { edit as profile } from '@/routes/profile';
import { index as quality } from '@/routes/quality';
import { index as rateDecks } from '@/routes/rate-decks';
import { index as routingPlans } from '@/routes/routing-plans';
import { index as trunks } from '@/routes/trunks';
import type { BreadcrumbItem, NavItem, User } from '@/types';

const { breadcrumbs = [] } = defineProps<{ breadcrumbs?: BreadcrumbItem[] }>();

const page = usePage();
const user = computed(() => (page.props.auth as { user: User & { account_id?: number | null } }).user);
const isPortal = computed(() => Boolean(user.value?.account_id));
const openAlerts = computed(() => (page.props as { openAlerts?: number | null }).openAlerts ?? 0);

const adminNav: (NavItem | { heading: string })[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Alerts', href: alerts(), icon: BellRing },
    { title: 'Live calls', href: live(), icon: AudioLines },
    { title: 'Call records', href: cdrs(), icon: PhoneCall },
    { title: 'Vendor quality', href: quality(), icon: Activity },
    { heading: 'Billing' },
    { title: 'Invoices', href: invoices(), icon: FileText },
    { title: 'Accounts', href: accounts(), icon: Building2 },
    { heading: 'Routing' },
    { title: 'Rate decks', href: rateDecks(), icon: Tags },
    { title: 'Trunks', href: trunks(), icon: Cable },
    { title: 'Routing plans', href: routingPlans(), icon: Route },
    { title: 'PBX', href: pbx(), icon: Phone },
];
const portalNav: NavItem[] = [
    { title: 'Overview', href: portalHome(), icon: LayoutGrid },
    { title: 'Calls', href: portalCalls(), icon: PhoneCall },
    { title: 'Invoices', href: portalInvoices(), icon: FileText },
    { title: 'Rates', href: portalRates(), icon: Tags },
    { title: 'Web phone', href: portalPhone(), icon: Headphones },
    { title: 'API tokens', href: portalTokens(), icon: KeyRound },
];
const nav = computed(() => (isPortal.value ? portalNav : adminNav));
const home = computed(() => (isPortal.value ? portalHome() : dashboard()));

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
// The dashboard/overview is only active on itself; other items also for their sub-pages.
const active = (item: NavItem) => (item.href === home.value ? isCurrentUrl(item.href) : isCurrentOrParentUrl(item.href));

// Mobile menu and the user dropdown (Vue, no Bootstrap JS).
const menuOpen = ref(false);
const userOpen = ref(false);
const userMenu = useTemplateRef<HTMLElement>('userMenu');
onClickOutside(userMenu, () => (userOpen.value = false));
router.on('navigate', () => {
    menuOpen.value = false;
    userOpen.value = false;
});

const { resolvedAppearance, updateAppearance } = useAppearance();
const toggleTheme = () => updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(/\s+/)
        .map((p) => p[0])
        .join('')
        .slice(0, 2)
        .toUpperCase(),
);
</script>

<template>
    <div class="page">
        <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
            <div class="container-fluid">
                <button class="navbar-toggler" type="button" :aria-expanded="menuOpen" aria-label="Menu" @click="menuOpen = !menuOpen">
                    <Menu class="icon" />
                </button>
                <div class="navbar-brand navbar-brand-autodark">
                    <Link :href="home" class="text-reset text-decoration-none"><AppLogo /></Link>
                </div>
                <div class="navbar-nav flex-row d-lg-none">
                    <Link :href="profile()" class="nav-link px-2" aria-label="Settings"><Settings class="icon" /></Link>
                </div>
                <div class="collapse navbar-collapse" :class="{ show: menuOpen }">
                    <ul class="navbar-nav pt-lg-3">
                        <template v-for="(item, i) in nav" :key="i">
                            <li v-if="'heading' in item" class="nav-item">
                                <div class="nav-link text-uppercase small text-secondary pt-3 pb-1 pe-none">{{ item.heading }}</div>
                            </li>
                            <li v-else class="nav-item" :class="{ active: active(item) }">
                                <Link :href="item.href" class="nav-link">
                                    <span class="nav-link-icon d-md-none d-lg-inline-block"><component :is="item.icon" class="icon" /></span>
                                    <span class="nav-link-title">{{ item.title }}</span>
                                    <span v-if="item.title === 'Alerts' && openAlerts" class="badge bg-red text-red-fg ms-auto">{{ openAlerts }}</span>
                                </Link>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>
        </aside>


        <div class="page-wrapper">
            <header class="navbar navbar-expand-md d-none d-lg-flex d-print-none">
                <div class="container-xl">
                    <div class="navbar-nav flex-row order-md-last ms-auto">
                        <button type="button" class="nav-link px-0 me-3 btn btn-ghost-secondary" :title="resolvedAppearance === 'dark' ? 'Light mode' : 'Dark mode'" @click="toggleTheme">
                            <Sun v-if="resolvedAppearance === 'dark'" class="icon" /><Moon v-else class="icon" />
                        </button>
                        <div ref="userMenu" class="nav-item dropdown" :class="{ show: userOpen }">
                            <button type="button" class="nav-link d-flex lh-1 p-0 px-2 btn btn-ghost-secondary" :aria-expanded="userOpen" @click="userOpen = !userOpen">
                                <span class="avatar avatar-sm">{{ initials }}</span>
                                <div class="d-none d-xl-block ps-2 text-start">
                                    <div>{{ user?.name }}</div>
                                    <div class="mt-1 small text-secondary">{{ user?.email }}</div>
                                </div>
                                <ChevronDown class="icon ms-1" />
                            </button>
                            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow" :class="{ show: userOpen }" style="right: 0; left: auto">
                                <Link :href="profile()" class="dropdown-item"><Settings class="icon dropdown-item-icon" /> Settings</Link>
                                <div class="dropdown-divider"></div>
                                <Link :href="logout()" as="button" class="dropdown-item" data-test="logout-button" @click="router.flushAll()">
                                    <LogOut class="icon dropdown-item-icon" /> Log out
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <div v-if="breadcrumbs.length > 1" class="page-header d-print-none">
                <div class="container-xl">
                    <ol class="breadcrumb breadcrumb-arrows mb-0" aria-label="breadcrumbs">
                        <li v-for="(b, i) in breadcrumbs" :key="i" class="breadcrumb-item" :class="{ active: i === breadcrumbs.length - 1 }">
                            <Link v-if="i < breadcrumbs.length - 1" :href="b.href">{{ b.title }}</Link>
                            <span v-else>{{ b.title }}</span>
                        </li>
                    </ol>
                </div>
            </div>
            <div class="page-body">
                <div class="container-xl">
                    <slot />
                </div>
            </div>
        </div>
        <Toaster />
    </div>
</template>
