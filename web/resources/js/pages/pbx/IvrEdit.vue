<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, Trash2 } from '@lucide/vue';
import PbxController from '@/actions/App/Http/Controllers/Admin/PbxController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DestinationPicker from '@/components/pbx/DestinationPicker.vue';
import type { Destination, PbxNumber } from '@/components/pbx/DestinationPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show } from '@/routes/accounts/pbx';
import { greeting } from '@/routes/accounts/pbx/ivrs';
import { index } from '@/routes/pbx';

const props = defineProps<{
    account: { id: number; name: string; code: string };
    ivr: {
        id: number;
        extension: string;
        name: string;
        timeout_seconds: number;
        has_greeting: boolean;
        default_destination: Destination;
        options: { key: string; type: string; target?: string | null }[];
    };
    numbers: PbxNumber[];
}>();

defineOptions({
    layout: (props: { account: { id: number; name: string }; ivr: { name: string } }) => ({
        breadcrumbs: [
            { title: 'PBX', href: index() },
            { title: props.account.name, href: show(props.account.id) },
            { title: props.ivr.name, href: '#' },
        ],
    }),
});

let nextId = 0;
const rows = ref(props.ivr.options.map((o) => ({ id: nextId++, key: o.key, destination: { type: o.type, target: o.target ?? null } as Destination })));
if (rows.value.length === 0) {
    rows.value.push({ id: nextId++, key: '1', destination: null });
}
const addRow = () => rows.value.push({ id: nextId++, key: '', destination: null });
const removeRow = (id: number) => (rows.value = rows.value.filter((r) => r.id !== id));
const greetingUrl = greeting.url([props.account.id, props.ivr.id]);
</script>

<template>
    <Head :title="props.ivr.name" />

    <div class="max-w-3xl space-y-6 p-4">
        <Heading :title="ivr.name" :description="`IVR ${ivr.extension} · ${account.name}`" />

        <Form v-bind="PbxController.updateIvr.form([account.id, ivr.id])" class="space-y-6" v-slot="{ errors, processing, progress }">
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="grid gap-1 sm:col-span-2">
                    <Label for="extension">Number</Label>
                    <Input id="extension" name="extension" :default-value="ivr.extension" required inputmode="numeric" class="font-mono" />
                    <InputError :message="errors.extension" />
                </div>
                <div class="grid gap-1 sm:col-span-3">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" :default-value="ivr.name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1">
                    <Label for="timeout_seconds">Wait (s)</Label>
                    <Input id="timeout_seconds" name="timeout_seconds" type="number" min="2" max="30" :default-value="ivr.timeout_seconds" required />
                    <InputError :message="errors.timeout_seconds" />
                </div>
            </div>

            <section class="space-y-2">
                <Label for="greeting">Greeting</Label>
                <audio v-if="ivr.has_greeting" :src="greetingUrl" controls preload="none" class="w-full max-w-md"></audio>
                <p v-else class="text-sm text-muted-foreground">No greeting yet: callers hear silence while the IVR waits for a key.</p>
                <input id="greeting" type="file" name="greeting" accept=".wav,audio/wav,audio/x-wav" class="block text-sm" />
                <p class="text-xs text-muted-foreground">WAV (PCM, 8-48 kHz, mono is best), up to 10 MB. Say the options, e.g. "For sales press 1…".</p>
                <InputError :message="errors.greeting" />
                <progress v-if="progress" :value="progress.percentage" max="100" class="w-full max-w-md">{{ progress.percentage }}%</progress>
                <label v-if="ivr.has_greeting" class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_greeting" value="1" class="size-4" /> Remove the greeting</label>
            </section>

            <section class="space-y-3">
                <Heading variant="small" title="Options" description="One key each. Callers who press nothing get the action below." />
                <div v-for="(row, i) in rows" :key="row.id" class="flex flex-wrap items-start gap-2">
                    <div class="grid gap-1">
                        <Input :name="`options[${i}][key]`" v-model="row.key" maxlength="1" placeholder="Key" :aria-label="`Option ${i + 1} key`" class="w-16 text-center font-mono" />
                        <InputError :message="errors[`options.${i}.key`]" />
                    </div>
                    <div class="grid gap-1">
                        <DestinationPicker :name="`options[${i}]`" :numbers="numbers" :value="row.destination" />
                        <InputError :message="errors[`options.${i}.type`]" />
                    </div>
                    <Button type="button" size="sm" variant="ghost" :aria-label="`Remove option ${i + 1}`" @click="removeRow(row.id)"><Trash2 class="size-4" /></Button>
                </div>
                <InputError :message="errors.options" />
                <Button type="button" size="sm" variant="outline" @click="addRow"><Plus class="size-4" /> Add option</Button>
            </section>

            <section class="space-y-2">
                <Label for="default_destination">No key pressed</Label>
                <DestinationPicker id="default_destination" name="default_destination" :numbers="numbers" :value="ivr.default_destination" optional />
                <InputError :message="errors['default_destination.type']" />
            </section>

            <Button :disabled="processing">Save IVR</Button>
        </Form>
    </div>
</template>
