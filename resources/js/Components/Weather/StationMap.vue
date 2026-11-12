<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onErrorCaptured, onMounted, ref, shallowRef, watch } from 'vue';
import { Check, GitCompareArrows, LocateFixed, Maximize2, RefreshCw, Sun, Thermometer, X } from '@lucide/vue';
import type * as Leaflet from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { STATION_COLORS } from '../../weather/compare';
import { formatNumber, utcTimestamp } from '../../weather/data';

type LeafletNS = typeof Leaflet;
type Station = { id: number; name: string; provider: string; latitude?: number | null; longitude?: number | null; altitude?: number | null; valid_percentage?: number | null };
type Located = Station & { lat: number; lng: number };
type StationMeta = { label: string; code: string };
type Latest = { temperature: number | null; radiation: number | null; observed_at: string | null };

const props = withDefaults(defineProps<{
  stations: Station[];
  comparisonIds?: number[];
  currentStationId?: number | null;
  latest?: Record<number, Latest> | null;
  latestUrl?: string | null;
  labels?: Record<string, Partial<StationMeta>>;
  center?: [number, number];
  zoom?: number;
  maxCompare?: number;
  colors?: string[];
  tileUrl?: string;
  fallbackTileUrl?: string | null;
  refreshMs?: number;
  heightClass?: string;
}>(), {
  comparisonIds: () => [],
  currentStationId: null,
  latest: null,
  latestUrl: null,
  labels: () => ({}),
  center: () => [-28.3906, -53.9156] as [number, number],
  zoom: 12,
  maxCompare: 4,
  colors: () => STATION_COLORS,
  tileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  fallbackTileUrl: null,
  refreshMs: 5 * 60 * 1000,
  heightClass: 'h-[420px] sm:h-[480px]',
});

const emit = defineEmits<{
  'update:comparisonIds': [ids: number[]];
  'select-station': [id: number];
  'open-comparison': [];
}>();

const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';
const BLANK_TILE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
const SLOW_TILE_MS = 6000;
const MAX_TILE_RETRIES = 3;
const STALE_AFTER_MIN = 180;
const LATEST_THROTTLE_MS = 60_000;
const NEUTRAL = '#3B5BDB';

const DEFAULT_META: Record<string, StationMeta> = {
  unijui_gaic: { label: 'Campus Unijuí', code: 'GAIC' },
  open_meteo: { label: 'Modelo regional', code: 'Open-Meteo' },
  ijui_pws_iiiju2: { label: 'Bairro Centro', code: 'IIJU2' },
  ijui_pws_iiiju3: { label: 'Floresta', code: 'IIJU3' },
};
const PROVIDERS: Record<string, string> = { licor: 'Licor', open_meteo: 'Open-Meteo', wunderground: 'Weather Underground' };

const container = ref<HTMLDivElement | null>(null);
const status = ref<'loading' | 'ready' | 'failed'>('loading');
const errorMessage = ref('');
const slowTiles = ref(false);
const offscreenCount = ref(0);
const live = ref('');
const now = ref(Date.now());
const popupEls = shallowRef<Record<number, HTMLElement>>({});
const fetched = ref<Record<number, Latest>>({});
const latestState = ref<'idle' | 'loading' | 'error'>('idle');

let L: LeafletNS | null = null;
let map: Leaflet.Map | null = null;
let tileLayer: Leaflet.TileLayer | null = null;
const markers = new Map<number, Leaflet.Marker>();
let resizeObserver: ResizeObserver | undefined;
let resizeFrame = 0;
let slowTimer: number | undefined;
let retryTimer: number | undefined;
let refreshTimer: number | undefined;
let clockTimer: number | undefined;
let abortController: AbortController | undefined;
let lastFetchAt = 0;
let tileErrors = 0;
let tileRetries = 0;
let usingFallback = false;
let unmounted = false;

const located = computed<Located[]>(() => props.stations.flatMap(station => {
  const lat = Number(station.latitude);
  const lng = Number(station.longitude);
  const valid = station.latitude != null && station.longitude != null && Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180;

  return valid ? [{ ...station, lat, lng }] : [];
}));
const selectedStations = computed(() => props.comparisonIds.map(id => props.stations.find(station => station.id === id)).filter((station): station is Station => !!station));
const hasQuality = computed(() => props.stations.some(station => station.valid_percentage != null));
const latestData = computed<Record<number, Latest>>(() => props.latest ?? fetched.value);

const humanize = (name: string) => name.replace(/[_-]+/g, ' ').replace(/\b\w/g, character => character.toUpperCase());
const metaOf = (station: Station): StationMeta => ({ ...(DEFAULT_META[station.name] ?? { label: humanize(station.name), code: `#${station.id}` }), ...(props.labels[station.name] ?? {}) });
const providerLabel = (provider: string) => PROVIDERS[provider] ?? provider;
const slotOf = (id: number) => props.comparisonIds.indexOf(id);
const safeColor = (color: string | undefined) => (color && /^#[0-9a-f]{3,8}$/i.test(color) ? color : NEUTRAL);
const slotColor = (slot: number) => safeColor(props.colors[slot % props.colors.length]);
const qualityColor = (percentage?: number | null) => percentage == null ? NEUTRAL : percentage >= 95 ? '#2F9E5B' : percentage >= 85 ? '#E0A100' : '#C92A2A';
const pinColor = (station: Station) => {
  const slot = slotOf(station.id);

  return slot >= 0 ? slotColor(slot) : qualityColor(station.valid_percentage);
};

const emptyLatest: Latest = { temperature: null, radiation: null, observed_at: null };
const latestFor = (id: number): Latest => latestData.value[id] ?? emptyLatest;
const hasReading = (id: number) => latestFor(id).temperature !== null || latestFor(id).radiation !== null;

const relativeTime = typeof Intl !== 'undefined' && 'RelativeTimeFormat' in Intl ? new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' }) : null;

function ageMinutes(iso: string | null): number | null {
  if (!iso) {
    return null;
  }

  const timestamp = Date.parse(utcTimestamp(iso));

  return Number.isFinite(timestamp) ? Math.max(0, Math.round((now.value - timestamp) / 60000)) : null;
}

function ageLabel(iso: string | null): string {
  const minutes = ageMinutes(iso);

  if (minutes === null) {
    return 'Sem leitura recente';
  }

  if (minutes < 1) {
    return 'Atualizado agora há pouco';
  }

  const unit = minutes < 60 ? ['minute', minutes] as const : minutes < 2880 ? ['hour', Math.round(minutes / 60)] as const : ['day', Math.round(minutes / 1440)] as const;

  return `Atualizado ${relativeTime ? relativeTime.format(-unit[1], unit[0]) : `há ${unit[1]} ${unit[0]}`}`;
}

const isStale = (iso: string | null) => {
  const minutes = ageMinutes(iso);

  return minutes === null || minutes > STALE_AFTER_MIN;
};

function toggleCompare(id: number): void {
  const ids = [...props.comparisonIds];
  const index = ids.indexOf(id);
  const station = props.stations.find(item => item.id === id);
  const name = station ? metaOf(station).label : `estação ${id}`;

  if (index >= 0) {
    ids.splice(index, 1);
    live.value = `${name} removida da comparação. ${ids.length} de ${props.maxCompare} selecionadas.`;
  } else if (ids.length < props.maxCompare) {
    ids.push(id);
    live.value = `${name} adicionada à comparação. ${ids.length} de ${props.maxCompare} selecionadas.`;
  } else {
    live.value = `Limite de ${props.maxCompare} estações atingido.`;

    return;
  }

  emit('update:comparisonIds', ids);
}

function compareLabel(id: number): string {
  if (slotOf(id) >= 0) {
    return 'Remover da comparação';
  }

  return props.comparisonIds.length >= props.maxCompare ? `Limite de ${props.maxCompare} estações` : 'Selecionar para Comparação';
}

function latestEndpoint(): string {
  if (props.latestUrl) {
    return props.latestUrl;
  }

  try {
    return typeof route === 'function' ? route('stations.latest') : '/stations/latest';
  } catch {
    return '/stations/latest';
  }
}

const num = (value: unknown) => (typeof value === 'number' && Number.isFinite(value) ? value : null);

async function loadLatest(force = false): Promise<void> {
  if (props.latest || unmounted) {
    return;
  }

  if (!force && Date.now() - lastFetchAt < LATEST_THROTTLE_MS) {
    return;
  }

  abortController?.abort();
  abortController = new AbortController();
  latestState.value = 'loading';

  try {
    const response = await fetch(latestEndpoint(), {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      signal: abortController.signal,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const body = await response.json();
    const raw = (body?.data ?? body ?? {}) as Record<string, Record<string, unknown>>;

    fetched.value = Object.fromEntries(Object.entries(raw).map(([id, value]) => [Number(id), {
      temperature: num(value?.temperature),
      radiation: num(value?.radiation),
      observed_at: typeof value?.observed_at === 'string' ? value.observed_at : null,
    }]));
    lastFetchAt = Date.now();
    latestState.value = 'idle';
  } catch (error) {
    if ((error as Error)?.name === 'AbortError') {
      return;
    }

    latestState.value = 'error';
  }
}

function safely(callback: () => void): void {
  try {
    callback();
  } catch (error) {
    console.error('[StationMap]', error);
  }
}

function fail(error: unknown): void {
  console.error('[StationMap]', error);
  errorMessage.value = error instanceof Error ? error.message : String(error);
  status.value = 'failed';
}

function markerOffset(station: Located): number {
  const colocated = located.value
    .filter(candidate => candidate.lat === station.lat && candidate.lng === station.lng)
    .sort((left, right) => left.id - right.id);
  const index = colocated.findIndex(candidate => candidate.id === station.id);

  // Separate pins visually while keeping their geographic coordinates unchanged.
  return (index - (colocated.length - 1) / 2) * 36;
}

function buildIcon(station: Located): Leaflet.DivIcon {
  const slot = slotOf(station.id);
  const classes = ['smk', slot >= 0 && 'smk--picked', station.id === props.currentStationId && 'smk--current'].filter(Boolean).join(' ');
  const badge = slot >= 0 ? `<b class="smk__badge">${slot + 1}</b>` : '';
  const pin = '<svg width="32" height="40" viewBox="0 0 32 40" aria-hidden="true"><path d="M16 38C16 38 4 25 4 15.5a12 12 0 1 1 24 0C28 25 16 38 16 38Z" fill="var(--smk)" stroke="#fff" stroke-width="2.5"/><circle cx="16" cy="15.5" r="4.5" fill="#fff"/></svg>';

  return L!.divIcon({
    className: 'smk-wrap',
    html: `<div class="${classes}" style="--smk:${pinColor(station)}"><i class="smk__pulse"></i>${pin}${badge}</div>`,
    iconSize: [32, 40],
    iconAnchor: [16 + markerOffset(station), 38],
    popupAnchor: [0, -34],
  });
}

function syncMarkers(): void {
  if (!L || !map) {
    return;
  }

  const ids = new Set(located.value.map(station => station.id));

  for (const [id, marker] of markers) {
    if (!ids.has(id)) {
      marker.remove();
      markers.delete(id);
      const { [id]: _removed, ...rest } = popupEls.value;
      popupEls.value = rest;
    }
  }

  for (const station of located.value) {
    const existing = markers.get(station.id);

    if (existing) {
      existing.setLatLng([station.lat, station.lng]);
      existing.setIcon(buildIcon(station));

      continue;
    }

    const content = document.createElement('div');
    const label = metaOf(station).label;
    const marker = L.marker([station.lat, station.lng], {
      icon: buildIcon(station),
      title: label,
      alt: `Estação ${label}`,
      riseOnHover: true,
      keyboard: true,
    })
      .bindPopup(content, {
        className: 'station-popup',
        minWidth: 256,
        maxWidth: 304,
        autoPanPaddingTopLeft: [24, 24],
        autoPanPaddingBottomRight: [72, 24],
      })
      .addTo(map);

    marker.on('popupopen', () => {
      void loadLatest();
    });
    markers.set(station.id, marker);
    popupEls.value = { ...popupEls.value, [station.id]: content };
  }

  updateOffscreen();
}

function updateOffscreen(): void {
  if (!map) {
    return;
  }

  const bounds = map.getBounds();

  offscreenCount.value = located.value.filter(station => !bounds.contains([station.lat, station.lng])).length;
}

function fitAll(): void {
  if (!L || !map || located.value.length === 0) {
    return;
  }

  map.fitBounds(L.latLngBounds(located.value.map(station => [station.lat, station.lng] as [number, number])), { padding: [56, 56], maxZoom: 13 });
}

function recenter(): void {
  map?.setView(props.center, props.zoom, { animate: true });
}

function scheduleInvalidate(): void {
  cancelAnimationFrame(resizeFrame);
  resizeFrame = requestAnimationFrame(() => safely(() => {
    map?.invalidateSize({ animate: false, pan: false });
    updateOffscreen();
  }));
}

function observeResize(): void {
  if (!container.value) {
    return;
  }

  if (typeof ResizeObserver === 'undefined') {
    window.addEventListener('resize', scheduleInvalidate);

    return;
  }

  resizeObserver = new ResizeObserver(scheduleInvalidate);
  resizeObserver.observe(container.value);
}

function addTiles(url: string): void {
  if (!L || !map) {
    return;
  }

  tileLayer?.remove();

  const layer = L.tileLayer(url, {
    maxZoom: 19,
    attribution: ATTRIBUTION,
    errorTileUrl: BLANK_TILE,
    updateWhenIdle: true,
    keepBuffer: 3,
  });

  layer.on('loading', () => {
    window.clearTimeout(slowTimer);
    slowTimer = window.setTimeout(() => {
      slowTiles.value = true;
    }, SLOW_TILE_MS);
  });
  layer.on('load', () => {
    window.clearTimeout(slowTimer);

    if (tileErrors === 0) {
      slowTiles.value = false;
    }
  });
  layer.on('tileload', () => {
    if (tileErrors > 0) {
      tileErrors = 0;
      tileRetries = 0;
      slowTiles.value = false;
    }
  });
  layer.on('tileerror', onTileError);
  layer.addTo(map);
  tileLayer = layer;
}

function onTileError(): void {
  tileErrors++;
  slowTiles.value = true;

  if (tileErrors < 4 || retryTimer) {
    return;
  }

  if (tileRetries < MAX_TILE_RETRIES) {
    const delay = 2000 * 2 ** tileRetries++;

    retryTimer = window.setTimeout(() => {
      retryTimer = undefined;
      tileErrors = 0;
      safely(() => tileLayer?.redraw());
    }, delay);
  } else if (props.fallbackTileUrl && !usingFallback) {
    usingFallback = true;
    tileErrors = 0;
    tileRetries = 0;
    safely(() => addTiles(props.fallbackTileUrl as string));
  }
}

async function initMap(): Promise<void> {
  const element = container.value;

  if (!element) {
    return;
  }

  status.value = 'loading';

  try {
    const module = await import('leaflet');
    const leaflet = ((module as unknown as { default?: LeafletNS }).default ?? module) as LeafletNS;

    if (unmounted || !container.value) {
      return;
    }

    L = leaflet;
    map = leaflet.map(element, {
      center: props.center,
      zoom: props.zoom,
      zoomControl: false,
      minZoom: 4,
      maxZoom: 18,
      zoomSnap: 0.5,
    });
    leaflet.control.zoom({ position: 'bottomright', zoomInTitle: 'Aproximar', zoomOutTitle: 'Afastar' }).addTo(map);
    leaflet.control.scale({ position: 'bottomleft', imperial: false, maxWidth: 110 }).addTo(map);

    addTiles(props.tileUrl);
    syncMarkers();
    observeResize();
    map.on('moveend', () => safely(updateOffscreen));

    status.value = 'ready';
    await nextTick();
    scheduleInvalidate();
  } catch (error) {
    fail(error);
  }
}

function destroyMap(): void {
  resizeObserver?.disconnect();
  resizeObserver = undefined;
  window.removeEventListener('resize', scheduleInvalidate);
  cancelAnimationFrame(resizeFrame);
  window.clearTimeout(slowTimer);
  window.clearTimeout(retryTimer);
  retryTimer = undefined;
  safely(() => map?.remove());
  map = null;
  tileLayer = null;
  markers.clear();
  popupEls.value = {};
  tileErrors = 0;
  tileRetries = 0;
  usingFallback = false;
  slowTiles.value = false;
}

async function retry(): Promise<void> {
  destroyMap();
  await nextTick();
  void initMap();
}

watch(() => [props.stations, props.comparisonIds, props.currentStationId, props.labels], () => safely(syncMarkers), { deep: true });

onErrorCaptured(error => {
  fail(error);

  return false;
});

onMounted(() => {
  unmounted = false;
  void initMap();
  void loadLatest(true);
  refreshTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') {
      void loadLatest(true);
    }
  }, props.refreshMs);
  clockTimer = window.setInterval(() => {
    now.value = Date.now();
  }, 30_000);
});

onBeforeUnmount(() => {
  unmounted = true;
  window.clearInterval(refreshTimer);
  window.clearInterval(clockTimer);
  abortController?.abort();
  destroyMap();
});

defineExpose({ fitAll, recenter, invalidateSize: scheduleInvalidate });
</script>

<template>
  <section class="station-map-root space-y-3" aria-label="Mapa das estações">
    <div :class="['relative isolate overflow-hidden rounded-2xl border border-[var(--border)] bg-[#e4eef3]', heightClass]">
      <div ref="container" class="h-full w-full" role="application" aria-label="Mapa interativo das estações meteorológicas de Ijuí" />

      <div v-if="status === 'loading'" class="absolute inset-0 z-[1100] grid place-items-center bg-[#e4eef3]/90 text-sm text-slate-500" role="status">
        <span class="flex items-center gap-2"><RefreshCw :size="16" class="animate-spin" /> Carregando o mapa…</span>
      </div>

      <div v-else-if="status === 'failed'" class="absolute inset-0 z-[1100] overflow-auto bg-[var(--card)] p-4" role="alert">
        <p class="text-sm font-bold">Não foi possível carregar o mapa.</p>
        <p class="mt-1 text-xs text-slate-500">O resto do painel segue funcionando. Você ainda pode escolher as estações aqui.</p>
        <p v-if="errorMessage" class="mt-1 truncate text-[11px] text-slate-400">{{ errorMessage }}</p>
        <button type="button" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-[var(--primary)] px-3 py-2 text-xs font-semibold text-white hover:opacity-90" @click="retry">
          <RefreshCw :size="14" /> Tentar de novo
        </button>
        <ul class="mt-4 space-y-2">
          <li v-for="station in stations" :key="station.id" class="flex items-center gap-3 rounded-xl border border-[var(--border)] p-2.5 text-sm">
            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ background: pinColor(station) }" />
            <span class="min-w-0 flex-1"><b class="block truncate">{{ metaOf(station).label }}</b><small class="text-slate-500">{{ metaOf(station).code }}</small></span>
            <button type="button" class="rounded-lg border border-[var(--border)] px-2.5 py-1.5 text-xs font-semibold hover:bg-[var(--muted)] disabled:opacity-50"
              :disabled="slotOf(station.id) < 0 && comparisonIds.length >= maxCompare" @click="toggleCompare(station.id)">
              {{ slotOf(station.id) >= 0 ? 'Remover' : 'Comparar' }}
            </button>
          </li>
        </ul>
      </div>

      <template v-if="status === 'ready'">
        <div v-if="slowTiles" class="absolute left-3 top-3 z-[1100] flex max-w-[calc(100%-5rem)] items-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)]/95 px-3 py-2 text-xs shadow" role="status">
          <RefreshCw :size="13" class="shrink-0 animate-spin text-[var(--primary)]" />
          <span>O mapa base está lento. As estações continuam clicáveis.</span>
        </div>

        <div class="absolute right-3 top-3 z-[1100] flex flex-col gap-2">
          <button type="button" class="grid h-9 w-9 place-items-center rounded-xl border border-[var(--border)] bg-[var(--card)] shadow transition hover:bg-[var(--muted)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--primary)]" title="Centralizar em Ijuí" aria-label="Centralizar em Ijuí" @click="recenter">
            <LocateFixed :size="16" />
          </button>
          <button type="button" class="relative grid h-9 w-9 place-items-center rounded-xl border border-[var(--border)] bg-[var(--card)] shadow transition hover:bg-[var(--muted)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--primary)]" title="Mostrar todas as estações" aria-label="Mostrar todas as estações" @click="fitAll">
            <Maximize2 :size="16" />
            <span v-if="offscreenCount" class="absolute -right-1.5 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-[var(--primary)] px-1 text-[10px] font-bold text-white">{{ offscreenCount }}</span>
          </button>
        </div>

        <p v-if="!located.length" class="absolute inset-x-3 bottom-12 z-[1100] rounded-xl bg-[var(--card)]/95 p-3 text-center text-xs text-slate-500 shadow">
          Nenhuma estação com coordenadas cadastradas.
        </p>
      </template>
    </div>

    <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3 text-xs">
      <div class="flex flex-wrap items-center gap-2">
        <p class="font-semibold text-slate-500">Comparação ({{ selectedStations.length }}/{{ maxCompare }})</p>
        <span v-for="(station, index) in selectedStations" :key="station.id" class="inline-flex items-center gap-1.5 rounded-full border border-[var(--border)] bg-[var(--secondary)] py-1 pl-2 pr-1 font-semibold">
          <i class="h-2.5 w-2.5 rounded-full" :style="{ background: slotColor(index) }" />
          {{ metaOf(station).label }}
          <button type="button" class="rounded-full p-0.5 hover:bg-[var(--muted)]" :aria-label="`Remover ${metaOf(station).label} da comparação`" @click="toggleCompare(station.id)"><X :size="12" /></button>
        </span>
        <p v-if="selectedStations.length < 2" class="text-slate-500">Escolha de 2 a {{ maxCompare }} estações no mapa.</p>
        <button type="button" class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-[var(--primary)] px-3 py-1.5 font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
          :disabled="selectedStations.length < 2" @click="emit('open-comparison')">
          <GitCompareArrows :size="13" /> Abrir comparação
        </button>
      </div>
      <p v-if="hasQuality" class="mt-2 text-[11px] text-slate-500">
        Cor do pino, leituras válidas:
        <b class="text-[#2F9E5B]">●</b> 95% ou mais · <b class="text-[#E0A100]">●</b> 85% a 95% · <b class="text-[#C92A2A]">●</b> abaixo de 85%. O número é a posição na comparação.
      </p>
    </div>
    <p class="sr-only" aria-live="polite">{{ live }}</p>

    <template v-for="station in located" :key="station.id">
      <Teleport v-if="popupEls[station.id]" :to="popupEls[station.id]">
        <div class="w-64 max-w-full p-4 text-[var(--foreground)]">
          <div class="flex items-start gap-3 pr-5">
            <span class="mt-1 h-3 w-3 shrink-0 rounded-full" :style="{ background: pinColor(station) }" />
            <div class="min-w-0">
              <h3 class="truncate text-sm font-extrabold leading-tight">{{ metaOf(station).label }}</h3>
              <p class="mt-0.5 text-[11px] text-slate-500">{{ providerLabel(station.provider) }} · {{ metaOf(station).code }} · ID {{ station.id }}</p>
            </div>
          </div>

          <dl class="mt-3 grid grid-cols-2 gap-2">
            <div class="rounded-xl bg-[var(--secondary)] p-2.5">
              <dt class="flex items-center gap-1 text-[11px] font-semibold text-slate-500"><Thermometer :size="12" /> Temperatura</dt>
              <dd class="mt-1 text-lg font-extrabold leading-none">
                <span v-if="latestState === 'loading' && !hasReading(station.id)" class="inline-block h-5 w-14 animate-pulse rounded bg-[var(--muted)]" />
                <template v-else>{{ formatNumber(latestFor(station.id).temperature, 1) }}<span class="ml-0.5 text-xs font-semibold text-slate-500">°C</span></template>
              </dd>
            </div>
            <div class="rounded-xl bg-[var(--secondary)] p-2.5">
              <dt class="flex items-center gap-1 text-[11px] font-semibold text-slate-500"><Sun :size="12" /> Irradiação</dt>
              <dd class="mt-1 text-lg font-extrabold leading-none">
                <span v-if="latestState === 'loading' && !hasReading(station.id)" class="inline-block h-5 w-14 animate-pulse rounded bg-[var(--muted)]" />
                <template v-else>{{ formatNumber(latestFor(station.id).radiation, 0) }}<span class="ml-0.5 text-xs font-semibold text-slate-500">W/m²</span></template>
              </dd>
            </div>
          </dl>
          <p class="mt-2 text-[11px]" :class="latestState === 'error' && !hasReading(station.id) ? 'text-[#C92A2A]' : isStale(latestFor(station.id).observed_at) ? 'text-[#B07A00]' : 'text-slate-500'">
            {{ latestState === 'error' && !hasReading(station.id) ? 'Leitura indisponível no momento.' : ageLabel(latestFor(station.id).observed_at) }}
          </p>

          <div class="mt-3 flex flex-col gap-2">
            <button type="button"
              class="flex w-full items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--primary)] disabled:cursor-not-allowed disabled:opacity-50"
              :class="slotOf(station.id) >= 0 ? 'border border-[var(--border)] bg-[var(--card)] text-[var(--foreground)] hover:bg-[var(--muted)]' : 'bg-[var(--primary)] text-white shadow hover:opacity-90'"
              :disabled="slotOf(station.id) < 0 && comparisonIds.length >= maxCompare" @click="toggleCompare(station.id)">
              <component :is="slotOf(station.id) >= 0 ? Check : GitCompareArrows" :size="16" />
              {{ compareLabel(station.id) }}
            </button>
            <button type="button" class="w-full rounded-xl px-3 py-2 text-xs font-semibold text-[var(--primary)] transition hover:bg-[var(--secondary)] disabled:cursor-default disabled:text-slate-500 disabled:hover:bg-transparent"
              :disabled="station.id === currentStationId" @click="emit('select-station', station.id)">
              {{ station.id === currentStationId ? 'Estação aberta no painel' : 'Ver no painel' }}
            </button>
          </div>
        </div>
      </Teleport>
    </template>
  </section>
</template>

<style>
.station-map-root .leaflet-container { font: inherit; background: #e4eef3; }
.station-map-root .leaflet-control-attribution { font-size: 10px; }

.smk-wrap { background: transparent; border: 0; }
.smk { position: relative; width: 32px; height: 40px; filter: drop-shadow(0 2px 3px rgb(0 0 0 / .35)); transform-origin: 50% 95%; transition: transform .15s ease; }
.smk:hover { transform: scale(1.12); }
.smk svg { display: block; }
.smk__badge { position: absolute; top: -6px; right: -8px; min-width: 18px; height: 18px; padding: 0 4px; border: 2px solid #fff; border-radius: 9999px; background: #14213d; color: #fff; font-family: inherit; font-size: 10px; font-weight: 700; line-height: 14px; text-align: center; }
.smk__pulse { position: absolute; left: 50%; bottom: 0; width: 12px; height: 12px; margin: 0 0 -6px -6px; border-radius: 9999px; background: var(--smk); opacity: 0; }
.smk--current .smk__pulse { animation: smk-pulse 1.8s ease-out infinite; }
@keyframes smk-pulse { 0% { transform: scale(.6); opacity: .55; } 100% { transform: scale(3.2); opacity: 0; } }
@media (prefers-reduced-motion: reduce) {
  .smk { transition: none; }
  .smk--current .smk__pulse { animation: none; opacity: .3; transform: scale(2); }
}

.station-popup .leaflet-popup-content-wrapper { padding: 0; overflow: hidden; border: 1px solid var(--border, #e2e8f2); border-radius: 1rem; background: var(--card, #fff); color: var(--foreground, #14213d); box-shadow: 0 12px 32px -8px rgb(15 23 42 / .35); }
.station-popup .leaflet-popup-content { width: auto !important; margin: 0; font: inherit; line-height: 1.35; }
.station-popup .leaflet-popup-tip { background: var(--card, #fff); }
.station-popup a.leaflet-popup-close-button { top: 10px; right: 10px; width: 22px; height: 22px; padding: 0; font-family: inherit; font-size: 18px; font-weight: 400; line-height: 22px; text-align: center; }
</style>
