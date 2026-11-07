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

export function dateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export function titleCase(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

/** "October 2026" for a billing period starting at `iso`, in the account's time zone. */
export function billingMonth(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone }).format(new Date(iso));
}

export function date(iso: string | null | undefined, timeZone?: string): string {
    return iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone }).format(new Date(iso)) : '—';
}
