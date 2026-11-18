<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CalendarRange, GitCompareArrows } from '@lucide/vue';
import { previousPeriod, type Period } from '../../weather/compare';

const periodA = defineModel<Period>('periodA', { required: true });
const periodB = defineModel<Period>('periodB', { required: true });
const compare = defineModel<boolean>('compare', { default: false });
const draftA = ref<Period>({ ...periodA.value });
const draftB = ref<Period>({ ...periodB.value });
const draftCompare = ref(compare.value);

function toggleCompare() {
  draftCompare.value = !draftCompare.value;
  if (draftCompare.value) draftB.value = previousPeriod(draftA.value);
}
const validPeriod = (period: Period) => Boolean(period.start && period.end && period.start <= period.end);
const hasChanges = computed(() => draftA.value.start !== periodA.value.start
  || draftA.value.end !== periodA.value.end
  || draftCompare.value !== compare.value
  || (draftCompare.value && (draftB.value.start !== periodB.value.start || draftB.value.end !== periodB.value.end)));
const canApply = computed(() => validPeriod(draftA.value)
  && (!draftCompare.value || validPeriod(draftB.value))
  && hasChanges.value);

function apply() {
  if (!canApply.value) return;

  periodA.value = { ...draftA.value };
  if (draftCompare.value) periodB.value = { ...draftB.value };
  compare.value = draftCompare.value;
}

watch(periodA, value => { draftA.value = { ...value }; }, { deep: true });
watch(periodB, value => { draftB.value = { ...value }; }, { deep: true });
watch(compare, value => { draftCompare.value = value; });
const input = 'rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 py-1.5 text-sm';
</script>

<template>
  <div class="flex flex-wrap items-end gap-3">
    <div>
      <p class="mb-1 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500"><CalendarRange :size="12" /> Período A</p>
      <div class="flex items-center gap-1.5">
        <input v-model="draftA.start" type="date" :class="input" :max="draftA.end" aria-label="Data inicial" />
        <span class="text-slate-400">→</span>
        <input v-model="draftA.end" type="date" :class="input" :min="draftA.start" aria-label="Data final" />
      </div>
    </div>

    <button type="button" role="switch" :aria-checked="draftCompare" @click="toggleCompare"
      :class="['flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-semibold transition', draftCompare ? 'bg-[var(--primary)] text-white shadow' : 'bg-[var(--secondary)] text-[var(--foreground)] hover:opacity-80']">
      <GitCompareArrows :size="15" /> Comparar Períodos
    </button>

    <transition enter-from-class="opacity-0 translate-x-2" enter-active-class="transition">
      <div v-if="draftCompare">
        <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-[var(--primary)]">Comparar com...</p>
        <div class="flex items-center gap-1.5">
          <input v-model="draftB.start" type="date" :class="input" :max="draftB.end" aria-label="Data inicial comparação" />
          <span class="text-slate-400">→</span>
          <input v-model="draftB.end" type="date" :class="input" :min="draftB.start" aria-label="Data final comparação" />
        </div>
      </div>
    </transition>

    <button type="button" :disabled="!canApply" @click="apply"
      class="h-9 rounded-lg bg-[var(--primary)] px-3 text-sm font-semibold text-white shadow transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
      Aplicar período
    </button>
  </div>
</template>
