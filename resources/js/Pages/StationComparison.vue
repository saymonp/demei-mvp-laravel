<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
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
  requiresChunking?: boolean;
  canAudit?: boolean;
  canGenerateReports?: boolean;
  selectedDashboardStationId?: number | null;
  selectedDashboardDate?: string;
  embedded?: boolean;
}>(), {
  series: () => ({}), seriesB: () => ({}), overrides: () => ({}), canAudit: true,
  requiresChunking: false, canGenerateReports: false, selectedDashboardStationId: null, selectedDashboardDate: undefined, embedded: false,
});

const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date());
const selectedIds = ref<number[]>(props.selectedStationIds?.length ? props.selectedStationIds : props.stations.slice(0, 3).map(s => s.id));
const periodA = ref<Period>(props.periodA ?? { start: today, end: today });
const periodB = ref<Period>(props.periodB ?? previousPeriod(periodA.value));
const compareMode = ref(!!props.periodB);
const thresholds = ref<Thresholds>({ ...DEFAULT_THRESHOLDS });
const panelOpen = ref(false);
const overrides = ref<OverrideMap>({ ...props.overrides });
const series = shallowRef(props.series);
const seriesB = shallowRef(props.seriesB);
const isLoadingSeries = ref(props.requiresChunking);
const seriesError = ref<string | null>(null);
const loadProgress = ref({ completed: 0, total: 0 });
const layout = ref<'overlay' | 'split'>('overlay');
const metric = ref<'temp' | 'rad'>('temp');
let activeController: AbortController | null = null;

const selected = computed(() => selectedIds.value.map(id => props.stations.find(s => s.id === id)!).filter(Boolean));
const hasSeriesData = computed(() => Object.values(series.value).some(rows => rows.length > 0));
const useDemo = computed(() => import.meta.env.DEV && !props.embedded && !isLoadingSeries.value && !hasSeriesData.value);
const raw = (id: number, b = false) => useDemo.value ? demoSeries(id, b ? periodB.value : periodA.value) : (b ? seriesB.value : series.value)[id] ?? [];

watch(() => props.selectedStationIds, ids => {
  if (ids && ids.join(',') !== selectedIds.value.join(',')) {
    selectedIds.value = [...ids];
  }
});
watch(() => props.periodA, period => {
  if (period && (period.start !== periodA.value.start || period.end !== periodA.value.end)) {
    periodA.value = { ...period };
  }
});
watch(() => props.periodB, period => {
  compareMode.value = !!period;

  if (period && (period.start !== periodB.value.start || period.end !== periodB.value.end)) {
    periodB.value = { ...period };
  }
});

// Reclassificação reativa: qualquer mudança nos sliders/overrides recalcula tudo.
const classified = computed(() => Object.fromEntries(selected.value.map(s => [s.id, reclassify(s.id, raw(s.id), thresholds.value, overrides.value)])) as Record<number, ClassifiedPoint[]>);
const classifiedB = computed(() => compareMode.value ? Object.fromEntries(selected.value.map(s => [s.id, reclassify(s.id, raw(s.id, true), thresholds.value, overrides.value)])) as Record<number, ClassifiedPoint[]> : {});

const metricMeta = computed(() => ({ temp: { type: TEMP, unit: '°C', title: 'Temperatura do ar' }, rad: { type: RAD, unit: 'W/m²', title: 'Irradiância solar' } }[metric.value]));
const multiDay = computed(() => periodA.value.start !== periodA.value.end);

const overlayOption = computed(() => multiLineOptions(stationLines(selected.value, STATION_COLORS, classified.value, metricMeta.value.type, periodA.value.start), { unit: metricMeta.value.unit, multiDay: multiDay.value }));
const splitOptions = computed(() => [
  { key: 'temp', icon: Thermometer, title: 'Temperatura', type: TEMP, unit: '°C' },
  { key: 'rad', icon: Sun, title: 'Irradiância', type: RAD, unit: 'W/m²' },
].map(m => ({ ...m, option: multiLineOptions(stationLines(selected.value, STATION_COLORS, classified.value, m.type, periodA.value.start), { unit: m.unit, multiDay: multiDay.value }) })));

// Overlay Período A x Período B (linha cheia = A, tracejada = B).
const periodOption = computed(() => multiLineOptions(selected.value.flatMap((s, i) => [
  { name: `${s.name} · A`, color: STATION_COLORS[i], points: (classified.value[s.id] ?? []).filter(p => p.sensor_measurement_type === metricMeta.value.type), origin: periodA.value.start },
  { name: `${s.name} · B`, color: STATION_COLORS[i], dashed: true, points: (classifiedB.value[s.id] ?? []).filter(p => p.sensor_measurement_type === metricMeta.value.type), origin: periodB.value.start },
]), { unit: metricMeta.value.unit, multiDay: true }));

const summaries = computed(() => Object.fromEntries(selected.value.map(s => [s.id, summarize(classified.value[s.id] ?? [])])));
const summariesB = computed(() => compareMode.value ? Object.fromEntries(selected.value.map(s => [s.id, summarize(classifiedB.value[s.id] ?? [])])) : {});
const counts = computed(() => Object.values(classified.value).flat().reduce((acc, p) => { acc[p.quality_flag] = (acc[p.quality_flag] ?? 0) + 1; return acc; }, {} as Record<number, number>));
const standaloneUrl = computed(() => {
  const query = new URLSearchParams();

  selectedIds.value.forEach(id => query.append('stations[]', String(id)));
  query.set('start', periodA.value.start);
  query.set('end', periodA.value.end);

  if (compareMode.value) {
    query.set('compare_start', periodB.value.start);
    query.set('compare_end', periodB.value.end);
  }

  return `${route('stations.compare')}?${query.toString()}`;
});

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

type SeriesChunk = { series: Record<number, Measurement[]>; overrides: OverrideMap };

function periodChunks(period: Period): Period[] {
  const start = new Date(`${period.start}T12:00:00Z`);
  const end = new Date(`${period.end}T12:00:00Z`);

  if (!Number.isFinite(start.getTime()) || !Number.isFinite(end.getTime()) || start > end) {
    throw new Error('Informe um intervalo de datas válido.');
  }

  const chunks: Period[] = [];

  while (start <= end) {
    const chunkEnd = new Date(start);
    chunkEnd.setUTCDate(chunkEnd.getUTCDate() + 6);
    if (chunkEnd > end) chunkEnd.setTime(end.getTime());

    chunks.push({
      start: start.toISOString().slice(0, 10),
      end: chunkEnd.toISOString().slice(0, 10),
    });

    start.setTime(chunkEnd.getTime());
    start.setUTCDate(start.getUTCDate() + 1);
  }

  return chunks;
}

async function fetchPeriod(period: Period, stationIds: number[], signal: AbortSignal): Promise<SeriesChunk> {
  const combined: SeriesChunk = { series: {}, overrides: {} };

  for (const chunk of periodChunks(period)) {
    const query = new URLSearchParams();
    stationIds.forEach(id => query.append('stations[]', String(id)));
    query.set('start', chunk.start);
    query.set('end', chunk.end);

    const response = await fetch(`${route('stations.compare.series')}?${query}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      signal,
    });

    if (!response.ok) {
      throw new Error(`Não foi possível carregar as medições (HTTP ${response.status}).`);
    }

    const result = await response.json() as SeriesChunk;

    Object.entries(result.series).forEach(([stationId, rows]) => {
      const id = Number(stationId);
      combined.series[id] = [...(combined.series[id] ?? []), ...rows];
    });
    Object.assign(combined.overrides, result.overrides);
    loadProgress.value.completed++;
  }

  return combined;
}

async function loadSeries() {
  activeController?.abort();
  const controller = new AbortController();
  activeController = controller;
  const periodARequest = periodA.value;
  const periodBRequest = compareMode.value ? periodB.value : null;
  const periods = [periodARequest, ...(periodBRequest ? [periodBRequest] : [])];

  try {
    const chunks = periods.flatMap(periodChunks);
    loadProgress.value = { completed: 0, total: chunks.length };
    isLoadingSeries.value = true;
    seriesError.value = null;

    const first = await fetchPeriod(periodARequest, selectedIds.value, controller.signal);
    if (controller.signal.aborted) return;

    let second: SeriesChunk = { series: {}, overrides: {} };
    if (periodBRequest) {
      second = await fetchPeriod(periodBRequest, selectedIds.value, controller.signal);
    }
    if (controller.signal.aborted) return;

    series.value = first.series;
    seriesB.value = second.series;
    overrides.value = { ...first.overrides, ...second.overrides };
  } catch (error) {
    if (!controller.signal.aborted) {
      console.error(error);
      seriesError.value = error instanceof Error ? error.message : 'Falha ao carregar as medições.';
    }
  } finally {
    if (activeController === controller) {
      isLoadingSeries.value = false;
      activeController = null;
    }
  }
}

onMounted(() => {
  if (props.requiresChunking) void loadSeries();
});

// Sincroniza filtros com a URL e carrega o intervalo em blocos para limitar o uso de memória no servidor.
watch([selectedIds, periodA, periodB, compareMode], () => {
  activeController?.abort();
  series.value = {};
  seriesB.value = {};
  overrides.value = {};
  isLoadingSeries.value = true;
  seriesError.value = null;

  router.get(route(props.embedded ? 'station-dashboard' : 'stations.compare'), {
    ...(props.embedded ? {
      station_id: props.selectedDashboardStationId,
      date: props.selectedDashboardDate,
    } : {}),
    stations: selectedIds.value,
    start: periodA.value.start,
    end: periodA.value.end,
    ...(compareMode.value ? { compare_start: periodB.value.start, compare_end: periodB.value.end } : {}),
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ['selectedStationIds', 'periodA', 'periodB', 'series', 'seriesB', 'overrides', 'requiresChunking'],
    onSuccess: () => {
      if (props.requiresChunking) {
        void loadSeries();
        return;
      }

      series.value = props.series;
      seriesB.value = props.seriesB;
      overrides.value = { ...props.overrides };
      isLoadingSeries.value = false;
    },
    onError: () => {
      isLoadingSeries.value = false;
      seriesError.value = 'Não foi possível aplicar o intervalo selecionado.';
    },
  });
}, { deep: true });
</script>

<template>
  <div :class="embedded ? 'space-y-6' : 'min-h-screen bg-[var(--background)] font-sans text-[var(--foreground)]'">
    <div :class="embedded ? 'space-y-6' : 'mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8'">
      <header v-if="!embedded" class="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-widest text-[var(--primary)]">Análise comparativa</p>
          <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">Comparação entre estações</h1>
          <p v-if="useDemo" class="mt-1 text-xs text-amber-600">Exibindo dados de demonstração</p>
        </div>
        <button type="button" @click="panelOpen = true" class="flex items-center gap-2 rounded-xl bg-[var(--primary)] px-4 py-2.5 text-sm font-semibold text-white shadow transition hover:opacity-90">
          <SlidersHorizontal :size="16" /> Limiares de qualidade
        </button>
      </header>
      <header v-else class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-widest text-[var(--primary)]">Análise comparativa</p>
          <h2 class="text-xl font-extrabold tracking-tight">Comparação entre estações</h2>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a v-if="canGenerateReports" :href="standaloneUrl" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 py-2 text-xs font-semibold text-[var(--foreground)] hover:bg-[var(--muted)]">
            Abrir tela separada
          </a>
          <button type="button" @click="panelOpen = true" class="flex items-center gap-2 rounded-lg bg-[var(--primary)] px-3 py-2 text-xs font-semibold text-white shadow transition hover:opacity-90">
            <SlidersHorizontal :size="14" /> Limiares
          </button>
        </div>
      </header>

      <section class="grid gap-4 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-sm lg:grid-cols-[minmax(260px,1fr)_auto]">
        <div>
          <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Estações (2 a 4)</p>
          <StationMultiSelect v-model="selectedIds" :stations="stations" :colors="STATION_COLORS" />
        </div>
        <PeriodSelector v-model:period-a="periodA" v-model:period-b="periodB" v-model:compare="compareMode" />
      </section>

      <p v-if="seriesError" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        {{ seriesError }}
        <button type="button" class="ml-2 font-semibold underline" @click="loadSeries">Tentar novamente</button>
      </p>
      <p v-else-if="isLoadingSeries" role="status" class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 text-sm text-slate-500">
        Carregando medições... {{ loadProgress.completed }} de {{ loadProgress.total }} blocos.
      </p>
      <p v-else-if="!useDemo && !hasSeriesData" class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 text-sm text-slate-500">
        Nenhuma das estações selecionadas tem leituras no período escolhido.
      </p>

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
