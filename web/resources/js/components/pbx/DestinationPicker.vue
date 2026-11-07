<script setup lang="ts">
import { computed, ref } from 'vue';
import { selectClass } from '@/lib/ui';

export type PbxNumber = { number: string; kind: 'extension' | 'ivr'; label: string; voicemail: boolean };
export type Destination = { type: string; target?: string | null } | null;

const props = defineProps<{
    /** Field name prefix: renders `${name}[type]` and `${name}[target]`. */
    name: string;
    numbers: PbxNumber[];
    value?: Destination;
    /** Offer "nothing" (e.g. an IVR's no-key-pressed action). */
    optional?: boolean;
    id?: string;
}>();

const type = ref(props.value?.type ?? (props.optional ? '' : 'number'));
const target = ref(props.value?.target ?? '');

const targets = computed(() => (type.value === 'voicemail' ? props.numbers.filter((n) => n.kind === 'extension' && n.voicemail) : props.numbers));
const needsTarget = computed(() => type.value === 'number' || type.value === 'voicemail');
</script>

<template>
    <div class="d-flex flex-wrap gap-2">
        <select :id="id" v-model="type" :name="`${name}[type]`" :class="selectClass" class="w-auto ss-minw-36">
            <option v-if="optional" value="">Repeat, then hang up</option>
            <option value="number">Ring / go to</option>
            <option value="voicemail">Voicemail of</option>
            <option value="hangup">Hang up</option>
        </select>
        <select v-if="needsTarget" v-model="target" :name="`${name}[target]`" :class="selectClass" class="w-auto ss-minw-48" :aria-label="`${name} target`">
            <option value="" disabled>Choose…</option>
            <option v-for="n in targets" :key="n.number" :value="n.number">{{ n.label }}</option>
        </select>
    </div>
</template>
