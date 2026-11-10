<script setup lang="ts">
/**
 * Accounts → Dialing rules: where a customer's calls may go. Canada/USA is
 * always allowed; on top of that, every other country or a chosen few.
 * Posts dialing_scope and dialing_countries[] with the account form.
 */
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';

export type DialCountry = { code: string; name: string; region: string; prefixes: string[] };
export type DialingScope = 'nanp' | 'international' | 'countries';

const props = defineProps<{
    countries: DialCountry[];
    scope?: DialingScope;
    selected?: string[];
    errors: Record<string, string | undefined>;
}>();

const scope = ref<DialingScope>(props.scope ?? 'nanp');
const chosen = ref<string[]>([...(props.selected ?? [])]);
const search = ref('');

const byCode = computed(() => new Map(props.countries.map((c) => [c.code, c])));
const regions = computed(() => [...new Set(props.countries.map((c) => c.region))]);
const chosenSorted = computed(() =>
    chosen.value.map((code) => byCode.value.get(code)).filter((c): c is DialCountry => !!c).sort((a, b) => a.name.localeCompare(b.name)),
);

// Search by name, code or dialing prefix ("44", "+33").
const matches = computed(() => {
    const q = search.value.trim().toLowerCase().replace(/^\+/, '');
    if (q === '') return [];
    return props.countries
        .filter((c) => !chosen.value.includes(c.code))
        .filter((c) => c.name.toLowerCase().includes(q) || c.code.toLowerCase() === q || c.prefixes.some((p) => p.startsWith(q)))
        .slice(0, 12);
});

const add = (code: string) => {
    if (!chosen.value.includes(code)) chosen.value.push(code);
};
const remove = (code: string) => (chosen.value = chosen.value.filter((c) => c !== code));
const addRegion = (region: string) => props.countries.filter((c) => c.region === region).forEach((c) => add(c.code));
const regionFull = (region: string) => props.countries.filter((c) => c.region === region).every((c) => chosen.value.includes(c.code));

function pickFirst() {
    if (matches.value.length > 0) {
        add(matches.value[0].code);
        search.value = '';
    }
}

const options: { value: DialingScope; label: string; hint: string }[] = [
    { value: 'nanp', label: 'Canada and USA only', hint: 'Including the US territories (Puerto Rico, Guam...). Caribbean +1 area codes such as Jamaica (1-876) are not included.' },
    { value: 'countries', label: 'Canada and USA, plus selected countries', hint: 'Choose the countries below.' },
    { value: 'international', label: 'Canada and USA, plus all international', hint: 'Every country, satellite phones included.' },
];
</script>

<template>
    <div class="ss-grid gap-3 ss-span-sm-2">
        <div>
            <h3 class="fw-semibold mb-1">Dialing rules</h3>
            <p class="small text-secondary mb-0">Where this customer's calls may go. Anything else is refused (403).</p>
        </div>

        <div class="ss-grid gap-2">
            <label v-for="o in options" :key="o.value" class="form-check mb-0">
                <input v-model="scope" type="radio" name="dialing_scope" :value="o.value" class="form-check-input" />
                <span class="form-check-label">{{ o.label }}</span>
                <span class="d-block small text-secondary">{{ o.hint }}</span>
            </label>
            <InputError :message="errors.dialing_scope" />
        </div>

        <!-- Kept while another option is chosen, so switching back doesn't lose the list. -->
        <input v-for="code in chosen" :key="code" type="hidden" name="dialing_countries[]" :value="code" />

        <div v-if="scope === 'countries'" class="card card-body ss-stack-3">
            <div class="position-relative">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Add a country: name or dialing code (e.g. France, 44)"
                    aria-label="Add a country"
                    autocomplete="off"
                    @keydown.enter.prevent="pickFirst"
                />
                <div v-if="matches.length" class="list-group position-absolute w-100 shadow-sm" style="z-index: 1000">
                    <button
                        v-for="c in matches"
                        :key="c.code"
                        type="button"
                        class="list-group-item list-group-item-action d-flex justify-content-between"
                        @click="add(c.code); search = ''"
                    >
                        <span>{{ c.name }}</span>
                        <span class="text-secondary small">+{{ c.prefixes.join(', +') }}</span>
                    </button>
                </div>
            </div>

            <div>
                <span class="small text-secondary me-2">Add a whole region:</span>
                <button
                    v-for="r in regions"
                    :key="r"
                    type="button"
                    class="btn btn-sm btn-outline-secondary me-1 mb-1"
                    :disabled="regionFull(r)"
                    @click="addRegion(r)"
                >
                    {{ r }}
                </button>
            </div>

            <div v-if="chosenSorted.length" class="d-flex flex-wrap gap-1">
                <span v-for="c in chosenSorted" :key="c.code" class="badge bg-secondary-lt d-inline-flex align-items-center gap-1">
                    {{ c.name }}
                    <button type="button" class="btn btn-link p-0 text-reset" :aria-label="`Remove ${c.name}`" @click="remove(c.code)"><X class="icon" /></button>
                </span>
                <button type="button" class="btn btn-link btn-sm p-0 ms-2" @click="chosen = []">Clear all</button>
            </div>
            <p v-else class="small text-secondary mb-0">No countries chosen yet.</p>
            <p class="small text-secondary mb-0">{{ chosenSorted.length }} selected</p>
            <InputError :message="errors.dialing_countries" />
        </div>
    </div>
</template>
