<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatNumber, timeLabel, type Quality } from '../../weather/data';
import { TEMP, type ClassifiedPoint, type StationLite } from '../../weather/compare';
import QualityBadge from './QualityBadge.vue';
import QualityOverrideMenu from './QualityOverrideMenu.vue';

const props = defineProps<{ stations: StationLite[]; colors: string[]; data: Record<number, ClassifiedPoint[]> }>();
const emit = defineEmits<{ override: [p: ClassifiedPoint, flag: Quality]; reset: [p: ClassifiedPoint] }>();
const filter = ref<'issues' | 'audited' | 'all'>('issues');
const page = ref(0); const PER = 15;

const rows = computed(() => props.stations.flatMap(s => (props.data[s.id] ?? []))
  .filter(p => filter.value === 'all' || (filter.value === 'audited' ? p.audited : p.quality_flag !== 0 || p.audited))
  .sort((a, b) => a.timestamp.localeCompare(b.timestamp)));
const visible = computed(() => rows.value.slice(page.value * PER, (page.value + 1) * PER));
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / PER)));
const stationOf = (id: number) => props.stations.findIndex(s => s.id === id);
const dateLabel = (ts: string) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', timeZone: 'America/Sao_Paulo' }).format(new Date(ts));
</script>

<template>
  <section class="rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-sm">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] p-4">
      <div>
        <h2 class="font-bold">Auditoria de leituras</h2>
        <p class="text-xs text-slate-500">{{ rows.length }} registros</p>
      </div>
      <div class="flex rounded-lg bg-[var(--secondary)] p-0.5 text-xs font-semibold">
        <button v-for="o in [['issues','Anomalias'],['audited','Auditados'],['all','Todos']]" :key="o[0]" type="button"
          :class="['rounded-md px-3 py-1.5', filter === o[0] ? 'bg-[var(--card)] text-[var(--primary)] shadow' : 'text-slate-500']"
          @click="filter = o[0] as any; page = 0">{{ o[1] }}</button>
      </div>
    </header>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-left text-[11px] uppercase tracking-wide text-slate-400">
          <tr><th class="px-4 py-2">Estação</th><th>Data/Hora</th><th>Sensor</th><th class="text-right">Valor</th><th class="pl-4">Status</th><th>Motivo</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="p in visible" :key="p.measurement_id ?? `${p.station_id}${p.sensor_measurement_type}${p.timestamp}`"
              :class="['border-t border-[var(--border)]', p.audited && 'bg-[var(--accent)]']">
            <td class="px-4 py-2 font-medium"><span class="mr-1.5 inline-block h-2 w-2 rounded-full" :style="{ background: colors[stationOf(p.station_id) % colors.length] }" />{{ stations[stationOf(p.station_id)]?.name }}</td>
            <td class="tabular-nums text-slate-500">{{ dateLabel(p.timestamp) }} {{ timeLabel(p.timestamp) }}</td>
            <td>{{ p.sensor_measurement_type === TEMP ? 'Temperatura' : 'Irradiância' }}</td>
            <td class="text-right font-mono">{{ p.value === null ? '—' : formatNumber(p.value) }}</td>
            <td class="pl-4"><QualityBadge :flag="p.quality_flag" :audited="p.audited" /></td>
            <td class="max-w-[180px] truncate text-xs text-slate-500">{{ p.flag_reason ?? '—' }}</td>
            <td class="pr-3 text-right"><QualityOverrideMenu :current="p.quality_flag" :audited="p.audited" @change="f => emit('override', p, f)" @reset="emit('reset', p)" /></td>
          </tr>
          <tr v-if="!visible.length"><td colspan="7" class="py-10 text-center text-slate-400">Nenhum registro neste filtro.</td></tr>
        </tbody>
      </table>
    </div>
    <footer v-if="pages > 1" class="flex items-center justify-end gap-2 p-3 text-xs">
      <button type="button" class="rounded px-2 py-1 hover:bg-[var(--muted)] disabled:opacity-30" :disabled="page === 0" @click="page--">Anterior</button>
      <span>{{ page + 1 }} / {{ pages }}</span>
      <button type="button" class="rounded px-2 py-1 hover:bg-[var(--muted)] disabled:opacity-30" :disabled="page >= pages - 1" @click="page++">Próxima</button>
    </footer>
  </section>
</template>
