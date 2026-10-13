<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { LineChart } from 'echarts/charts';
import { 
  GridComponent, 
  TooltipComponent, 
  LegendComponent, 
  TitleComponent, 
  DataZoomComponent 
} from 'echarts/components';

use([
  CanvasRenderer,
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent
]);

const props = defineProps({
  measurements: {
    type: Array,
    default: () => []
  },
  stations: {
    type: Array,
    default: () => []
  },
  selectedStationId: {
    type: Number,
    default: 1
  }
});

// Estado reativo para a estação selecionada
const currentStationId = ref(props.selectedStationId);

// Função para alternar de estação recarregando via Inertia
const changeStation = () => {
  router.get('/station-dashboard', { station_id: currentStationId.value }, {
    preserveState: true,
    replace: true,
  });
};

// Função para converter UTC para o fuso horário local do cliente (ex: America/Sao_Paulo)
const formatLocalDate = (timestampStr) => {
  if (!timestampStr) return '';
  // Garante leitura correta do UTC adicionando 'Z' se não houver
  const isoStr = timestampStr.endsWith('Z') ? timestampStr : timestampStr + 'Z';
  const date = new Date(isoStr);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
};

// Filtros de qualidade interativos (checkboxes)
// 0: Válido, 1: Inválido, 2: Suspeito, 3: Lacuna (Missing)
const qualityFilters = ref({
  0: true,
  2: true,
  1: true,
  3: true,
});

// Legenda amigável para as flags
const flagLabels = {
  0: { label: 'Válido' },
  2: { label: 'Suspeito' },
  1: { label: 'Inválido' },
  3: { label: 'Lacuna (Gap)' },
};

// Filtragem baseada nas checkboxes selecionadas
const filteredData = computed(() => {
  return props.measurements.filter(m => qualityFilters.value[m.quality_flag]);
});

// --- ESTATÍSTICAS E DADOS PARA TEMPERATURA ---
const temperatureData = computed(() => {
  return filteredData.value.filter(m => m.sensor_measurement_type === 'Air Temperature');
});

const tempStats = computed(() => {
  const validValues = temperatureData.value.map(m => m.value).filter(v => v !== null);
  if (validValues.length === 0) return { min: 0, max: 0, avg: 0 };
  return {
    min: Math.min(...validValues).toFixed(2),
    max: Math.max(...validValues).toFixed(2),
    avg: (validValues.reduce((a, b) => a + b, 0) / validValues.length).toFixed(2)
  };
});

const temperatureChartOption = computed(() => ({
  title: { text: 'Temperatura do Ar', left: 'center', textStyle: { fontSize: 14 } },
  tooltip: {
    trigger: 'axis',
    formatter: (params) => {
      const data = params[0].data;
      return `<b>Horário Local:</b> ${params[0].name}<br/>
              <b>Valor:</b> ${data.value !== null ? data.value + ' °C' : 'Sem Dados'}<br/>
              <b>Status:</b> ${flagLabels[data.flag]?.label || 'Desconhecido'}<br/>
              ${data.reason ? `<b>Motivo:</b> ${data.reason}` : ''}`;
    }
  },
  grid: { left: '10%', right: '5%', bottom: '15%' },
  xAxis: { type: 'category', data: temperatureData.value.map(m => formatLocalDate(m.timestamp)) },
  yAxis: { type: 'value', name: '°C' },
  series: [{
    data: temperatureData.value.map(m => ({
      value: m.value,
      flag: m.quality_flag,
      reason: m.flag_reason
    })),
    type: 'line',
    smooth: true,
    itemStyle: { color: '#f97316' },
    connectNulls: false
  }]
}));

// --- ESTATÍSTICAS E DADOS PARA RADIAÇÃO SOLAR ---
const radiationData = computed(() => {
  return filteredData.value.filter(m => m.sensor_measurement_type === 'Solar Radiation');
});

const radStats = computed(() => {
  const validValues = radiationData.value.map(m => m.value).filter(v => v !== null);
  if (validValues.length === 0) return { min: 0, max: 0, avg: 0 };
  return {
    min: Math.min(...validValues).toFixed(2),
    max: Math.max(...validValues).toFixed(2),
    avg: (validValues.reduce((a, b) => a + b, 0) / validValues.length).toFixed(2)
  };
});

const radiationChartOption = computed(() => ({
  title: { text: 'Radiação Solar', left: 'center', textStyle: { fontSize: 14 } },
  tooltip: {
    trigger: 'axis',
    formatter: (params) => {
      const data = params[0].data;
      return `<b>Horário Local:</b> ${params[0].name}<br/>
              <b>Valor:</b> ${data.value !== null ? data.value + ' W/m²' : 'Sem Dados'}<br/>
              <b>Status:</b> ${flagLabels[data.flag]?.label || 'Desconhecido'}<br/>
              ${data.reason ? `<b>Motivo:</b> ${data.reason}` : ''}`;
    }
  },
  grid: { left: '10%', right: '5%', bottom: '15%' },
  xAxis: { type: 'category', data: radiationData.value.map(m => formatLocalDate(m.timestamp)) },
  yAxis: { type: 'value', name: 'W/m²' },
  series: [{
    data: radiationData.value.map(m => ({
      value: m.value,
      flag: m.quality_flag,
      reason: m.flag_reason
    })),
    type: 'line',
    smooth: true,
    itemStyle: { color: '#eab308' },
    areaStyle: { opacity: 0.1 },
    connectNulls: false
  }]
}));

// --- GRÁFICO COMBINADO (Eixo Duplo) ---
const combinedChartOption = computed(() => {
  const timestamps = [...new Set(filteredData.value.map(m => formatLocalDate(m.timestamp)))];
  
  const tempData = timestamps.map(time => {
    const found = temperatureData.value.find(m => formatLocalDate(m.timestamp) === time);
    return found ? found.value : null;
  });

  const radData = timestamps.map(time => {
    const found = radiationData.value.find(m => formatLocalDate(m.timestamp) === time);
    return found ? found.value : null;
  });

  return {
    title: { text: 'Temperatura & Radiação Solar (Combinado)', left: 'center', textStyle: { fontSize: 14 } },
    tooltip: { trigger: 'axis' },
    legend: { data: ['Temperatura (°C)', 'Radiação (W/m²)'], bottom: 0 },
    grid: { left: '8%', right: '8%', bottom: '15%' },
    xAxis: { type: 'category', data: timestamps },
    yAxis: [
      { type: 'value', name: 'Temp (°C)', position: 'left' },
      { type: 'value', name: 'Rad (W/m²)', position: 'right', splitLine: { show: false } }
    ],
    series: [
      { name: 'Temperatura (°C)', type: 'line', data: tempData, itemStyle: { color: '#f97316' }, smooth: true },
      { name: 'Radiação (W/m²)', type: 'line', yAxisIndex: 1, data: radData, itemStyle: { color: '#eab308' }, smooth: true }
    ]
  };
});
</script>

<template>
  <div class="p-6 max-w-7xl mx-auto space-y-6">
    
    <!-- Cabeçalho e Seletor de Estações -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
      <div>
        <h1 class="text-2xl font-bold text-slate-800">Dashboard Meteorológica</h1>
        <p class="text-sm text-slate-500">Horários convertidos automaticamente para o seu fuso local.</p>
      </div>
      
      <div class="flex items-center space-x-3">
        <label for="station-select" class="text-sm font-medium text-slate-700">Estação:</label>
        <select 
          id="station-select" 
          v-model="currentStationId" 
          @change="changeStation"
          class="border-slate-300 rounded-xl text-sm focus:ring-indigo-500 focus:border-indigo-500 shadow-sm px-4 py-2"
        >
          <option v-for="station in stations" :key="station.id" :value="station.id">
            {{ station.name }} ({{ station.provider }})
          </option>
        </select>
      </div>
    </div>

    <!-- Barra de Filtros de Qualidade (Checkboxes Estilizadas) -->
    <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-100 flex flex-wrap items-center gap-4">
      <span class="text-sm font-medium text-slate-600">Filtrar por Qualidade:</span>
      <label v-for="(cfg, flag) in flagLabels" :key="flag" class="cursor-pointer inline-flex items-center space-x-2 px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 transition">
        <input type="checkbox" v-model="qualityFilters[flag]" class="rounded text-indigo-600 focus:ring-indigo-500 w-4 h-4">
        <span class="text-sm text-slate-700 font-medium">{{ cfg.label }}</span>
      </label>
    </div>

    <!-- Grid de Gráficos Individuais e Estatísticas -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      
      <!-- Gráfico Temperatura -->
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-100 flex flex-col">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-semibold text-slate-700">Temperatura do Ar</h2>
          <div class="flex space-x-3 text-xs font-medium text-slate-500 bg-slate-50 px-3 py-1.5 rounded-lg">
            <span>Mín: <strong class="text-slate-700">{{ tempStats.min }}°C</strong></span>
            <span>Máx: <strong class="text-slate-700">{{ tempStats.max }}°C</strong></span>
            <span>Média: <strong class="text-slate-700">{{ tempStats.avg }}°C</strong></span>
          </div>
        </div>
        <div class="h-80 w-full">
          <v-chart class="chart" :option="temperatureChartOption" autoresize />
        </div>
      </div>

      <!-- Gráfico Radiação -->
      <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-100 flex flex-col">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-semibold text-slate-700">Radiação Solar</h2>
          <div class="flex space-x-3 text-xs font-medium text-slate-500 bg-slate-50 px-3 py-1.5 rounded-lg">
            <span>Mín: <strong class="text-slate-700">{{ radStats.min }} W/m²</strong></span>
            <span>Máx: <strong class="text-slate-700">{{ radStats.max }} W/m²</strong></span>
            <span>Média: <strong class="text-slate-700">{{ radStats.avg }} W/m²</strong></span>
          </div>
        </div>
        <div class="h-80 w-full">
          <v-chart class="chart" :option="radiationChartOption" autoresize />
        </div>
      </div>

    </div>

    <!-- Gráfico Combinado -->
    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
      <h2 class="text-lg font-semibold text-slate-700 mb-4">Comparativo Consolidado</h2>
      <div class="h-96 w-full">
        <v-chart class="chart" :option="combinedChartOption" autoresize />
      </div>
    </div>

  </div>
</template>