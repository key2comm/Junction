<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandingController from '@/actions/App/Http/Controllers/Settings/BrandingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/branding';

const props = defineProps<{ settings: { logo: string | null; icon: string | null; company: string | null } }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Branding', href: edit() }] } });

const year = new Date().getFullYear();
const footer = computed(() => props.settings.company);
</script>

<template>
    <Head title="Branding" />

    <h1 class="visually-hidden">Branding</h1>

    <Form v-bind="BrandingController.update.form()" class="ss-stack-4 ss-maxw-3xl" v-slot="{ errors, processing }">
        <Heading variant="small" title="Branding" description="Your own logo and icon, shown to you and to customers in the portal, and your company name in the page footer." />

        <section class="card card-body ss-stack-3">
            <div class="ss-grid gap-1">
                <Label for="company_name">Company name</Label>
                <Input id="company_name" name="company_name" :default-value="settings.company ?? ''" maxlength="100" placeholder="e.g. Key2 Comm Inc." />
                <p class="small text-secondary mb-0">
                    Shown at the bottom of every page as
                    <strong>© {{ year }} {{ footer || 'Company name' }}</strong>. The year is always the current one. Leave it blank for no footer.
                </p>
                <InputError :message="errors.company_name" />
            </div>
        </section>

        <section class="card card-body ss-stack-3">
            <div class="ss-grid gap-1">
                <Label for="logo">Company logo</Label>
                <div v-if="settings.logo" class="d-flex align-items-center gap-3 p-2 border rounded bg-body-tertiary">
                    <img :src="settings.logo" alt="Current logo" class="ss-brand-logo" />
                    <label class="d-flex align-items-center gap-2 ms-auto small">
                        <input type="checkbox" name="remove_logo" value="1" class="form-check-input m-0" /> Remove
                    </label>
                </div>
                <Input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg,.webp,.svg,image/png,image/jpeg,image/webp,image/svg+xml" />
                <p class="small text-secondary mb-0">
                    Top left on every page and on the sign-in page, in place of the name. PNG, JPG, WebP or SVG, up to 1 MB. A wide logo about 40 pixels
                    high (or 80 for sharp screens) on a transparent background works best in both light and dark mode.
                </p>
                <InputError :message="errors.logo" />
            </div>
        </section>

        <section class="card card-body ss-stack-3">
            <div class="ss-grid gap-1">
                <Label for="icon">Icon</Label>
                <div v-if="settings.icon" class="d-flex align-items-center gap-3 p-2 border rounded bg-body-tertiary">
                    <img :src="settings.icon" alt="Current icon" width="32" height="32" />
                    <label class="d-flex align-items-center gap-2 ms-auto small">
                        <input type="checkbox" name="remove_icon" value="1" class="form-check-input m-0" /> Remove
                    </label>
                </div>
                <Input id="icon" name="icon" type="file" accept=".png,.ico,.svg,image/png,image/x-icon,image/svg+xml" />
                <p class="small text-secondary mb-0">
                    The browser tab and bookmark icon (and beside the name when there's no logo). PNG, ICO or SVG, up to 512 KB; square, at least 180 × 180
                    pixels.
                </p>
                <InputError :message="errors.icon" />
            </div>
        </section>

        <div>
            <Button :disabled="processing">Save</Button>
        </div>
    </Form>
</template>
