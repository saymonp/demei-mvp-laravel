<script setup lang="ts">
import { SlidersHorizontal, RotateCcw, X, Thermometer, Sun } from '@lucide/vue';
import { DEFAULT_THRESHOLDS, type Thresholds } from '../../weather/compare';

const model = defineModel<Thresholds>({ required: true });
const open = defineModel<boolean>('open', { default: false });
defineProps<{ counts: Record<number, number> }>();
const set = (k: keyof Thresholds, v: string) => { model.value = { ...model.value, [k]: Number(v) }; };
</script>

<template>
  <transition enter-from-class="translate-x-full" leave-to-class="translate-x-full" enter-active-class="transition duration-300" leave-active-class="transition duration-200">
    <aside v-if="open" class="fixed inset-y-0 right-0 z-40 flex w-full max-w-sm flex-col gap-6 border-l border-[var(--border)] bg-[var(--card)] p-6 text-[var(--foreground)] shadow-2xl">
      <header class="flex items-center justify-between">
        <h2 class="flex items-center gap-2 text-base font-bold"><SlidersHorizontal :size="18" /> Limiares de Qualidade</h2>
        <button type="button" class="rounded-lg p-1.5 hover:bg-[var(--muted)]" @click="open = false" aria-label="Fechar"><X :size="18" /></button>
      </header>
      <p class="text-sm text-slate-500">Arraste para reclassificar em tempo real a variação máxima entre leituras consecutivas.</p>

      <label class="block">
        <span class="mb-2 flex items-center justify-between text-sm font-semibold"><span class="flex items-center gap-1.5"><Thermometer :size="15" class="text-orange-500" /> Variação máx. temperatura</span>
          <span class="rounded-md bg-orange-50 px-2 py-0.5 font-mono text-orange-700">{{ model.maxTempDelta.toFixed(1) }} °C</span></span>
        <input type="range" min="0.5" max="15" step="0.5" :value="model.maxTempDelta" @input="set('maxTempDelta', ($event.target as HTMLInputElement).value)" class="w-full accent-orange-500" />
      </label>

      <label class="block">
        <span class="mb-2 flex items-center justify-between text-sm font-semibold"><span class="flex items-center gap-1.5"><Sun :size="15" class="text-sky-500" /> Variação máx. irradiância</span>
          <span class="rounded-md bg-sky-50 px-2 py-0.5 font-mono text-sky-700">{{ model.maxRadDelta }} W/m²</span></span>
        <input type="range" min="50" max="1000" step="10" :value="model.maxRadDelta" @input="set('maxRadDelta', ($event.target as HTMLInputElement).value)" class="w-full accent-sky-500" />
      </label>

      <div class="grid grid-cols-2 gap-2 text-xs">
        <div class="rounded-lg bg-emerald-50 p-2 text-emerald-700">● Válido: <b>{{ counts[0] ?? 0 }}</b></div>
        <div class="rounded-lg bg-amber-50 p-2 text-amber-700">◆ Suspeito: <b>{{ counts[2] ?? 0 }}</b></div>
        <div class="rounded-lg bg-red-50 p-2 text-red-700">■ Inválido: <b>{{ counts[1] ?? 0 }}</b></div>
        <div class="rounded-lg bg-[var(--muted)] p-2 text-slate-600">○ Lacuna: <b>{{ counts[3] ?? 0 }}</b></div>
      </div>

      <button type="button" class="mt-auto flex items-center justify-center gap-2 rounded-lg border border-[var(--border)] py-2 text-sm font-semibold hover:bg-[var(--muted)]" @click="model = { ...DEFAULT_THRESHOLDS }">
        <RotateCcw :size="14" /> Restaurar padrão
      </button>
    </aside>
  </transition>
  <div v-if="open" class="fixed inset-0 z-30 bg-slate-900/20 backdrop-blur-[1px]" @click="open = false" />
</template>
