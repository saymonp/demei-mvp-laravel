<script setup lang="ts">
import { onErrorCaptured, ref } from 'vue';

const failed = ref(false);

onErrorCaptured(error => {
  console.error('[StationMapBoundary]', error);
  failed.value = true;

  return false;
});
</script>

<template>
  <div>
    <slot v-if="!failed" />
    <div v-else class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-6 text-sm" role="alert">
      <p class="font-bold">O mapa não pôde ser exibido.</p>
      <p class="mt-1 text-xs text-slate-500">As demais seções do painel continuam funcionando.</p>
      <button type="button" class="mt-3 rounded-lg bg-[var(--primary)] px-3 py-2 text-xs font-semibold text-white hover:opacity-90" @click="failed = false">
        Tentar de novo
      </button>
    </div>
  </div>
</template>
