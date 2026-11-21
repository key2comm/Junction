import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Areas of the admin UI (App\Support\AdminAreas). */
export type AdminArea = 'accounts' | 'routing' | 'monitoring' | 'billing' | 'settings' | 'users' | 'audit';
type Level = 'write' | 'read' | null;

export type Access = {
    role: string;
    label: string;
    areas: Record<AdminArea, Level>;
    /** This page's area: "read" means the admin can look but not change it. */
    page: Level;
};

/** The signed-in admin's access level (Settings → Admin users); null for portal users. */
export function useAccess() {
    const page = usePage();
    const access = computed(() => (page.props as { access?: Access | null }).access ?? null);

    /** Can they open this area (or change it, with write = true)? */
    const can = (area: AdminArea, write = false) => {
        const level = access.value?.areas[area] ?? null;

        return write ? level === 'write' : level !== null;
    };

    return { access, can };
}
