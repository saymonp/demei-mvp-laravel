import type { EChartsOption, LineSeriesOption } from 'echarts';
import { stats, timeLabel, qualityLabels, type Measurement, type Quality } from './data';

export type ChartSettings = {
  kind: 'combined' | 'radiation' | 'temperature';
  filters: Quality[];
  radiationMean: boolean;
  temperatureMean: boolean;
  radiationMax: number;
  temperatureMax: number;
  colors: Record<'ink' | 'muted' | 'surface' | 'border' | 'grid' | 'radiation' | 'temperature' | 'invalid' | 'suspect' | 'gapFill', string>;
};

export function chartOptions(rows: Measurement[], settings: ChartSettings): EChartsOption {
  const { colors: c, kind } = settings;
  const times = [...new Set(rows.map(r => r.timestamp))].sort();
  const temp = rows.filter(r => r.sensor_measurement_type === 'Air Temperature');
  const rad = rows.filter(r => r.sensor_measurement_type === 'Solar Radiation');
  
  const gaps = temp.filter(r => r.quality_flag === 3).map(r => {
    const i = times.indexOf(r.timestamp);
    const end = times[i + 1] ?? r.timestamp;
    return [{ xAxis: timeLabel(r.timestamp) }, { xAxis: timeLabel(end) }] as [{ xAxis: string }, { xAxis: string }];
  });

  let defaultStart = 0;
  if (times.length > 1) {
    const firstTime = new Date(times[0]).getTime();
    const lastTime = new Date(times[times.length - 1]).getTime();
    const totalSpan = lastTime - firstTime;
    const sixHoursMs = 6 * 60 * 60 * 1000;
    if (totalSpan > sixHoursMs) {
      defaultStart = Math.max(0, 100 - (sixHoursMs / totalSpan) * 100);
    }
  }

  const series = (data: Measurement[], name: string, color: string, axis: number, mean: boolean): LineSeriesOption => ({
    name,
    type: 'line' as const,
    yAxisIndex: axis,
    smooth: .18,
    showAllSymbol: true,
    connectNulls: false,
    symbol: 'circle',
    symbolSize: 4,
    lineStyle: { width: 1.8, color },
    itemStyle: { color },
    data: times.map(t => {
      const r = data.find(d => d.timestamp === t);
      return {
        value: r && settings.filters.includes(r.quality_flag) ? r.value : null,
        quality: r?.quality_flag,
        reason: r?.flag_reason,
        itemStyle: { color: r?.quality_flag === 1 ? c.invalid : r?.quality_flag === 2 ? c.suspect : color },
        symbol: r?.quality_flag === 1 ? 'rect' : r?.quality_flag === 2 ? 'diamond' : 'circle',
        symbolSize: r && r.quality_flag !== 0 ? 9 : 3
      };
    }),
    ...(mean && stats(data.filter(r => settings.filters.includes(r.quality_flag))).avg !== null ? {
      markLine: {
        symbol: 'none' as const,
        silent: true,
        lineStyle: { color, width: 1, type: 'dashed' as const },
        label: {
          formatter: `Média {c} ${axis === 1 || kind === 'temperature' ? '°C' : 'W/m²'}`,
          position: 'insideEndTop' as const,
          fontSize: 10,
          color: c.ink,
          backgroundColor: c.surface,
          padding: [4, 7],
          borderRadius: 10
        },
        data: [{ yAxis: Number(stats(data.filter(r => settings.filters.includes(r.quality_flag))).avg?.toFixed(1)) }]
      }
    } : {}),
    ...(kind !== 'radiation' && gaps.length > 0 ? { markArea: { silent: true, itemStyle: { color: c.gapFill }, data: gaps } } : {}),
  });

  return {
    animationDuration: 450,
    textStyle: { fontFamily: 'Manrope, sans-serif', color: c.muted },
    grid: { 
      left: kind === 'combined' ? 62 : 48, 
      right: kind === 'combined' ? 54 : 24, 
      top: 30, 
      bottom: 53 
    },
    tooltip: { 
      trigger: 'axis', 
      backgroundColor: c.surface, 
      borderColor: c.border, 
      textStyle: { color: c.ink, fontSize: 12 }, 
      confine: true, 
      formatter: (params: any) => {
        if (!Array.isArray(params) || params.length === 0) return '';
        let html = `<b>${params[0].axisValue}</b>`;
        params.forEach(param => {
          const d = param.data;
          const val = d && d.value !== null && d.value !== undefined ? `${d.value}` : 'Sem dados';
          const quality = d ? d.quality : null;
          const reason = d ? d.reason : null;
          
          let statusLabel = '';
          let statusColor = c.ink;
          if (quality !== null && quality !== undefined) {
            statusLabel = qualityLabels[quality as Quality] || '';
            if (quality === 1) statusColor = c.invalid;
            else if (quality === 2) statusColor = c.suspect;
          }

          html += `<br/>${param.marker} <b>${param.seriesName}:</b> ${val}`;
          if (quality !== null && quality !== 0) {
            html += ` <span style="color: ${statusColor}; font-weight: 600;">[${statusLabel}]</span>`;
          }
          if ((quality === 1 || quality === 2) && reason) {
            html += `<br/><span style="font-size: 11px; color: ${c.muted}; margin-left: 14px;">Motivo: <b>${reason}</b></span>`;
          }
        });
        return html;
      }
    },
    legend: { show: false },
    xAxis: { 
      type: 'category', 
      data: times.map(timeLabel), 
      boundaryGap: false, 
      axisLine: { lineStyle: { color: c.border } }, 
      axisTick: { show: false }, 
      axisLabel: { fontSize: 10, interval: 3, color: c.muted } 
    },
    yAxis: kind === 'combined' 
      ? [
          { type: 'value', name: 'W/m²', max: settings.radiationMax, min: -10, splitNumber: 5, axisLabel: { fontSize: 10 }, splitLine: { lineStyle: { color: c.grid } } }, 
          { type: 'value', name: '°C', max: settings.temperatureMax, min: 0, splitNumber: 5, axisLabel: { fontSize: 10 }, splitLine: { show: false } }
        ] 
      : [
          { type: 'value', name: kind === 'radiation' ? 'W/m²' : '°C', max: kind === 'radiation' ? settings.radiationMax : settings.temperatureMax, min: kind === 'radiation' ? -10 : 0, splitNumber: 5, axisLabel: { fontSize: 10 }, splitLine: { lineStyle: { color: c.grid } } }
        ],
    dataZoom: [
      {
        type: 'slider',
        show: true,
        xAxisIndex: [0],
        start: defaultStart,
        end: 100,
        height: 14,
        bottom: 6,
        borderColor: c.border,
        backgroundColor: c.surface,
        fillerColor: 'rgba(99, 102, 241, 0.12)',
        handleStyle: { color: c.ink }
      },
      {
        type: 'inside',
        filterMode: 'none',
        zoomOnMouseWheel: 'ctrl',
        moveOnMouseMove: true,
        start: defaultStart,
        end: 100
      }
    ],
    series: kind === 'combined' 
      ? [series(rad, 'Irradiação', c.radiation, 0, settings.radiationMean), series(temp, 'Temperatura', c.temperature, 1, settings.temperatureMean)] 
      : [kind === 'radiation' ? series(rad, 'Irradiação', c.radiation, 0, settings.radiationMean) : series(temp, 'Temperatura', c.temperature, 0, settings.temperatureMean)]
  };
}