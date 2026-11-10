import { ref } from 'vue';

/** Format a decimal string from the API as money, keeping sub-cent precision when present. */
export function money(value: string | number | null | undefined, currency = 'USD'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const n = Number(value);
    const decimals = Math.abs(n) < 1 && n !== 0 ? 4 : 2;

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        minimumFractionDigits: decimals,
        maximumFractionDigits: Math.max(decimals, 6),
    }).format(n);
}

/**
 * The time zone the site shows times in: the signed-in user's choice
 * (Settings → Profile), UTC by default. Reactive, so pages re-render when it changes.
 */
export const timeZone = ref('UTC');

export function setTimeZone(tz: unknown): void {
    timeZone.value = typeof tz === 'string' && tz !== '' ? tz : 'UTC';
}

export function dateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString(undefined, { timeZone: timeZone.value }) : '—';
}

/** Wall-clock parts of `d` in `tz`. */
function zonedParts(d: Date, tz: string) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
    }).formatToParts(d);
    const get = (t: string) => Number(parts.find((p) => p.type === t)?.value ?? 0);

    return { y: get('year'), mo: get('month'), d: get('day'), h: get('hour'), mi: get('minute'), s: get('second') };
}

/** An ISO instant as an <input type="datetime-local"> value in the user's time zone. */
export function toZonedInput(iso: string | number | null | undefined): string {
    if (!iso) return '';
    const p = zonedParts(new Date(String(iso)), timeZone.value);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${p.y}-${pad(p.mo)}-${pad(p.d)}T${pad(p.h)}:${pad(p.mi)}`;
}

/** An <input type="datetime-local"> value, read in the user's time zone, as ISO UTC. */
export function fromZonedInput(value: string): string {
    const [date, time = '00:00'] = value.split('T');
    const [y, mo, d] = date.split('-').map(Number);
    const [h, mi] = time.split(':').map(Number);
    const wall = Date.UTC(y, mo - 1, d, h, mi);
    // The zone's offset at that moment (checked twice so DST changes land right).
    const offset = (at: number) => {
        const p = zonedParts(new Date(at), timeZone.value);

        return Date.UTC(p.y, p.mo - 1, p.d, p.h, p.mi, p.s) - Math.floor(at / 1000) * 1000;
    };
    let at = wall - offset(wall);
    at = wall - offset(at);

    return new Date(at).toISOString();
}

export function titleCase(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

/** "October 2026" for a billing period starting at `iso`, in the account's time zone. */
export function billingMonth(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone }).format(new Date(iso));
}

export function date(iso: string | null | undefined, tz?: string): string {
    return iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone: tz ?? timeZone.value }).format(new Date(iso)) : '—';
}
