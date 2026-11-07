export { default as Button } from './Button.vue';

export type ButtonVariant = 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link' | 'success';
export type ButtonSize = 'default' | 'sm' | 'lg' | 'icon' | 'icon-sm';

/** Tabler/Bootstrap button classes. */
export function buttonClass(variant: ButtonVariant = 'default', size: ButtonSize = 'default'): string {
    const v = {
        default: 'btn-primary',
        destructive: 'btn-danger',
        outline: 'btn-outline-secondary',
        secondary: 'btn-secondary',
        ghost: 'btn-ghost-secondary',
        link: 'btn-link',
        success: 'btn-success',
    }[variant];
    const s = { default: '', sm: 'btn-sm', lg: 'btn-lg', icon: 'btn-icon', 'icon-sm': 'btn-icon btn-sm' }[size];

    return ['btn', v, s].filter(Boolean).join(' ');
}
