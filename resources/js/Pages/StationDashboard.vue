<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, MarkLineComponent, MarkAreaComponent } from 'echarts/components';
import { defineComponent, h } from 'vue';
import { chartOptions, type ChartSettings } from '../weather/chart-options';
import { stats, formatNumber, timeLabel, csvContent, qualityLabels, type Measurement, type Quality } from '../weather/data';
import '../../css/weather-dashboard.css';

use([CanvasRenderer, LineChart, GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, MarkLineComponent, MarkAreaComponent]);

type IconShape = { tag: string; attrs?: Record<string, string | number> };
const makeIcon = (...shapes: IconShape[]) => defineComponent({
  props: { size: { type: [Number, String], default: 24 } },
  setup(props, { attrs }) {
    return () => h('svg', { ...attrs, width: props.size, height: props.size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, shapes.map(shape => h(shape.tag, shape.attrs)));
  },
});

const Sun = makeIcon({ tag: 'circle', attrs: { cx: 12, cy: 12, r: 4 } }, { tag: 'path', attrs: { d: 'M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42' } });
const Thermometer = makeIcon({ tag: 'path', attrs: { d: 'M14 14.76V5a2 2 0 0 0-4 0v9.76a4 4 0 1 0 4 0Z' } });
const CircleCheck = makeIcon({ tag: 'circle', attrs: { cx: 12, cy: 12, r: 10 } }, { tag: 'path', attrs: { d: 'm9 12 2 2 4-4' } });
const Cloud = makeIcon({ tag: 'path', attrs: { d: 'M20 16.2A4.5 4.5 0 0 0 18.5 7.4 6 6 0 0 0 7 8.5a4 4 0 0 0 .5 8H19' } });
const Download = makeIcon({ tag: 'path', attrs: { d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' } }, { tag: 'polyline', attrs: { points: '7 10 12 15 17 10' } }, { tag: 'line', attrs: { x1: 12, y1: 15, x2: 12, y2: 3 } });
const ChevronRight = makeIcon({ tag: 'polyline', attrs: { points: '9 18 15 12 9 6' } });
const MapPin = makeIcon({ tag: 'path', attrs: { d: 'M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z' } }, { tag: 'circle', attrs: { cx: 12, cy: 10, r: 2.5 } });
const Navigation = makeIcon({ tag: 'path', attrs: { d: 'm3 11 19-9-9 19-2-8-8-2Z' } });
const RefreshCw = makeIcon({ tag: 'path', attrs: { d: 'M20 7v5h-5M4 17v-5h5' } }, { tag: 'path', attrs: { d: 'M5.6 9A7 7 0 0 1 18 6l2 6M4 12l2 6a7 7 0 0 0 12.4-3' } });

type Station = { id: number; name: string; provider: string; latitude?: number | null; longitude?: number | null; altitude?: number | null; valid_percentage?: number | null };

const props = defineProps<{
  measurements: Measurement[];
  stations: Station[];
  selectedStationId: number | null;
  selectedDate: string;
  canGenerateReports: boolean;
}>();

type ClassificationEvent = { station_ids: number[]; date: string; batch_id: string };
type EchoChannel = { listen(event: string, callback: (payload: ClassificationEvent) => void): EchoChannel };
type EchoClient = { channel(name: string): EchoChannel; leave(name: string): void };

const CLASSIFICATION_CHECK_INTERVAL = 35 * 60 * 1000;

const stationId = ref(props.selectedStationId); 
const date = ref(props.selectedDate); 
const quality = ref<Quality | null>(null); 
const radiationMean = ref(true); 
const temperatureMean = ref(true); 
const radiationMax = ref(1500); 
const temperatureMax = ref(60); 
const view = ref('overview'); 
const highlighted = ref(''); 
const busy = ref(false);
const lastBatchId = ref<string | null>(null);
let hasLoadedVersion = false;
let isCheckingVersion = false;
let pollTimer: number | undefined;
let echo: EchoClient | undefined;

watch(() => props.selectedStationId, v => stationId.value = v); 
watch(() => props.selectedDate, v => date.value = v);

function reload() { 
  router.get('/station-dashboard', { station_id: stationId.value, date: date.value }, { preserveState: true, replace: true, onStart: () => busy.value = true, onFinish: () => busy.value = false });
}

function reloadMeasurements(): void {
  router.reload({
    only: ['measurements', 'stations'],
    onStart: () => busy.value = true,
    onFinish: () => busy.value = false,
  });
}

function handleMeasurementsClassified(event: ClassificationEvent): void {
  if (
    event.date !== date.value
    || stationId.value === null
    || !event.station_ids.includes(stationId.value)
    || event.batch_id === lastBatchId.value
  ) {
    return;
  }

  lastBatchId.value = event.batch_id;
  hasLoadedVersion = true;
  reloadMeasurements();
}

async function checkClassificationVersion(): Promise<void> {
  if (stationId.value === null || document.visibilityState === 'hidden' || isCheckingVersion) {
    return;
  }

  isCheckingVersion = true;
  const query = new URLSearchParams({
    station_id: String(stationId.value),
    date: date.value,
  });

  try {
    const response = await fetch(`/station-dashboard/version?${query}`, {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    if (!response.ok) {
      throw new Error(`Classification version request failed with status ${response.status}.`);
    }

    const result = await response.json() as { batch_id: string | null };

    if (hasLoadedVersion && result.batch_id && result.batch_id !== lastBatchId.value) {
      reloadMeasurements();
    }

    lastBatchId.value = result.batch_id;
    hasLoadedVersion = true;
  } catch (error) {
    console.error('Unable to check for newly classified measurements.', error);
  } finally {
    isCheckingVersion = false;
  }
}

onMounted(() => {
  echo = (window as Window & { Echo?: EchoClient }).Echo;

  if (echo) {
    echo.channel('weather.measurements')
      .listen('.measurements.classified', handleMeasurementsClassified);
  } else {
    void checkClassificationVersion();
    pollTimer = window.setInterval(
      () => void checkClassificationVersion(),
      CLASSIFICATION_CHECK_INTERVAL,
    );
  }
});

onBeforeUnmount(() => {
  if (pollTimer !== undefined) {
    window.clearInterval(pollTimer);
  }

  echo?.leave('weather.measurements');
});

function openHorizon() {
  window.open('/horizon/dashboard', '_blank');
}

type ReportFormat = 'pdf' | 'xlsx' | 'csv';
type StationReport = {
  id: number;
  format: ReportFormat;
  status: 'pending' | 'processing' | 'ready' | 'failed';
  reference_date: string;
  error: string | null;
  download_url: string | null;
  created_at: string | null;
};

const reportFormats: { value: ReportFormat; label: string; detail: string }[] = [
  { value: 'pdf', label: 'Relatório PDF', detail: 'KPIs, controle de qualidade, anomalias e notas' },
  { value: 'xlsx', label: 'Planilha Excel', detail: 'Dados padronizados e estatísticas em 2 abas' },
  { value: 'csv', label: 'CSV', detail: 'Dados padronizados e QC' },
];
const reportStatus = { pending: 'Na fila', processing: 'Gerando', ready: 'Pronto', failed: 'Falhou' } as const;
const documentsOpen = ref(false);
const reportFormat = ref<ReportFormat>('pdf');
const reportNotes = ref('');
const reports = ref<StationReport[]>([]);
const reportError = ref('');
const requestingReport = ref(false);
let reportTimer: number | undefined;
let reportRequestId = 0;

function closeDocuments(): void {
  documentsOpen.value = false;
  reportRequestId++;
  window.clearTimeout(reportTimer);
}

function reportHeaders(): Record<string, string> {
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=')[1];

  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
  };
}

function scheduleReportPolling(): void {
  window.clearTimeout(reportTimer);

  if (documentsOpen.value && reports.value.some(report => ['pending', 'processing'].includes(report.status))) {
    reportTimer = window.setTimeout(() => void loadReports(), 3000);
  }
}

async function loadReports(): Promise<void> {
  const requestId = ++reportRequestId;

  if (stationId.value === null) {
    reports.value = [];
    window.clearTimeout(reportTimer);

    return;
  }

  const query = new URLSearchParams({
    station_id: String(stationId.value),
    date: date.value,
  });

  try {
    const response = await fetch(`/station-dashboard/reports?${query}`, {
      headers: reportHeaders(),
      cache: 'no-store',
      credentials: 'same-origin',
    });

    if (requestId !== reportRequestId) {
      return;
    }

    if (!response.ok) {
      throw new Error(`Request failed with status ${response.status}.`);
    }

    reports.value = await response.json() as StationReport[];
    reportError.value = '';
  } catch {
    if (requestId === reportRequestId) {
      reportError.value = 'Não foi possível carregar os documentos. Tente novamente.';
    }
  } finally {
    if (requestId === reportRequestId) {
      scheduleReportPolling();
    }
  }
}

async function generateReport(): Promise<void> {
  if (stationId.value === null || requestingReport.value) {
    return;
  }

  requestingReport.value = true;
  reportRequestId++;
  window.clearTimeout(reportTimer);
  reportError.value = '';

  try {
    const response = await fetch('/station-dashboard/reports', {
      method: 'POST',
      headers: reportHeaders(),
      credentials: 'same-origin',
      body: JSON.stringify({
        station_id: stationId.value,
        date: date.value,
        format: reportFormat.value,
        notes: reportNotes.value || null,
      }),
    });

    if (!response.ok) {
      const result = await response.json().catch(() => null) as {
        message?: string;
        errors?: Record<string, string[]>;
      } | null;
      const validationMessage = Object.values(result?.errors ?? {}).flat()[0];
      throw new Error(validationMessage ?? result?.message ?? `Request failed with status ${response.status}.`);
    }

    reports.value = [await response.json() as StationReport, ...reports.value];
    reportNotes.value = '';
    scheduleReportPolling();
  } catch (error: unknown) {
    reportError.value = error instanceof Error
      ? error.message
      : 'Não foi possível solicitar o documento. Verifique os dados e tente novamente.';
  } finally {
    requestingReport.value = false;
  }
}

function openDocuments(): void {
  documentsOpen.value = true;
  void loadReports();
}

watch([stationId, date], () => {
  if (documentsOpen.value) {
    void loadReports();
  }
});

onBeforeUnmount(() => window.clearTimeout(reportTimer));

const station = computed(() => props.stations.find(s => s.id === stationId.value));
const rad = computed(() => props.measurements.filter(r => r.sensor_measurement_type === 'Solar Radiation'));
const temp = computed(() => props.measurements.filter(r => r.sensor_measurement_type === 'Air Temperature'));
const rs = computed(() => stats(rad.value)); 
const ts = computed(() => stats(temp.value));
const counts = computed(() => [0, 1, 2, 3].map(q => props.measurements.filter(r => r.quality_flag === q).length));
const reliable = computed(() => props.measurements.length ? counts.value[0] / props.measurements.length * 100 : null);

const colors = computed(() => { 
  if (typeof document === 'undefined') return null; 
  const css = getComputedStyle(document.documentElement); 
  const get = (k: string) => css.getPropertyValue(`--chart-${k}`).trim(); 
  return { ink: get('ink'), muted: get('muted'), surface: get('surface'), border: get('border'), grid: get('grid'), radiation: get('radiation'), temperature: get('temperature'), invalid: get('invalid'), suspect: get('suspect'), gapFill: get('gapFill') }; 
});

const settings = computed(() => ({ filters: quality.value === null ? [0, 1, 2, 3] as Quality[] : [quality.value], radiationMean: radiationMean.value, temperatureMean: temperatureMean.value, radiationMax: radiationMax.value, temperatureMax: temperatureMax.value }));
const option = (kind: ChartSettings['kind']) => colors.value ? chartOptions(props.measurements, { ...settings.value, kind, colors: colors.value }) : {};
const combinedOption = computed(() => option('combined')); 
const radiationOption = computed(() => option('radiation')); 
const temperatureOption = computed(() => option('temperature'));

const qclass = ['valid', 'invalid', 'suspect', 'gap'];
const filters = computed(() => [{ label: 'Todos', flag: null, count: props.measurements.length }, { label: 'Válidos', flag: 0, count: counts.value[0] }, { label: 'Suspeitos', flag: 2, count: counts.value[2] }, { label: 'Inválidos', flag: 1, count: counts.value[1] }, { label: 'Lacunas', flag: 3, count: counts.value[3] }]);
const alerts = computed(() => props.measurements.filter(r => r.quality_flag !== 0));
const paired = computed(() => [...new Set(props.measurements.map(r => r.timestamp))].sort().map(timestamp => ({ timestamp, rad: rad.value.find(r => r.timestamp === timestamp), temp: temp.value.find(r => r.timestamp === timestamp) })).filter(r => quality.value === null || r.rad?.quality_flag === quality.value || r.temp?.quality_flag === quality.value));
const displayed = computed(() => view.value === 'logs' ? paired.value : paired.value.filter(r => r.rad?.quality_flag !== 0 || r.temp?.quality_flag !== 0).slice(0, 12));

function peakTime(sensor: Measurement[], max: number | null) { 
  const row = sensor.find(r => r.quality_flag === 0 && r.value === max); 
  return row ? `às ${timeLabel(row.timestamp)}, leitura válida` : 'Sem leituras válidas'; 
}

function review(timestamp: string) { 
  highlighted.value = timestamp; 
  view.value = 'logs'; 
  quality.value = null; 
  setTimeout(() => document.getElementById('measurements')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 0); 
}

function exportData() { 
  const a = document.createElement('a'); 
  a.href = URL.createObjectURL(new Blob([csvContent(props.measurements.filter(r => quality.value === null || r.quality_flag === quality.value))], { type: 'text/csv;charset=utf-8' })); 
  a.download = `${station.value?.id ?? 'station'}-${date.value}.csv`; 
  a.click(); 
  URL.revokeObjectURL(a.href); 
}

const mapStations = computed(() => { 
  const located = props.stations.filter(s => s.latitude != null && s.longitude != null); 
  const xs = located.map(s => Number(s.longitude)); 
  const ys = located.map(s => Number(s.latitude)); 
  const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys); 
  return located.map(s => ({ ...s, x: 10 + (Number(s.longitude) - minX) / (maxX - minX || 1) * 65, y: 20 + (maxY - Number(s.latitude)) / (maxY - minY || 1) * 55 })); 
});
</script>

<template>
  <main class="dashboard bg-slate-200" :aria-busy="busy">
    <header class="topbar">
      <div class="brand">
        <span class="brand-icon">
          <Sun :size="26" />
        </span>
        Estação meteorológica
      </div>
      
      <!-- Navegação com abas e link para o Horizon -->
      <nav class="nav-tabs">
        <button :class="{ active: view === 'overview' }" @click="view = 'overview'">Visão geral</button>
        <button :class="{ active: view === 'logs' }" @click="view = 'logs'">Logs</button>
        <button @click="openHorizon">Horizon</button>
      </nav>

      <div class="top-actions">
        <select class="station-select" v-model="stationId" @change="reload" :disabled="busy" aria-label="Selecionar estação">
          <option v-for="s in stations" :key="s.id" :value="s.id">{{ s.provider }} {{ s.name }}</option>
        </select>
        <input type="date" class="date-select" v-model="date" @change="reload" :disabled="busy" aria-label="Data das medições" />
        <button class="export-button" @click="exportData">
          <Download :size="14" />Exportar
        </button>
        <button v-if="canGenerateReports" class="export-button" @click="openDocuments" :disabled="stationId === null">
          <Download :size="14" />Documentos
        </button>
      </div>
    </header>

    <section class="">
      <h1>{{ station?.name ?? 'Nenhuma estação cadastrada' }}, Rio Grande do Sul</h1>
      <p class="summary-location">
        <template v-if="station?.latitude != null && station?.longitude != null">{{ formatNumber(station.latitude, 2) }}°, {{ formatNumber(station.longitude, 2) }}°</template>
        <template v-if="station?.altitude != null"> · {{ station.altitude }} m acima do nível do mar</template>
      </p>
      <div class="flex p-4 mx-auto max-w-4xl gap-6 justify-center">
        <div class="metric">
          <span class="metric-icon solar">
            <Sun />
          </span>
          <div>
            <p class="metric-label">Pico de irradiação</p>
            <p class="metric-value">{{ formatNumber(rs.max, 0) }}<span>W/m²</span></p>
            <small>{{ peakTime(rad, rs.max) }}</small>
          </div>
        </div>
        <div class="metric">
          <span class="metric-icon temp">
            <Thermometer />
          </span>
          <div>
            <p class="metric-label">Temperatura máxima</p>
            <p class="metric-value">{{ formatNumber(ts.max) }}<span>°C</span></p>
            <small>{{ peakTime(temp, ts.max) }}</small>
          </div>
        </div>
        <div class="metric">
          <span class="metric-icon reliability">
            <CircleCheck :size="35" />
          </span>
          <div>
            <p class="metric-label">Leituras confiáveis</p>
            <p class="metric-value">{{ formatNumber(reliable) }}<span>%</span></p>
            <small>{{ counts[0] }} de {{ measurements.length }} leituras</small>
          </div>
        </div>
      </div>
    </section>

    <section class="filterbar">
      <div class="quality-filters">
        <button v-for="f in filters" :key="f.label" :class="{ selected: quality === f.flag }" :aria-pressed="quality === f.flag" @click="quality = f.flag as Quality | null">
          <i v-if="f.flag !== null" :class="['dot', qclass[f.flag]]" />{{ f.label }} {{ f.count }}
        </button>
      </div>
      <div class="range-control">
        <label>Irradiação <span>0 a {{ radiationMax }}</span></label>
        <input type="range" min="1000" max="2000" step="100" v-model.number="radiationMax" aria-label="Escala de irradiação" />
      </div>
      <div class="range-control temp">
        <label>Temperatura <span>0 a {{ temperatureMax }}</span></label>
        <input type="range" min="30" max="80" step="5" v-model.number="temperatureMax" aria-label="Escala de temperatura" />
      </div>
      <div class="mean-toggles">
        <label class="mean-toggle"><input type="checkbox" v-model="radiationMean" />Média irradiação</label>
        <label class="mean-toggle temp"><input type="checkbox" v-model="temperatureMean" />Média temperatura</label>
      </div>
    </section>

    <div class="context-bar">
      <span>{{ date.split('-').reverse().join('/') }} · GMT−3 · {{ measurements.length }} leituras</span>
      <div class="quality-legend">
        <span v-for="(label, f) in qualityLabels" :key="label"><i :class="['dot', qclass[f]]" />{{ label }}</span>
      </div>
    </div>

    <template v-if="view === 'overview'">
      <div class="chart-layout">
        <section class="panel chart-panel">
          <div class="panel-heading">
            <div>
              <h2>Irradiação e temperatura juntas</h2>
              <p>{{ station?.name }} · GMT−3</p>
            </div>
            <div class="series-legend"><span><i class="series-mark" />Irradiação</span><span><i class="series-mark temp" />Temperatura</span></div>
          </div>
          <VChart class="weather-chart" :option="combinedOption" :update-options="{ notMerge: false }" autoresize />
        </section>
        <aside class="panel attention">
          <h2>Precisa de atenção</h2>
          <small>{{ alerts.length }} leituras para revisar no período</small>
          <div class="attention-list">
            <button v-for="a in alerts.slice(0, 4)" :key="a.timestamp + a.sensor_measurement_type" class="attention-item" @click="review(a.timestamp)">
              <span :class="['attention-icon', qclass[a.quality_flag]]"><i :class="['dot', qclass[a.quality_flag]]" /></span>
              <div>
                <strong>{{ timeLabel(a.timestamp) }}, {{ a.sensor_measurement_type === 'Solar Radiation' ? 'irradiação' : 'temperatura' }}</strong>
                <p>{{ a.flag_reason ?? 'Qualidade sinalizada' }}</p>
              </div>
              <span :class="['badge', qclass[a.quality_flag]]">{{ qualityLabels[a.quality_flag] }}</span>
              <ChevronRight :size="13" />
            </button>
            <p v-if="!alerts.length" class="empty">Nenhuma leitura sinalizada.</p>
          </div>
        </aside>
      </div>

      <div class="two-charts">
        <section class="panel chart-panel">
          <div class="panel-heading">
            <div>
              <h2>Irradiação solar</h2>
              <p>W/m², leituras da estação</p>
            </div>
            <div class="series-legend"><span><i class="series-mark" />Irradiação</span></div>
          </div>
          <VChart class="weather-chart" :option="radiationOption" :update-options="{ notMerge: false }" autoresize />
        </section>
        <section class="panel chart-panel">
          <div class="panel-heading">
            <div>
              <h2>Temperatura do ar</h2>
              <p>°C, leituras da estação</p>
            </div>
            <div class="series-legend"><span><i class="series-mark temp" />Temperatura</span></div>
          </div>
          <VChart class="weather-chart" :option="temperatureOption" :update-options="{ notMerge: false }" autoresize />
        </section>
      </div>

      <div class="stations-layout">
        <section class="panel map-panel">
          <div class="section-title">
            <h2>Onde ficam as estações</h2>
            <p>{{ mapStations.length }} estações com coordenadas</p>
          </div>
          <div class="station-map">
            <span class="map-caption">Mapa esquemático</span>
            <span class="map-north"><Navigation :size="18" />N</span>
            <button v-for="s in mapStations" :key="s.id" :class="['map-pin', { current: s.id === stationId }]" :style="{ left: `${s.x}%`, top: `${s.y}%` }" @click="stationId = s.id; reload()">
              <MapPin /><label><strong>{{ s.name }}</strong><small>{{ s.provider }}</small></label>
            </button>
            <p v-if="!mapStations.length" class="empty">Coordenadas não cadastradas.</p>
          </div>
        </section>
        <section class="panel stations-panel">
          <div class="section-title">
            <h2>Estações</h2>
            <p>Escolha uma para ver os dados dela</p>
          </div>
          <div class="stations-list">
            <button v-for="s in stations" :key="s.id" :class="['station-row', { active: s.id === stationId }]" @click="stationId = s.id; reload()">
              <i class="dot valid" />
              <div><strong>{{ s.name }}</strong><small>{{ s.provider }}</small></div>
              <div class="station-rating">
                <template v-if="s.valid_percentage != null"><strong>{{ formatNumber(s.valid_percentage) }}%</strong><small>leituras válidas</small></template>
              </div>
            </button>
          </div>
        </section>
      </div>
    </template>

    <section id="measurements" class="panel table-panel">
      <div class="panel-heading">
        <div class="section-title">
          <h2>{{ view === 'logs' ? 'Logs de medições' : 'Medições padronizadas' }}</h2>
          <p>Timestamps sincronizados, com a flag de cada leitura</p>
        </div>
        <button aria-label="Limpar filtros" @click="quality = null; highlighted = ''" class="reset-button">
          <RefreshCw :size="15" />
        </button>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Horário</th>
              <th>Irradiação (W/m²)</th>
              <th>Flag</th>
              <th>Temperatura (°C)</th>
              <th>Flag</th>
              <th>Observação</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in displayed" :key="r.timestamp" :class="{ highlight: highlighted === r.timestamp }">
              <td><strong>{{ timeLabel(r.timestamp) }}</strong></td>
              <td class="number">{{ formatNumber(r.rad?.value ?? null) }}</td>
              <td><span :class="['badge', qclass[r.rad?.quality_flag ?? 3]]">{{ qualityLabels[r.rad?.quality_flag ?? 3] }}</span></td>
              <td class="number">{{ formatNumber(r.temp?.value ?? null) }}</td>
              <td><span :class="['badge', qclass[r.temp?.quality_flag ?? 3]]">{{ qualityLabels[r.temp?.quality_flag ?? 3] }}</span></td>
              <td>{{ r.rad?.flag_reason || r.temp?.flag_reason || 'Leituras coerentes' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="!displayed.length" class="empty">Nenhuma leitura para este filtro.</p>
      </div>
      <div class="table-footer">
        <span>{{ displayed.length }} horários</span>
        <button @click="view = view === 'logs' ? 'overview' : 'logs'">{{ view === 'logs' ? 'Voltar ao resumo' : 'Ver todas as medições' }} →</button>
      </div>
    </section>

    <div v-if="documentsOpen" class="documents-backdrop" @click.self="closeDocuments">
      <section class="panel documents-dialog" role="dialog" aria-modal="true" aria-labelledby="documents-title">
        <div class="panel-heading">
          <div>
            <h2 id="documents-title">Gerar documentos</h2>
            <p>{{ station?.name }} · {{ date.split('-').reverse().join('/') }}</p>
          </div>
          <button class="documents-close" aria-label="Fechar" @click="closeDocuments">×</button>
        </div>
        <div class="documents-formats">
          <label v-for="format in reportFormats" :key="format.value" :class="['documents-format', { selected: reportFormat === format.value }]">
            <input type="radio" name="report-format" :value="format.value" v-model="reportFormat" />
            <strong>{{ format.label }}</strong><small>{{ format.detail }}</small>
          </label>
        </div>
        <label v-if="reportFormat === 'pdf'" class="documents-notes">Observações do operador (opcional)
          <textarea v-model="reportNotes" rows="3" maxlength="2000" placeholder="Ex.: sensor de radiação limpo às 10:00"></textarea>
        </label>
        <p v-if="reportError" class="documents-error" role="alert">{{ reportError }}</p>
        <button class="export-button" :disabled="requestingReport" @click="generateReport">
          {{ requestingReport ? 'Enviando…' : 'Gerar documento' }}
        </button>
        <h3 class="documents-subtitle">Documentos desta estação e data</h3>
        <p v-if="!reports.length" class="empty">Nenhum documento gerado ainda.</p>
        <ul class="documents-list">
          <li v-for="report in reports" :key="report.id">
            <span>{{ reportFormats.find(format => format.value === report.format)?.label }}</span>
            <span :class="['badge', report.status === 'ready' ? 'valid' : report.status === 'failed' ? 'invalid' : 'suspect']">{{ reportStatus[report.status] }}</span>
            <a v-if="report.download_url" :href="report.download_url">Baixar</a>
          </li>
        </ul>
      </section>
    </div>
  </main>
</template>