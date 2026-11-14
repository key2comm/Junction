<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Pencil, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/billing-profiles';
import { index as itemsIndex } from '@/routes/billing-items';

type Profile = {
    id: number;
    name: string;
    description: string | null;
    nanp_plan: 'metered' | 'unlimited' | 'bundle';
    nanp_minutes: number | null;
    accounts_count: number;
    plan: string;
    items: { name: string; unit: string; frequency: string; quantity: string; price: string }[];
};

defineProps<{ profiles: Profile[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Billing profiles', href: index() }] } });

const page = usePage();
const pageErrors = computed(() => page.props.errors as Record<string, string>);
const amount = (v: string) => Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
const qty = (v: string) => Number(v).toLocaleString();
</script>

<template>
    <Head title="Billing profiles" />

    <div class="ss-stack-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <Heading
                title="Billing profiles"
                description="Plans you give customers: the items on their bill, and how their Canada/USA calls are charged (per minute, unlimited, or a number of included minutes a month). International calls always pay the customer's tariff."
            />
            <Button as-child>
                <Link :href="create()"><Plus class="icon" /> New profile</Link>
            </Button>
        </div>

        <p v-if="pageErrors.profile" class="alert alert-danger mb-0">{{ pageErrors.profile }}</p>

        <div class="ss-grid gap-3 ss-cols-lg-2">
            <section v-for="p in profiles" :key="p.id" class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ p.name }}</h3>
                        <p class="card-subtitle mb-0">{{ p.accounts_count }} customer{{ p.accounts_count === 1 ? '' : 's' }}</p>
                    </div>
                    <div class="card-actions">
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="edit(p.id)"><Pencil class="icon" /> Edit</Link>
                        </Button>
                    </div>
                </div>
                <div class="card-body ss-stack-2">
                    <p v-if="p.description" class="text-secondary mb-0">{{ p.description }}</p>
                    <p class="mb-0">
                        <Badge :variant="p.nanp_plan === 'metered' ? 'secondary' : 'default'">{{ p.plan }}</Badge>
                    </p>
                    <ul class="list-unstyled mb-0 ss-stack-1">
                        <li v-for="(i, n) in p.items" :key="n" class="d-flex justify-content-between gap-2">
                            <span>{{ i.name }} × {{ qty(i.quantity) }}<span v-if="i.unit !== 'each'" class="text-secondary"> {{ i.unit }}</span></span>
                            <span class="tabular-nums text-secondary">{{ amount(i.price) }} {{ i.frequency === 'monthly' ? '/ month' : 'once' }}</span>
                        </li>
                        <li v-if="p.items.length === 0" class="text-secondary">No items: calls only.</li>
                    </ul>
                </div>
            </section>
        </div>

        <div v-if="profiles.length === 0" class="card card-body text-center text-secondary py-5">
            <p class="mb-1">No billing profiles yet.</p>
            <p class="mb-0">
                Add your <Link :href="itemsIndex()">Billing items</Link> first (e.g. "Unlimited hosting, per ext, monthly"), then build a profile from them.
            </p>
        </div>
    </div>
</template>
