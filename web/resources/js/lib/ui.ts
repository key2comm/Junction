/** Tabler classes for native <select>/<textarea> (kept as constants so pages stay uniform). */
export const selectClass = 'form-select';

export const textareaClass = 'form-control';

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};
