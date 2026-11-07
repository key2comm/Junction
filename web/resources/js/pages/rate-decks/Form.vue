<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import RateDeckController from '@/actions/App/Http/Controllers/Admin/RateDeckController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { textareaClass } from '@/lib/ui';
import { index, show } from '@/routes/rate-decks';

const props = defineProps<{ deck: { id: number; name: string; currency: string; description: string | null } | null }>();

defineOptions({
    layout: (props: { deck: { id: number; name: string } | null }) => ({
        breadcrumbs: [
            { title: 'Rate decks', href: index() },
            props.deck ? { title: props.deck.name, href: show(props.deck.id) } : { title: 'New', href: index() },
        ],
    }),
});

const action = props.deck ? RateDeckController.update.form(props.deck.id) : RateDeckController.store.form();
</script>

<template>
    <Head :title="deck ? `Edit ${deck.name}` : 'New rate deck'" />

    <div class="max-w-xl space-y-6 p-4">
        <Heading :title="deck ? `Edit ${deck.name}` : 'New rate deck'" />

        <Form v-bind="action" class="space-y-6" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" :default-value="deck?.name" required placeholder="Wholesale A-Z 2026" />
                <InputError :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="currency">Currency</Label>
                <Input id="currency" name="currency" :default-value="deck?.currency ?? 'USD'" maxlength="3" required class="w-24 uppercase" />
                <InputError :message="errors.currency" />
            </div>
            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <textarea id="description" name="description" rows="3" :class="textareaClass">{{ deck?.description ?? '' }}</textarea>
                <InputError :message="errors.description" />
            </div>
            <div class="flex gap-3">
                <Button :disabled="processing">{{ deck ? 'Save changes' : 'Create rate deck' }}</Button>
                <Button variant="ghost" as-child><Link :href="deck ? show(deck.id) : index()">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
