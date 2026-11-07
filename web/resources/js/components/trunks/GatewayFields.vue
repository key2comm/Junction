<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/ui';

export type GatewayValues = {
    name: string;
    host: string;
    port: number;
    transport: string;
    username: string | null;
    register: boolean;
    enabled: boolean;
    priority: number;
    weight: number;
    has_password?: boolean;
};

defineProps<{
    gateway?: Partial<GatewayValues>;
    transports: string[];
    errors: Record<string, string | undefined>;
}>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-6">
        <div class="grid gap-1 sm:col-span-3">
            <Label for="gw_host">Host</Label>
            <Input id="gw_host" name="host" :default-value="gateway?.host" required placeholder="sip.vendor.example or 203.0.113.10" class="font-mono" />
            <InputError :message="errors.host" />
        </div>
        <div class="grid gap-1">
            <Label for="gw_port">Port</Label>
            <Input id="gw_port" name="port" type="number" min="1" max="65535" :default-value="gateway?.port ?? 5060" required />
            <InputError :message="errors.port" />
        </div>
        <div class="grid gap-1 sm:col-span-2">
            <Label for="gw_transport">Transport</Label>
            <select id="gw_transport" name="transport" :class="selectClass">
                <option v-for="t in transports" :key="t" :value="t" :selected="t === (gateway?.transport ?? 'udp')">{{ t.toUpperCase() }}</option>
            </select>
            <InputError :message="errors.transport" />
        </div>
        <div class="grid gap-1 sm:col-span-2">
            <Label for="gw_name">Name</Label>
            <Input id="gw_name" name="name" :default-value="gateway?.name" placeholder="auto" class="font-mono lowercase" />
            <InputError :message="errors.name" />
        </div>
        <div class="grid gap-1 sm:col-span-2">
            <Label for="gw_username">Username</Label>
            <Input id="gw_username" name="username" :default-value="gateway?.username ?? ''" autocomplete="off" placeholder="IP auth if blank" />
            <InputError :message="errors.username" />
        </div>
        <div class="grid gap-1 sm:col-span-2">
            <Label for="gw_password">Password</Label>
            <Input id="gw_password" name="password" type="password" autocomplete="new-password" :placeholder="gateway?.has_password ? '•••••• (unchanged)' : ''" />
            <InputError :message="errors.password" />
        </div>
        <div class="grid gap-1">
            <Label for="gw_priority">Priority</Label>
            <Input id="gw_priority" name="priority" type="number" min="1" max="100" :default-value="gateway?.priority ?? 1" required />
            <InputError :message="errors.priority" />
        </div>
        <div class="grid gap-1">
            <Label for="gw_weight">Weight</Label>
            <Input id="gw_weight" name="weight" type="number" min="1" max="1000" :default-value="gateway?.weight ?? 100" required />
            <InputError :message="errors.weight" />
        </div>
        <div class="flex flex-wrap items-end gap-4 pb-2 text-sm sm:col-span-4">
            <label class="flex items-center gap-2"><input type="checkbox" name="register" value="1" :checked="gateway?.register ?? false" class="size-4" /> Register</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" :checked="gateway?.enabled ?? true" class="size-4" /> Enabled</label>
        </div>
    </div>
</template>
