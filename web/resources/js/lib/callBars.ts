import type { CallBar } from '@/components/dashboard/CallBars.vue';

/**
 * Bars for the dashboard charts from the server's buckets, which are already
 * in the viewer's time zone (so they're formatted as UTC here, unshifted).
 */
export function dayBars(week: { day: string; calls: number; answered: number }[]): CallBar[] {
    return week.map((d, i) => {
        const day = new Date(`${d.day}T12:00:00Z`);
        const today = i === week.length - 1;

        return {
            key: d.day,
            calls: d.calls,
            answered: d.answered,
            label: today ? 'Today' : day.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' }),
            long: day.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric', timeZone: 'UTC' }),
            highlight: today,
        };
    });
}

export function hourBars(hours: { hour: string; calls: number; answered: number }[]): CallBar[] {
    const time = (d: Date) => d.toLocaleTimeString(undefined, { hour: 'numeric', timeZone: 'UTC' });

    return hours.map((d, i) => {
        const [day, hh] = d.hour.split(' ');
        const now = i === hours.length - 1;
        const at = new Date(`${day}T${hh}:00:00Z`);

        return {
            key: d.hour,
            calls: d.calls,
            answered: d.answered,
            label: now ? 'Now' : time(at),
            long: `${at.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' })} ${time(at)} – ${time(new Date(at.getTime() + 3600000))}`,
            highlight: now,
        };
    });
}
