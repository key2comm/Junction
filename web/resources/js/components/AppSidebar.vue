<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, Building2, Cable, FileText, KeyRound, LayoutGrid, PhoneCall, Route, Tags } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as accounts } from '@/routes/accounts';
import { index as cdrs } from '@/routes/cdrs';
import { index as invoices } from '@/routes/invoices';
import { index as quality } from '@/routes/quality';
import { home as portalHome } from '@/routes/portal';
import { index as portalTokens } from '@/routes/portal/api-tokens';
import { index as portalCalls } from '@/routes/portal/calls';
import { index as portalInvoices } from '@/routes/portal/invoices';
import { index as portalRates } from '@/routes/portal/rates';
import { index as rateDecks } from '@/routes/rate-decks';
import { index as routingPlans } from '@/routes/routing-plans';
import { index as trunks } from '@/routes/trunks';
import type { NavItem } from '@/types';

const adminNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Call records',
        href: cdrs(),
        icon: PhoneCall,
    },
    {
        title: 'Vendor quality',
        href: quality(),
        icon: Activity,
    },
    {
        title: 'Invoices',
        href: invoices(),
        icon: FileText,
    },
    {
        title: 'Accounts',
        href: accounts(),
        icon: Building2,
    },
    {
        title: 'Rate decks',
        href: rateDecks(),
        icon: Tags,
    },
    {
        title: 'Trunks',
        href: trunks(),
        icon: Cable,
    },
    {
        title: 'Routing plans',
        href: routingPlans(),
        icon: Route,
    },
];

// Customer portal users get their own menu.
const portalNavItems: NavItem[] = [
    { title: 'Overview', href: portalHome(), icon: LayoutGrid },
    { title: 'Calls', href: portalCalls(), icon: PhoneCall },
    { title: 'Invoices', href: portalInvoices(), icon: FileText },
    { title: 'Rates', href: portalRates(), icon: Tags },
    { title: 'API tokens', href: portalTokens(), icon: KeyRound },
];

const page = usePage();
const isPortal = computed(() => Boolean((page.props.auth as { user?: { account_id?: number | null } }).user?.account_id));
const mainNavItems = computed(() => (isPortal.value ? portalNavItems : adminNavItems));
const homeLink = computed(() => (isPortal.value ? portalHome() : dashboard()));

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="homeLink">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
