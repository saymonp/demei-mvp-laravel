<script setup lang="ts">
import { computed } from 'vue';
import { Thermometer, Sun, ShieldCheck, TriangleAlert, ArrowUpRight, ArrowDownRight, Minus } from '@lucide/vue';
import { formatNumber } from '../../weather/data';
import { delta, type StationLite, type Summary } from '../../weather/compare';

const props = defineProps<{ station: StationLite; color: string; summary: Summary; previous?: Summary | null }>();

/** goodWhenUp: define se subir é positivo/negativo; métricas informativas permanecem neutras. */
const metrics = computed(() => [
  { label: 'Temp. média', icon: Thermometer, value: `${formatNumber(props.summary.avgTemp)} °C`, d: delta(props.summary.avgTemp, props.previous?.avgTemp ?? null), goodWhenUp: null, desc: 'na média de temperatura' },
  { label: 'Pico irradiância', icon: Sun, value: `${formatNumber(props.summary.peakRad, 0)} W/m²`, d: delta(props.summary.peakRad, props.previous?.peakRad ?? null), goodWhenUp: null, desc: 'no pico de irradiância' },
  { label: 'Qualidade', icon: ShieldCheck, value: `${formatNumber(props.summary.qualityScore)}%`, d: delta(props.summary.qualityScore, props.previous?.qualityScore ?? null), goodWhenUp: true, desc: 'nas leituras válidas' },
  { label: 'Anomalias', icon: TriangleAlert, value: String(props.summary.anomalies), d: delta(props.summary.anomalies, props.previous?.anomalies ?? null), goodWhenUp: false, desc: 'nas anomalias' },
]);
const tone = (d: number, up: boolean | null) => up === null || Math.abs(d) < .5 ? 'text-slate-500' : (d > 0) === up ? 'text-emerald-600' : 'text-red-600';
const scoreRing = computed(() => `conic-gradient(${props.color} ${props.summary.qualityScore * 3.6}deg, rgba(148,163,184,.2) 0)`);
</script>

<template>
  <article class="group relative overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
    <span class="absolute inset-x-0 top-0 h-1" :style="{ background: color }" />
    <header class="mb-4 flex items-center justify-between">
      <div>
        <h3 class="font-bold">{{ station.name }}</h3>
        <p class="text-xs text-slate-400">{{ station.provider }}<span v-if="summary.audited"> · {{ summary.audited }} auditados</span></p>
      </div>
      <div class="grid h-11 w-11 place-items-center rounded-full" :style="{ background: scoreRing }">
        <span class="grid h-8 w-8 place-items-center rounded-full bg-[var(--card)] text-[10px] font-bold">{{ Math.round(summary.qualityScore) }}%</span>
      </div>
    </header>
    <dl class="grid grid-cols-2 gap-3">
      <div v-for="m in metrics" :key="m.label" class="rounded-xl bg-[var(--muted)] p-3">
        <dt class="flex items-center gap-1 text-[11px] font-medium text-slate-500"><component :is="m.icon" :size="12" /> {{ m.label }}</dt>
        <dd class="mt-1 text-lg font-bold tabular-nums">{{ m.value }}</dd>
        <p v-if="previous && m.d !== null" :class="['mt-0.5 flex items-center gap-0.5 text-[11px] font-semibold', tone(m.d, m.goodWhenUp)]"
           :title="`${m.d > 0 ? '+' : ''}${m.d.toFixed(0)}% ${m.desc} em relação ao período anterior`">
          <component :is="Math.abs(m.d) < .5 ? Minus : m.d > 0 ? ArrowUpRight : ArrowDownRight" :size="13" />
          {{ m.d > 0 ? '+' : '' }}{{ m.d.toFixed(1) }}%
        </p>
      </div>
    </dl>
  </article>
</template>
