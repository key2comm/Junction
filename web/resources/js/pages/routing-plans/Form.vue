<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import RoutingPlanController from '@/actions/App/Http/Controllers/Admin/RoutingPlanController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass, textareaClass } from '@/lib/ui';
import { index, show } from '@/routes/routing-plans';

const props = defineProps<{
    plan: {
        id: number;
        name: string;
        mode: string;
        max_attempts: number;
        description: string | null;
        quality_routing: boolean;
        quality_min_asr: string;
        quality_max_pdd_ms: number;
        quality_min_attempts: number;
    } | null;
    modes: string[];
}>();

defineOptions({
    layout: (props: { plan: { id: number; name: string } | null }) => ({
        breadcrumbs: [
            { title: 'Routing plans', href: index() },
            props.plan ? { title: props.plan.name, href: show(props.plan.id) } : { title: 'New', href: index() },
        ],
    }),
});

const p = props.plan;
const action = p ? RoutingPlanController.update.form(p.id) : RoutingPlanController.store.form();
const modeHelp: Record<string, string> = {
    lcr: 'Least cost: cheapest vendor rate for the destination first; priority breaks ties.',
    priority: 'Priority: fixed order by route priority, regardless of cost.',
    weight: 'Load balance: spread calls across routes in proportion to their weight.',
};
</script>

<template>
    <Head :title="p ? `Edit ${p.name}` : 'New routing plan'" />

    <div class="max-w-xl space-y-6 p-4">
        <Heading :title="p ? `Edit ${p.name}` : 'New routing plan'" />

        <Form v-bind="action" class="space-y-6" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" :default-value="p?.name" required placeholder="Standard wholesale" />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="mode">Route order</Label>
                <select id="mode" name="mode" :class="selectClass">
                    <option v-for="m in modes" :key="m" :value="m" :selected="m === (p?.mode ?? 'lcr')">{{ modeHelp[m] ?? m }}</option>
                </select>
                <InputError :message="errors.mode" />
            </div>
            <div class="grid gap-2">
                <Label for="max_attempts">Max vendor attempts per call</Label>
                <Input id="max_attempts" name="max_attempts" type="number" min="1" max="10" :default-value="p?.max_attempts ?? 3" required class="w-24" />
                <p class="text-xs text-muted-foreground">How many vendors to try before giving up when calls fail.</p>
                <InputError :message="errors.max_attempts" />
            </div>
            <fieldset class="space-y-3 rounded-lg border p-4">
                <legend class="px-1 text-sm font-medium">Quality-aware routing</legend>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="quality_routing" value="1" :checked="p?.quality_routing ?? false" class="mt-0.5 size-4" />
                    <span>Try vendors performing badly over the last hour <strong>after</strong> the others, even if they're cheaper. They're kept as a last resort, never dropped.</span>
                </label>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <Label for="quality_min_asr">ASR below (%)</Label>
                        <Input id="quality_min_asr" name="quality_min_asr" type="number" step="0.1" min="0" max="100" :default-value="p?.quality_min_asr ?? 10" />
                        <InputError :message="errors.quality_min_asr" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="quality_max_pdd_ms">or PDD above (ms)</Label>
                        <Input id="quality_max_pdd_ms" name="quality_max_pdd_ms" type="number" min="500" step="100" :default-value="p?.quality_max_pdd_ms ?? 10000" />
                        <InputError :message="errors.quality_max_pdd_ms" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="quality_min_attempts">Judge after attempts</Label>
                        <Input id="quality_min_attempts" name="quality_min_attempts" type="number" min="1" :default-value="p?.quality_min_attempts ?? 30" />
                        <InputError :message="errors.quality_min_attempts" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">Uses each vendor's figures for the destination when it has enough attempts, otherwise its overall figures. Updated every minute.</p>
            </fieldset>

            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <textarea id="description" name="description" rows="3" :class="textareaClass">{{ p?.description ?? '' }}</textarea>
                <InputError :message="errors.description" />
            </div>
            <div class="flex gap-3">
                <Button :disabled="processing">{{ p ? 'Save changes' : 'Create routing plan' }}</Button>
                <Button variant="ghost" as-child><Link :href="p ? show(p.id) : index()">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
