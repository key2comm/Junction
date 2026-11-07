import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Whether the built-in billing is on (Settings → Billing). When off the switch
 * only routes calls: balances, payments, invoices and payables are hidden.
 */
export function useBilling() {
    const page = usePage();

    return computed(() => (page.props as { billing?: boolean }).billing !== false);
}
