<script setup lang="ts">
import { CalendarRange, GitCompareArrows } from '@lucide/vue';
import { previousPeriod, type Period } from '../../weather/compare';

const periodA = defineModel<Period>('periodA', { required: true });
const periodB = defineModel<Period>('periodB', { required: true });
const compare = defineModel<boolean>('compare', { default: false });

function toggleCompare() {
  compare.value = !compare.value;
  if (compare.value) periodB.value = previousPeriod(periodA.value);
}
const setA = (k: keyof Period, v: string) => { periodA.value = { ...periodA.value, [k]: v }; };
const setB = (k: keyof Period, v: string) => { periodB.value = { ...periodB.value, [k]: v }; };
const input = 'rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 py-1.5 text-sm';
</script>

<template>
  <div class="flex flex-wrap items-end gap-3">
    <div>
      <p class="mb-1 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500"><CalendarRange :size="12" /> Período A</p>
      <div class="flex items-center gap-1.5">
        <input type="date" :class="input" :value="periodA.start" :max="periodA.end" @change="setA('start', ($event.target as HTMLInputElement).value)" aria-label="Data inicial" />
        <span class="text-slate-400">→</span>
        <input type="date" :class="input" :value="periodA.end" :min="periodA.start" @change="setA('end', ($event.target as HTMLInputElement).value)" aria-label="Data final" />
      </div>
    </div>

    <button type="button" role="switch" :aria-checked="compare" @click="toggleCompare"
      :class="['flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-semibold transition', compare ? 'bg-[var(--primary)] text-white shadow' : 'bg-[var(--secondary)] text-[var(--foreground)] hover:opacity-80']">
      <GitCompareArrows :size="15" /> Comparar Períodos
    </button>

    <transition enter-from-class="opacity-0 translate-x-2" enter-active-class="transition">
      <div v-if="compare">
        <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-[var(--primary)]">Comparar com...</p>
        <div class="flex items-center gap-1.5">
          <input type="date" :class="input" :value="periodB.start" :max="periodB.end" @change="setB('start', ($event.target as HTMLInputElement).value)" aria-label="Data inicial comparação" />
          <span class="text-slate-400">→</span>
          <input type="date" :class="input" :value="periodB.end" :min="periodB.start" @change="setB('end', ($event.target as HTMLInputElement).value)" aria-label="Data final comparação" />
        </div>
      </div>
    </transition>
  </div>
</template>
