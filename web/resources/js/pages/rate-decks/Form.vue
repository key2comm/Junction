<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import RateDeckController from '@/actions/App/Http/Controllers/Admin/RateDeckController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { deckKind } from '@/lib/rateDecks';
import { selectClass, textareaClass } from '@/lib/ui';
import { show } from '@/routes/rate-decks';

type Kind = 'tariff' | 'carrier';

const props = defineProps<{
    deck: { id: number; name: string; currency: string; description: string | null } | null;
    kind: Kind;
    canChangeKind: boolean;
    currencies: { code: string; name: string; top: boolean }[];
}>();

defineOptions({
    layout: (props: { deck: { id: number; name: string } | null; kind: Kind }) => ({
        breadcrumbs: [
            { title: deckKind(props.kind).plural, href: deckKind(props.kind).index() },
            props.deck ? { title: props.deck.name, href: show(props.deck.id) } : { title: 'New', href: deckKind(props.kind).index() },
        ],
    }),
});

const action = props.deck ? RateDeckController.update.form(props.deck.id) : RateDeckController.store.form();
const kind = ref<Kind>(props.kind);
const currency = props.deck?.currency ?? 'CAD';
const topCurrencies = props.currencies.filter((c) => c.top);
const otherCurrencies = props.currencies.filter((c) => !c.top);
const title = props.deck ? `Edit ${props.deck.name}` : `New ${deckKind(props.kind).singular}`;
</script>

<template>
    <Head :title="title" />

    <div class="ss-maxw-xl ss-stack-4">
        <Heading :title="title" />

        <Form v-bind="action" class="ss-stack-4" v-slot="{ errors, processing }">
            <fieldset class="ss-grid gap-2">
                <legend class="fw-medium fs-5 mb-0">Type</legend>
                <div class="d-flex flex-wrap gap-4">
                    <label class="d-flex align-items-center gap-2">
                        <input v-model="kind" type="radio" name="kind" value="tariff" :disabled="!canChangeKind" class="form-check-input m-0" />
                        Tariff <span class="text-secondary">(customers pay)</span>
                    </label>
                    <label class="d-flex align-items-center gap-2">
                        <input v-model="kind" type="radio" name="kind" value="carrier" :disabled="!canChangeKind" class="form-check-input m-0" />
                        Carrier rates <span class="text-secondary">(a carrier charges us)</span>
                    </label>
                </div>
                <p v-if="!canChangeKind" class="small text-secondary">In use, so the type can't change.</p>
                <InputError :message="errors.kind" />
            </fieldset>
            <div class="ss-grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" :default-value="deck?.name" required :placeholder="kind === 'tariff' ? 'Retail A-Z 2026' : 'Acme wholesale A-Z'" />
                <InputError :message="errors.name" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="currency">Currency</Label>
                <select id="currency" name="currency" :class="selectClass" required>
                    <option v-for="c in topCurrencies" :key="c.code" :value="c.code" :selected="c.code === currency">{{ c.code }} — {{ c.name }}</option>
                    <option disabled>──────────</option>
                    <option v-for="c in otherCurrencies" :key="c.code" :value="c.code" :selected="c.code === currency">{{ c.code }} — {{ c.name }}</option>
                </select>
                <InputError :message="errors.currency" />
            </div>
            <div class="ss-grid gap-2">
                <Label for="description">Description</Label>
                <textarea id="description" name="description" rows="3" :class="textareaClass">{{ deck?.description ?? '' }}</textarea>
                <InputError :message="errors.description" />
            </div>
            <div class="d-flex gap-2">
                <Button :disabled="processing">{{ deck ? 'Save changes' : 'Create' }}</Button>
                <Button variant="ghost" as-child><Link :href="deck ? show(deck.id) : deckKind(props.kind).index()">Cancel</Link></Button>
            </div>
        </Form>
    </div>
</template>
