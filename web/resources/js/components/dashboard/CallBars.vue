<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Stacked bars of calls per period: answered at the base, failed on top,
 * the total labelled above. Hover (or focus) a column for its numbers.
 */
export type CallBar = { key: string; label: string; long: string; calls: number; answered: number; highlight?: boolean };

const props = withDefaults(defineProps<{ items: CallBar[]; title: string; height?: number; labelEvery?: number }>(), { height: 220, labelEvery: 1 });

const w = 700;
const top = 22;
const bottom = 30;
const gap = 2;
const h = computed(() => props.height);
const plotH = computed(() => h.value - top - bottom);
const base = computed(() => h.value - bottom);
const slot = computed(() => w / Math.max(1, props.items.length));
const barW = computed(() => Math.min(56, slot.value * 0.6));
const max = computed(() => Math.max(1, ...props.items.map((d) => d.calls)));
const n = (v: number) => v.toLocaleString();
// With many thin bars, value labels would collide: label only the peak.
const peak = computed(() => props.items.reduce((best, d, i) => (d.calls > (props.items[best]?.calls ?? -1) ? i : best), 0));
const labelValues = computed(() => props.items.length <= 10);

const bars = computed(() =>
    props.items.map((d, i) => {
        const scale = plotH.value / max.value;
        const answeredH = d.answered * scale;
        const failedH = (d.calls - d.answered) * scale;

        return {
            ...d,
            i,
            x: i * slot.value + (slot.value - barW.value) / 2,
            answeredY: base.value - answeredH,
            answeredH,
            failedY: base.value - answeredH - failedH,
            failedH: Math.max(0, failedH - (answeredH > 0 && failedH > 0 ? gap : 0)),
            topY: base.value - answeredH - failedH,
        };
    }),
);

function niceStep(m: number) {
    const raw = m / 3;
    const mag = 10 ** Math.floor(Math.log10(raw || 1));
    const f = raw / mag;

    return Math.max(1, (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * mag);
}
const gridLines = computed(() => {
    const step = niceStep(max.value);
    const lines: { v: number; y: number }[] = [];
    for (let v = step; v <= max.value; v += step) {
        lines.push({ v, y: base.value - (v / max.value) * plotH.value });
    }

    return lines;
});

const hover = ref<number | null>(null);
const tip = computed(() => (hover.value === null ? null : (bars.value[hover.value] ?? null)));
const total = computed(() => props.items.reduce((t, d) => t + d.calls, 0));
</script>

<template>
    <div class="position-relative">
        <svg :viewBox="`0 0 ${w} ${h}`" class="w-100 d-block" role="img" :aria-label="`${title}: ${total} calls in total`" @mouseleave="hover = null">
            <g v-for="g in gridLines" :key="g.v">
                <line x1="0" :x2="w" :y1="g.y" :y2="g.y" class="cb-grid" />
                <text x="0" :y="g.y - 4" class="cb-axis">{{ n(g.v) }}</text>
            </g>
            <line x1="0" :x2="w" :y1="base" :y2="base" class="cb-base" />
            <g v-for="b in bars" :key="b.key">
                <rect v-if="b.failedH > 0" :x="b.x" :y="b.failedY" :width="barW" :height="b.failedH" :rx="Math.min(4, barW / 4)" class="cb-failed" />
                <rect v-if="b.answeredH > 0" :x="b.x" :y="b.answeredY" :width="barW" :height="b.answeredH" :rx="Math.min(4, barW / 4)" class="cb-answered" />
                <text
                    v-if="b.calls > 0 && (labelValues || b.i === peak)"
                    :x="b.x + barW / 2"
                    :y="b.topY - 6"
                    text-anchor="middle"
                    class="cb-value"
                >
                    {{ n(b.calls) }}
                </text>
                <text v-if="b.i % labelEvery === 0 || b.highlight" :x="b.x + barW / 2" :y="h - 10" text-anchor="middle" class="cb-axis" :class="{ 'cb-axis-now': b.highlight }">
                    {{ b.label }}
                </text>
                <!-- Hit target: the whole column, bigger than the bar. -->
                <rect
                    :x="b.i * slot"
                    y="0"
                    :width="slot"
                    :height="h"
                    fill="transparent"
                    tabindex="0"
                    :aria-label="`${b.long}: ${b.calls} calls, ${b.answered} answered, ${b.calls - b.answered} failed`"
                    @mouseenter="hover = b.i"
                    @focus="hover = b.i"
                    @blur="hover = null"
                />
            </g>
        </svg>
        <div v-if="tip" class="cb-tip card shadow-sm small p-2" :style="{ left: `${Math.min(85, Math.max(15, ((tip.x + barW / 2) / w) * 100))}%` }">
            <div class="fw-semibold mb-1">{{ tip.long }}</div>
            <div class="d-flex justify-content-between gap-3"><span class="text-secondary">Calls</span><span class="tabular-nums">{{ n(tip.calls) }}</span></div>
            <div class="d-flex justify-content-between gap-3"><span class="text-secondary">Answered</span><span class="tabular-nums">{{ n(tip.answered) }}</span></div>
            <div class="d-flex justify-content-between gap-3"><span class="text-secondary">Failed</span><span class="tabular-nums">{{ n(tip.calls - tip.answered) }}</span></div>
        </div>
    </div>
</template>

<style scoped>
.cb-answered {
    fill: var(--tblr-primary);
}
.cb-failed {
    fill: rgba(var(--tblr-primary-rgb), 0.3);
}
.cb-grid {
    stroke: var(--tblr-border-color);
    stroke-dasharray: 3 4;
}
.cb-base {
    stroke: var(--tblr-border-color);
}
.cb-axis {
    fill: var(--tblr-secondary);
    font-size: 12px;
}
.cb-axis-now {
    fill: var(--tblr-body-color);
    font-weight: 600;
}
.cb-value {
    fill: var(--tblr-body-color);
    font-size: 12px;
    font-weight: 600;
}
.cb-tip {
    position: absolute;
    top: 0;
    transform: translateX(-50%);
    pointer-events: none;
    min-width: 11rem;
    z-index: 2;
}
</style>
