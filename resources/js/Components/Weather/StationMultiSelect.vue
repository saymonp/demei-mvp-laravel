<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Check, ChevronDown, MapPin, X } from '@lucide/vue';
import type { StationLite } from '../../weather/compare';

const props = withDefaults(defineProps<{ stations: StationLite[]; modelValue: number[]; colors: string[]; min?: number; max?: number }>(), { min: 2, max: 4 });
const emit = defineEmits<{ 'update:modelValue': [ids: number[]] }>();
const open = ref(false);
const root = ref<HTMLElement>();
const selected = computed(() => props.modelValue.map(id => props.stations.find(s => s.id === id)).filter(Boolean) as StationLite[]);

function toggle(id: number) {
  const ids = props.modelValue.includes(id) ? props.modelValue.filter(x => x !== id) : [...props.modelValue, id];
  if (ids.length < props.min || ids.length > props.max) return;
  emit('update:modelValue', ids);
}
const close = (e: MouseEvent) => { if (!root.value?.contains(e.target as Node)) open.value = false; };
onMounted(() => document.addEventListener('click', close));
onBeforeUnmount(() => document.removeEventListener('click', close));
</script>

<template>
  <div ref="root" class="relative">
    <button type="button" @click="open = !open" :aria-expanded="open"
      class="flex min-h-11 w-full items-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-2 text-left text-[var(--foreground)] shadow-sm transition hover:border-slate-300">
      <MapPin :size="16" class="shrink-0 text-slate-400" />
      <div class="flex flex-1 flex-wrap gap-1.5">
        <span v-for="(s, i) in selected" :key="s.id" class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--secondary)] px-2 py-0.5 text-xs font-semibold text-[var(--foreground)]">
          <span class="h-2 w-2 rounded-full" :style="{ background: colors[i % colors.length] }" />{{ s.name }}
          <X v-if="modelValue.length > min" :size="12" class="cursor-pointer opacity-60 hover:opacity-100" @click.stop="toggle(s.id)" />
        </span>
      </div>
      <span class="text-[11px] text-slate-400">{{ modelValue.length }}/{{ max }}</span>
      <ChevronDown :size="16" :class="['text-slate-400 transition', open && 'rotate-180']" />
    </button>
    <transition enter-from-class="opacity-0 -translate-y-1" leave-to-class="opacity-0 -translate-y-1" enter-active-class="transition" leave-active-class="transition">
      <ul v-if="open" class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--card)] p-1 text-[var(--foreground)] shadow-xl">
        <li v-for="s in stations" :key="s.id">
          <button type="button" @click="toggle(s.id)"
            :disabled="!modelValue.includes(s.id) && modelValue.length >= max"
            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-[var(--muted)] disabled:cursor-not-allowed disabled:opacity-40">
            <span :class="['flex h-4 w-4 items-center justify-center rounded border', modelValue.includes(s.id) ? 'border-[var(--primary)] bg-[var(--primary)] text-white' : 'border-slate-300']">
              <Check v-if="modelValue.includes(s.id)" :size="12" />
            </span>
            <span class="flex-1 text-left font-medium">{{ s.name }}</span>
            <span class="text-xs text-slate-400">{{ s.provider }}</span>
          </button>
        </li>
        <li class="px-3 py-1.5 text-[11px] text-slate-400">Selecione de {{ min }} a {{ max }} estações</li>
      </ul>
    </transition>
  </div>
</template>
