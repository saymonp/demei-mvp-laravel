<script setup lang="ts">
import { computed } from 'vue';
import { CircleCheck, Diamond, Square, CircleDashed, PenLine } from '@lucide/vue';
import { qualityLabels, type Quality } from '../../weather/data';

const props = defineProps<{ flag: Quality; audited?: boolean; compact?: boolean }>();
const styles: Record<Quality, string> = {
  0: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  1: 'bg-red-50 text-red-700 ring-red-600/20',
  2: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  3: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};
const icon = computed(() => [CircleCheck, Square, Diamond, CircleDashed][props.flag]);
</script>

<template>
  <span class="inline-flex items-center gap-1.5">
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset', styles[flag]]">
      <component :is="icon" :size="12" :class="flag === 1 || flag === 2 ? 'fill-current' : ''" />
      <span v-if="!compact">{{ qualityLabels[flag] }}</span>
    </span>
    <span v-if="audited" class="inline-flex items-center gap-1 rounded-full bg-[var(--accent)] px-2 py-0.5 text-[10px] font-medium text-[var(--primary)]" title="Status alterado manualmente">
      <PenLine :size="10" /> Auditado Manualmente
    </span>
  </span>
</template>
