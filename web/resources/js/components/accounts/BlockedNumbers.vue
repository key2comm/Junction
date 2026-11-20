<script setup lang="ts">
/**
 * Customers → a customer → Blocked numbers: exact numbers this customer may not
 * call, or receive calls from on its DIDs. Only this customer: other customers
 * on the switch can still call the number.
 */
import { Form, router } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import BlockedNumberController from '@/actions/App/Http/Controllers/Admin/BlockedNumberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateTime } from '@/lib/format';

export type BlockedNumber = { id: number; number: string; direction: string; reason: string | null; created_at: string };

const props = defineProps<{ accountId: number; numbers: BlockedNumber[]; directions: Record<string, string> }>();

function setDirection(n: BlockedNumber, direction: string) {
    router.patch(BlockedNumberController.update.url([props.accountId, n.id]), { direction, reason: n.reason }, { preserveScroll: true });
}
</script>

<template>
    <section class="ss-stack-3">
        <Heading
            variant="small"
            title="Blocked numbers"
            description="Numbers this customer can't call, or take calls from on its DIDs. Only for this customer: others on the switch can still call them. Refused with 403."
        />
        <Form
            v-bind="BlockedNumberController.store.form(accountId)"
            reset-on-success
            :options="{ preserveScroll: true }"
            class="ss-grid gap-2 ss-gtc-sm-1fr-1fr-auto align-items-sm-start"
            v-slot="{ errors, processing }"
        >
            <div class="ss-grid gap-1">
                <Input name="number" placeholder="Number, e.g. 14165551234" required inputmode="tel" class="font-monospace" aria-label="Number" />
                <InputError :message="errors.number" />
            </div>
            <div class="ss-grid gap-1">
                <select name="direction" class="form-select" aria-label="Block">
                    <option v-for="(label, value) in directions" :key="value" :value="value">{{ label }}</option>
                </select>
                <InputError :message="errors.direction" />
            </div>
            <Button :disabled="processing">Block</Button>
            <div class="ss-grid gap-1" style="grid-column: 1 / -1">
                <Input name="reason" placeholder="Reason (optional), e.g. retired forwarding number" maxlength="255" aria-label="Reason" />
                <InputError :message="errors.reason" />
            </div>
        </Form>

        <div class="card table-responsive">
            <table class="table table-vcenter card-table table-tight">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Blocks</th>
                        <th>Reason</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="n in numbers" :key="n.id">
                        <td class="text-nowrap">
                            <span class="font-monospace">{{ n.number }}</span>
                            <span class="d-block small text-secondary">{{ dateTime(n.created_at) }}</span>
                        </td>
                        <td>
                            <select :value="n.direction" class="form-select form-select-sm w-auto" :aria-label="`Block for ${n.number}`" @change="setDirection(n, ($event.target as HTMLSelectElement).value)">
                                <option v-for="(label, value) in directions" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </td>
                        <td class="text-secondary">{{ n.reason ?? '—' }}</td>
                        <td>
                            <Form v-bind="BlockedNumberController.destroy.form([accountId, n.id])" :options="{ preserveScroll: true }" v-slot="{ processing }">
                                <Button size="sm" variant="ghost" :disabled="processing" :aria-label="`Unblock ${n.number}`"><Trash2 class="icon" /></Button>
                            </Form>
                        </td>
                    </tr>
                    <tr v-if="numbers.length === 0">
                        <td colspan="4" class="text-center text-secondary">No blocked numbers.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
