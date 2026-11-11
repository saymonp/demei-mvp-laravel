<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import VChart from 'vue-echarts';
import '../../css/weather-dashboard.css';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, DataZoomComponent } from 'echarts/components';
import { SlidersHorizontal, Thermometer, Sun, GitCompareArrows, LayoutGrid, Rows3 } from '@lucide/vue';
import type { Measurement, Quality } from '../weather/data';
import {
  DEFAULT_THRESHOLDS, STATION_COLORS, TEMP, RAD, demoSeries, overrideKey, reclassify, summarize,
  type ClassifiedPoint, type OverrideMap, type Period, type StationLite, type Thresholds, previousPeriod,
} from '../weather/compare';
import { multiLineOptions, stationLines } from '../weather/compare-chart-options';
import StationMultiSelect from '../Components/Weather/StationMultiSelect.vue';
import PeriodSelector from '../Components/Weather/PeriodSelector.vue';
import ThresholdPanel from '../Components/Weather/ThresholdPanel.vue';
import StationSummaryCard from '../Components/Weather/StationSummaryCard.vue';
import AuditTable from '../Components/Weather/AuditTable.vue';
import QualityBadge from '../Components/Weather/QualityBadge.vue';
import QualityOverrideMenu from '../Components/Weather/QualityOverrideMenu.vue';

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent, DataZoomComponent]);

/**
 * Props vindas de StationComparisonController@index. Se `series` vier vazio, usa dados demo
 * (data.ts) para permitir validar a UI sem backend.
 */
const props = withDefaults(defineProps<{
  stations: StationLite[];
  selectedStationIds?: number[];
  periodA?: Period; periodB?: Period | null;
  series?: Record<number, Measurement[]>;
  seriesB?: Record<number, Measurement[]>;
  overrides?: OverrideMap;
  canAudit?: boolean;
}>(), { series: () => ({}), seriesB: () => ({}), overrides: () => ({}), canAudit: true });

const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date());
const selectedIds = ref<number[]>(props.selectedStationIds?.length ? props.selectedStationIds : props.stations.slice(0, 3).map(s => s.id));
const periodA = ref<Period>(props.periodA ?? { start: today, end: today });
const periodB = ref<Period>(props.periodB ?? previousPeriod(periodA.value));
const compareMode = ref(!!props.periodB);
const thresholds = ref<Thresholds>({ ...DEFAULT_THRESHOLDS });
const panelOpen = ref(false);
const overrides = ref<OverrideMap>({ ...props.overrides });
const layout = ref<'overlay' | 'split'>('overlay');
const metric = ref<'temp' | 'rad'>('temp');

const selected = computed(() => selectedIds.value.map(id => props.stations.find(s => s.id === id)!).filter(Boolean));
const useDemo = computed(() => Object.keys(props.series).length === 0);
const raw = (id: number, b = false) => useDemo.value ? demoSeries(id, b ? periodB.value : periodA.value) : (b ? props.seriesB : props.series)[id] ?? [];

// Reclassificação reativa: qualquer mudança nos sliders/overrides recalcula tudo.
const classified = computed(() => Object.fromEntries(selected.value.map(s => [s.id, reclassify(s.id, raw(s.id), thresholds.value, overrides.value)])) as Record<number, ClassifiedPoint[]>);
const classifiedB = computed(() => compareMode.value ? Object.fromEntries(selected.value.map(s => [s.id, reclassify(s.id, raw(s.id, true), thresholds.value, overrides.value)])) as Record<number, ClassifiedPoint[]> : {});

const metricMeta = computed(() => ({ temp: { type: TEMP, unit: '°C', title: 'Temperatura do ar' }, rad: { type: RAD, unit: 'W/m²', title: 'Irradiância solar' } }[metric.value]));
const multiDay = computed(() => periodA.value.start !== periodA.value.end);

const overlayOption = computed(() => multiLineOptions(stationLines(selected.value, STATION_COLORS, classified.value, metricMeta.value.type), { unit: metricMeta.value.unit, multiDay: multiDay.value }));
const splitOptions = computed(() => [
  { key: 'temp', icon: Thermometer, title: 'Temperatura', type: TEMP, unit: '°C' },
  { key: 'rad', icon: Sun, title: 'Irradiância', type: RAD, unit: 'W/m²' },
].map(m => ({ ...m, option: multiLineOptions(stationLines(selected.value, STATION_COLORS, classified.value, m.type), { unit: m.unit, multiDay: multiDay.value }) })));

// Overlay Período A x Período B (linha cheia = A, tracejada = B), alinhado por índice.
const periodOption = computed(() => multiLineOptions(selected.value.flatMap((s, i) => [
  { name: `${s.name} · A`, color: STATION_COLORS[i], points: (classified.value[s.id] ?? []).filter(p => p.sensor_measurement_type === metricMeta.value.type) },
  { name: `${s.name} · B`, color: STATION_COLORS[i], dashed: true, points: (classifiedB.value[s.id] ?? []).filter(p => p.sensor_measurement_type === metricMeta.value.type) },
]), { unit: metricMeta.value.unit, multiDay: true }));

const summaries = computed(() => Object.fromEntries(selected.value.map(s => [s.id, summarize(classified.value[s.id] ?? [])])));
const summariesB = computed(() => compareMode.value ? Object.fromEntries(selected.value.map(s => [s.id, summarize(classifiedB.value[s.id] ?? [])])) : {});
const counts = computed(() => Object.values(classified.value).flat().reduce((acc, p) => { acc[p.quality_flag] = (acc[p.quality_flag] ?? 0) + 1; return acc; }, {} as Record<number, number>));

// ---- Auditoria manual -------------------------------------------------------
const focused = ref<ClassifiedPoint | null>(null);
function onChartClick(e: any) { const p = e?.data?.point as ClassifiedPoint | undefined; if (p && props.canAudit) focused.value = p; }

function applyOverride(p: ClassifiedPoint, flag: Quality) {
  overrides.value = { ...overrides.value, [overrideKey(p.station_id, p)]: flag };
  focused.value = null;
  if (useDemo.value) return;
  router.post(route('stations.measurements.override'), {
    measurement_id: p.measurement_id, quality_flag: flag,
  }, { preserveScroll: true, preserveState: true, only: ['overrides'] });
}
function resetOverride(p: ClassifiedPoint) {
  const { [overrideKey(p.station_id, p)]: _, ...rest } = overrides.value;
  overrides.value = rest; focused.value = null;
  if (!useDemo.value) router.delete(route('stations.measurements.override.destroy'), { data: { measurement_id: p.measurement_id }, preserveScroll: true, preserveState: true });
}

// Sincroniza filtros com a URL / recarrega dados reais do servidor.
watch([selectedIds, periodA, periodB, compareMode], () => {
  if (useDemo.value) return;
  router.get(route('stations.compare'), {
    stations: selectedIds.value, start: periodA.value.start, end: periodA.value.end,
    ...(compareMode.value ? { compare_start: periodB.value.start, compare_end: periodB.value.end } : {}),
  }, { preserveState: true, preserveScroll: true, replace: true, only: ['series', 'seriesB', 'overrides', 'selectedStationIds', 'periodA', 'periodB'] });
}, { deep: true });
</script>

<template>
  <div class="min-h-screen bg-[var(--background)] font-sans text-[var(--foreground)]">
    <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-widest text-[var(--primary)]">Análise comparativa</p>
          <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">Comparação entre estações</h1>
          <p v-if="useDemo" class="mt-1 text-xs text-amber-600">Exibindo dados de demonstração</p>
        </div>
        <button type="button" @click="panelOpen = true" class="flex items-center gap-2 rounded-xl bg-[var(--primary)] px-4 py-2.5 text-sm font-semibold text-white shadow transition hover:opacity-90">
          <SlidersHorizontal :size="16" /> Limiares de qualidade
        </button>
      </header>

      <section class="grid gap-4 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-sm lg:grid-cols-[minmax(260px,1fr)_auto]">
        <div>
          <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Estações (2 a 4)</p>
          <StationMultiSelect v-model="selectedIds" :stations="stations" :colors="STATION_COLORS" />
        </div>
        <PeriodSelector v-model:period-a="periodA" v-model:period-b="periodB" v-model:compare="compareMode" />
      </section>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StationSummaryCard v-for="(s, i) in selected" :key="s.id" :station="s" :color="STATION_COLORS[i]" :summary="summaries[s.id]" :previous="compareMode ? summariesB[s.id] : null" />
      </section>

      <section class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-sm">
        <header class="mb-3 flex flex-wrap items-center justify-between gap-3">
          <div class="flex rounded-lg bg-[var(--secondary)] p-0.5 text-xs font-semibold">
            <button v-for="m in [['temp', 'Temperatura', Thermometer], ['rad', 'Irradiância', Sun]] as const" :key="m[0]" type="button" :disabled="layout === 'split'"
              :class="['flex items-center gap-1.5 rounded-md px-3 py-1.5 disabled:opacity-40', metric === m[0] ? 'bg-[var(--card)] text-[var(--primary)] shadow' : 'text-slate-500']" @click="metric = m[0]">
              <component :is="m[2]" :size="13" /> {{ m[1] }}
            </button>
          </div>
          <div class="flex items-center gap-3">
            <div class="hidden items-center gap-3 text-[11px] text-slate-500 md:flex">
              <QualityBadge :flag="0" compact /> <QualityBadge :flag="2" compact /> <QualityBadge :flag="1" compact /> <QualityBadge :flag="3" compact />
            </div>
            <div class="flex rounded-lg bg-[var(--secondary)] p-0.5">
              <button type="button" :class="['rounded-md p-1.5', layout === 'overlay' && 'bg-[var(--card)] text-[var(--primary)] shadow']" title="Sobreposto" @click="layout = 'overlay'"><Rows3 :size="15" /></button>
              <button type="button" :class="['rounded-md p-1.5', layout === 'split' && 'bg-[var(--card)] text-[var(--primary)] shadow']" title="Gráficos sincronizados" @click="layout = 'split'"><LayoutGrid :size="15" /></button>
            </div>
          </div>
        </header>

        <VChart v-if="layout === 'overlay'" :option="overlayOption" autoresize class="h-[360px]" @click="onChartClick" />
        <div v-else class="grid gap-4 lg:grid-cols-2">
          <div v-for="c in splitOptions" :key="c.key">
            <h3 class="mb-1 flex items-center gap-1.5 text-sm font-semibold"><component :is="c.icon" :size="14" /> {{ c.title }}</h3>
            <VChart :option="c.option" group="station-split" autoresize class="h-[280px]" @click="onChartClick" />
          </div>
        </div>

        <transition enter-from-class="opacity-0 -translate-y-1" enter-active-class="transition">
          <div v-if="focused" class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-[var(--accent)] p-3 text-sm">
            <b>{{ stations.find(s => s.id === focused!.station_id)?.name }}</b>
            <span class="text-slate-500">{{ new Date(focused.timestamp).toLocaleString('pt-BR') }} · {{ focused.value ?? '—' }}</span>
            <QualityBadge :flag="focused.quality_flag" :audited="focused.audited" />
            <QualityOverrideMenu class="ml-auto" :current="focused.quality_flag" :audited="focused.audited" @change="f => applyOverride(focused!, f)" @reset="resetOverride(focused!)" />
            <button type="button" class="text-xs text-slate-500" @click="focused = null">Fechar</button>
          </div>
        </transition>
      </section>

      <section v-if="compareMode" class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-sm">
        <h2 class="mb-1 flex items-center gap-2 font-bold"><GitCompareArrows :size="16" class="text-[var(--primary)]" /> Período A × Período B — {{ metricMeta.title }}</h2>
        <p class="mb-2 text-xs text-slate-500">Linha contínua: {{ periodA.start }} → {{ periodA.end }} · Tracejada: {{ periodB.start }} → {{ periodB.end }}</p>
        <VChart :option="periodOption" autoresize class="h-[340px]" @click="onChartClick" />
      </section>

      <AuditTable :stations="selected" :colors="STATION_COLORS" :data="classified" @override="applyOverride" @reset="resetOverride" />
    </div>

    <ThresholdPanel v-model="thresholds" v-model:open="panelOpen" :counts="counts" />
  </div>
</template>
