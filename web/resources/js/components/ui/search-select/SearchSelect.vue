<script setup lang="ts" generic="T extends string | number">
/**
 * A select with a search box, for long lists (customers...). Looks like a
 * Tabler form-select; opening it shows a search field and the matching
 * options (first 100). Keyboard: arrows, Enter, Escape. With `name`, the value
 * is submitted in a hidden input, like a normal select in a <Form>.
 */
import { onClickOutside } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import type { SearchSelectOption } from './types';


const props = withDefaults(
    defineProps<{
        options: SearchSelectOption<T>[];
        id?: string;
        name?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        disabled?: boolean;
        invalid?: boolean;
    }>(),
    { placeholder: 'Choose…', searchPlaceholder: 'Search…' },
);

const model = defineModel<T | null | ''>({ default: null });

const LIMIT = 100;
const root = ref<HTMLElement>();
const search = ref<HTMLInputElement>();
const list = ref<HTMLElement>();
const open = ref(false);
const query = ref('');
const active = ref(0);

const selected = computed(() => props.options.find((o) => String(o.value) === String(model.value ?? '')));
const matches = computed(() => {
    const words = query.value.toLowerCase().split(/\s+/).filter(Boolean);
    const found = words.length
        ? props.options.filter((o) => {
              const text = `${o.label} ${o.hint ?? ''}`.toLowerCase();
              return words.every((w) => text.includes(w));
          })
        : props.options;
    return { shown: found.slice(0, LIMIT), more: Math.max(0, found.length - LIMIT) };
});

watch(query, () => (active.value = 0));

async function show() {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    const i = matches.value.shown.findIndex((o) => o === selected.value);
    active.value = Math.max(0, i);
    await nextTick();
    search.value?.focus();
    scrollToActive();
}

function close(refocus = false) {
    open.value = false;
    if (refocus) root.value?.querySelector<HTMLButtonElement>('button')?.focus();
}

function choose(o: SearchSelectOption<T>) {
    model.value = o.value;
    close(true);
}

function scrollToActive() {
    nextTick(() => list.value?.querySelector<HTMLElement>(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }));
}

function onKey(e: KeyboardEvent) {
    const n = matches.value.shown.length;
    if (e.key === 'ArrowDown' && n) {
        active.value = (active.value + 1) % n;
        scrollToActive();
    } else if (e.key === 'ArrowUp' && n) {
        active.value = (active.value - 1 + n) % n;
        scrollToActive();
    } else if (e.key === 'Enter') {
        const o = matches.value.shown[active.value];
        if (o) choose(o);
    } else if (e.key === 'Escape') {
        close(true);
    } else if (e.key === 'Tab') {
        close();
        return;
    } else {
        return;
    }
    e.preventDefault();
}

onClickOutside(root, () => close());
</script>

<template>
    <div ref="root" class="position-relative">
        <input v-if="name" type="hidden" :name="name" :value="model ?? ''" />
        <button
            :id="id"
            type="button"
            class="form-select text-start"
            :class="{ 'is-invalid': invalid }"
            :disabled="disabled"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @click="open ? close() : show()"
            @keydown.down.prevent="show()"
        >
            <span v-if="selected" class="text-truncate d-block">{{ selected.label }}</span>
            <span v-else class="text-secondary">{{ placeholder }}</span>
        </button>
        <div v-if="open" class="dropdown-menu show w-100 p-0 mt-1" style="z-index: 1050">
            <div class="p-2 border-bottom">
                <input ref="search" v-model="query" type="search" class="form-control form-control-sm" :placeholder="searchPlaceholder" autocomplete="off" @keydown="onKey" />
            </div>
            <div ref="list" role="listbox" class="overflow-auto py-1" style="max-height: 18rem">
                <button
                    v-for="(o, i) in matches.shown"
                    :key="o.value"
                    type="button"
                    role="option"
                    :data-index="i"
                    :aria-selected="o === selected"
                    class="dropdown-item d-flex justify-content-between gap-2"
                    :class="{ active: i === active }"
                    @mouseenter="active = i"
                    @click="choose(o)"
                >
                    <span class="text-truncate">{{ o.label }}</span>
                    <span v-if="o.hint" class="small font-monospace" :class="i === active ? '' : 'text-secondary'">{{ o.hint }}</span>
                </button>
                <div v-if="!matches.shown.length" class="px-3 py-2 text-secondary small">No match for “{{ query }}”.</div>
                <div v-else-if="matches.more" class="px-3 py-2 text-secondary small">{{ matches.more }} more: type to narrow the list.</div>
            </div>
        </div>
    </div>
</template>
