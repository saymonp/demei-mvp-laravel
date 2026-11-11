<script setup lang="ts">
import { PenLine, Undo2 } from '@lucide/vue';
import { qualityLabels, type Quality } from '../../weather/data';
import QualityBadge from './QualityBadge.vue';

defineProps<{ current: Quality; audited?: boolean }>();
const emit = defineEmits<{ change: [flag: Quality]; reset: [] }>();
</script>

<template>
  <details class="relative inline-block [&_summary::-webkit-details-marker]:hidden">
    <summary class="flex cursor-pointer list-none items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-[var(--primary)] hover:bg-[var(--accent)]">
      <PenLine :size="12" /> Alterar Qualidade
    </summary>
    <div class="absolute right-0 z-20 mt-1 w-44 rounded-xl border border-[var(--border)] bg-[var(--card)] p-1 shadow-xl">
      <button v-for="(l, f) in qualityLabels" :key="l" type="button" :disabled="f === current"
        class="flex w-full items-center rounded-lg px-2 py-1.5 hover:bg-[var(--muted)] disabled:opacity-40"
        @click="emit('change', f as Quality); ($event.currentTarget as HTMLElement).closest('details')?.removeAttribute('open')">
        <QualityBadge :flag="f as Quality" />
      </button>
      <button v-if="audited" type="button" class="mt-1 flex w-full items-center gap-1.5 border-t border-[var(--border)] px-2 py-1.5 text-xs text-slate-500 hover:text-slate-800" @click="emit('reset')">
        <Undo2 :size="12" /> Restaurar automático
      </button>
    </div>
  </details>
</template>
