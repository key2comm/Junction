<script setup lang="ts">
/**
 * The signed-in shell, in Tabler's horizontal layout: a top row with the logo
 * and user menu, a menu row below it (admin items grouped into dropdowns),
 * then breadcrumbs and the page. Admins and portal users get different menus.
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
    Mail,
    Menu,
    Moon,
    Phone,
    PhoneCall,
    Receipt,
    Route,
    Settings,
    ShieldCheck,
    Sun,
    Tags,
    UserRound,
} from '@lucide/vue';
import { onClickOutside } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/composables/useAppearance';
import { useBilling } from '@/composables/useBilling';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard, logout } from '@/routes';
import { index as accounts } from '@/routes/accounts';
import { index as alerts } from '@/routes/alerts';
import { index as apiTokens } from '@/routes/api-tokens';
import { edit as billingSettings } from '@/routes/billing';
import { edit as certificate } from '@/routes/certificate';
import { index as carrierRates } from '@/routes/carrier-rates';
import { index as carriers } from '@/routes/carriers';
import { index as cdrs } from '@/routes/cdrs';
import { edit as email } from '@/routes/email';
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
import { index as routingPlans } from '@/routes/routing-plans';
import { index as tariffs } from '@/routes/tariffs';
import { index as trunks } from '@/routes/trunks';
import type { BreadcrumbItem, NavItem, User } from '@/types';

const { breadcrumbs = [] } = defineProps<{ breadcrumbs?: BreadcrumbItem[] }>();

const page = usePage();
const user = computed(() => (page.props.auth as { user: User & { account_id?: number | null } }).user);
const isPortal = computed(() => Boolean(user.value?.account_id));
const billing = useBilling();
const openAlerts = computed(() => (page.props as { openAlerts?: number | null }).openAlerts ?? 0);

type NavGroup = { title: string; icon: NavItem['icon']; items: NavItem[] };
type NavEntry = NavItem | NavGroup;

// With billing off (Settings → Billing) there are no invoices, so Accounts stands on its own.
const adminNav = computed((): NavEntry[] => [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Alerts', href: alerts(), icon: BellRing },
    {
        title: 'Calls',
        icon: PhoneCall,
        items: [
            { title: 'Live calls', href: live(), icon: AudioLines },
            { title: 'Call records', href: cdrs(), icon: PhoneCall },
            { title: 'Carrier quality', href: quality(), icon: Activity },
        ],
    },
    billing.value
        ? {
              title: 'Billing',
              icon: FileText,
              items: [
                  { title: 'Accounts', href: accounts(), icon: Building2 },
                  { title: 'Invoices', href: invoices(), icon: FileText },
              ],
          }
        : { title: 'Accounts', href: accounts(), icon: Building2 },
    {
        title: 'Routing',
        icon: Route,
        items: [
            { title: 'Carriers', href: carriers(), icon: Building2 },
            { title: 'Tariffs', href: tariffs(), icon: Tags },
            { title: 'Carrier Rates', href: carrierRates(), icon: Tags },
            { title: 'Trunks', href: trunks(), icon: Cable },
            { title: 'LCR groups', href: routingPlans(), icon: Route },
        ],
    },
    { title: 'PBX', href: pbx(), icon: Phone },
    {
        title: 'Settings',
        icon: Settings,
        items: [
            { title: 'SSL certificate', href: certificate(), icon: ShieldCheck },
            { title: 'Billing', href: billingSettings(), icon: Receipt },
            { title: 'Email (SMTP)', href: email(), icon: Mail },
            { title: 'API tokens', href: apiTokens(), icon: KeyRound },
        ],
    },
]);
const portalNav = computed((): NavEntry[] => [
    { title: 'Overview', href: portalHome(), icon: LayoutGrid },
    { title: 'Calls', href: portalCalls(), icon: PhoneCall },
    ...(billing.value ? [{ title: 'Invoices', href: portalInvoices(), icon: FileText }] : []),
    { title: 'Rates', href: portalRates(), icon: Tags },
    { title: 'Web phone', href: portalPhone(), icon: Headphones },
    { title: 'API tokens', href: portalTokens(), icon: KeyRound },
]);
const nav = computed(() => (isPortal.value ? portalNav.value : adminNav.value));
const home = computed(() => (isPortal.value ? portalHome() : dashboard()));
const isGroup = (e: NavEntry): e is NavGroup => 'items' in e;

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
// The dashboard/overview is only active on itself; other items also for their sub-pages.
const active = (item: NavItem) => (item.href === home.value ? isCurrentUrl(item.href) : isCurrentOrParentUrl(item.href));
const groupActive = (g: NavGroup) => g.items.some(active);

// Mobile menu, menu dropdowns and the user dropdown (Vue, no Bootstrap JS).
const menuOpen = ref(false);
const openGroup = ref<string | null>(null);
const userOpen = ref(false);
const userMenu = useTemplateRef<HTMLElement>('userMenu');
const mainMenu = useTemplateRef<HTMLElement>('mainMenu');
onClickOutside(userMenu, () => (userOpen.value = false));
onClickOutside(mainMenu, () => (openGroup.value = null));
const toggleGroup = (title: string) => (openGroup.value = openGroup.value === title ? null : title);
router.on('navigate', () => {
    menuOpen.value = false;
    userOpen.value = false;
    openGroup.value = null;
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
        <!-- Top row: logo, theme toggle, user menu. -->
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <button class="navbar-toggler" type="button" :aria-expanded="menuOpen" aria-label="Menu" @click="menuOpen = !menuOpen">
                    <Menu class="icon" />
                </button>
                <div class="navbar-brand pe-0 pe-md-3">
                    <Link :href="home" class="text-reset text-decoration-none"><AppLogo /></Link>
                </div>
                <div class="navbar-nav flex-row order-md-last">
                    <button type="button" class="nav-link px-2 btn btn-ghost-secondary" :title="resolvedAppearance === 'dark' ? 'Light mode' : 'Dark mode'" @click="toggleTheme">
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
                            <Link :href="profile()" class="dropdown-item"><UserRound class="icon dropdown-item-icon" /> My account</Link>
                            <div class="dropdown-divider"></div>
                            <Link :href="logout()" as="button" class="dropdown-item" data-test="logout-button" @click="router.flushAll()">
                                <LogOut class="icon dropdown-item-icon" /> Log out
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Second row: the menu (collapses behind the toggler on small screens). -->
        <header class="navbar-expand-md">
            <div class="collapse navbar-collapse" :class="{ show: menuOpen }">
                <div class="navbar">
                    <div class="container-xl">
                        <ul ref="mainMenu" class="navbar-nav">
                            <template v-for="entry in nav" :key="entry.title">
                                <li v-if="isGroup(entry)" class="nav-item dropdown" :class="{ active: groupActive(entry), show: openGroup === entry.title }">
                                    <button type="button" class="nav-link dropdown-toggle btn btn-link text-reset border-0" :aria-expanded="openGroup === entry.title" @click="toggleGroup(entry.title)">
                                        <span class="nav-link-icon d-md-none d-lg-inline-block"><component :is="entry.icon" class="icon" /></span>
                                        <span class="nav-link-title">{{ entry.title }}</span>
                                    </button>
                                    <div class="dropdown-menu" :class="{ show: openGroup === entry.title }">
                                        <Link v-for="item in entry.items" :key="item.title" :href="item.href" class="dropdown-item" :class="{ active: active(item) }">
                                            <component :is="item.icon" class="icon dropdown-item-icon" /> {{ item.title }}
                                        </Link>
                                    </div>
                                </li>
                                <li v-else class="nav-item" :class="{ active: active(entry) }">
                                    <Link :href="entry.href" class="nav-link">
                                        <span class="nav-link-icon d-md-none d-lg-inline-block"><component :is="entry.icon" class="icon" /></span>
                                        <span class="nav-link-title">{{ entry.title }}</span>
                                        <span v-if="entry.title === 'Alerts' && openAlerts" class="badge bg-red text-red-fg ms-2">{{ openAlerts }}</span>
                                    </Link>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-wrapper">
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
