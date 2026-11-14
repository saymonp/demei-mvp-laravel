import type { EChartsOption, LineSeriesOption } from 'echarts';
import { qualityLabels, timeLabel, type Quality } from './data';
import type { ClassifiedPoint, StationLite } from './compare';

export const QUALITY_COLORS: Record<Quality, string> = { 0: '#16a34a', 1: '#dc2626', 2: '#f59e0b', 3: '#9ca3af' };
const SYMBOL: Record<Quality, string> = { 0: 'circle', 1: 'rect', 2: 'diamond', 3: 'circle' };

type Line = { name: string; color: string; dashed?: boolean; points: ClassifiedPoint[]; origin: string };
const SEVERITY: Record<Quality, number> = { 1: 3, 2: 2, 0: 1, 3: 0 };
const HTML_ESCAPE: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const escapeHtml = (value: string) => value.replace(/[&<>"']/g, character => HTML_ESCAPE[character]);

/** Rótulo do eixo X: hora para 1 dia, "dd/mm hh:mm" para períodos maiores. */
const label = (ts: string, multiDay: boolean) => multiDay
  ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }).format(new Date(ts))
  : timeLabel(ts);

/**
 * Alinha as linhas por baldes de tempo relativos ao início de cada período.
 */
export function multiLineOptions(lines: Line[], opts: { unit: string; multiDay: boolean; group?: string }): EChartsOption {
  const bucket = (opts.multiDay ? 60 : 30) * 60_000;
  const originMs = (origin: string) => Date.parse(`${origin}T00:00:00-03:00`);
  const keyOf = (line: Line, point: ClassifiedPoint) =>
    Math.floor((Date.parse(point.timestamp) - originMs(line.origin)) / bucket);
  const picked = lines.map(line => {
    const pointsByBucket = new Map<number, ClassifiedPoint>();

    for (const point of line.points) {
      const key = keyOf(line, point);
      const current = pointsByBucket.get(key);

      if (
        !current
        || SEVERITY[point.quality_flag] > SEVERITY[current.quality_flag]
        || (
          SEVERITY[point.quality_flag] === SEVERITY[current.quality_flag]
          && point.timestamp > current.timestamp
        )
      ) {
        pointsByBucket.set(key, point);
      }
    }

    return pointsByBucket;
  });
  const keys = [...new Set(picked.flatMap(pointsByBucket => [...pointsByBucket.keys()]))]
    .sort((a, b) => a - b);
  const axisBase = lines[0] ? originMs(lines[0].origin) : 0;
  const series: LineSeriesOption[] = lines.map((l, i) => ({
    name: l.name,
    type: 'line',
    smooth: .2,
    showAllSymbol: true,
    connectNulls: false,
    lineStyle: { width: 2, color: l.color, type: l.dashed ? 'dashed' : 'solid', opacity: l.dashed ? .75 : 1 },
    itemStyle: { color: l.color },
    data: keys.map(key => {
      const p = picked[i].get(key);

      if (!p) {
        return { value: null };
      }

      return {
        value: p.quality_flag === 1 || p.quality_flag === 3 ? null : p.value,
        point: p,
        symbol: SYMBOL[p.quality_flag],
        symbolSize: p.quality_flag === 0 ? (p.audited ? 7 : 3) : 9,
        itemStyle: { color: p.quality_flag === 0 ? l.color : QUALITY_COLORS[p.quality_flag], borderColor: p.audited ? '#6366f1' : undefined, borderWidth: p.audited ? 2 : 0 },
      };
    }),
  }));
  return {
    animationDuration: 350,
    textStyle: { fontFamily: 'Figtree, ui-sans-serif, system-ui, sans-serif' },
    grid: { left: 48, right: 20, top: 40, bottom: 50 },
    legend: { top: 0, icon: 'roundRect', itemWidth: 14, itemHeight: 4, textStyle: { fontSize: 11 } },
    tooltip: {
      trigger: 'axis', confine: true, enterable: true,
      formatter: (params: any) => {
        const arr = Array.isArray(params) ? params : [params];
        let html = `<b>${escapeHtml(String(arr[0]?.axisValue ?? ''))}</b>`;
        for (const prm of arr) {
          const p: ClassifiedPoint | undefined = prm.data?.point;
          if (!p) continue;
          const q = p.quality_flag;
          html += `<br/>${prm.marker}<b>${escapeHtml(String(prm.seriesName ?? ''))}</b>: ${p.value ?? 'Sem dados'} ${p.value !== null ? opts.unit : ''}`;
          if (q !== 0) html += ` <span style="color:${QUALITY_COLORS[q]};font-weight:600">[${qualityLabels[q]}]</span>`;
          if (p.audited) html += ` <span style="color:#6366f1;font-size:10px">✎ Auditado</span>`;
        }
        return html + '<br/><span style="font-size:10px;color:#64748b">Clique no ponto para Alterar Qualidade</span>';
      },
    },
    xAxis: { type: 'category', boundaryGap: false, data: keys.map(key => label(new Date(axisBase + key * bucket).toISOString(), opts.multiDay)), axisTick: { show: false }, axisLabel: { fontSize: 10 } },
    yAxis: { type: 'value', name: opts.unit, scale: true, axisLabel: { fontSize: 10 }, splitLine: { lineStyle: { color: 'rgba(148,163,184,.2)' } } },
    dataZoom: [{ type: 'inside', zoomOnMouseWheel: 'ctrl' }, { type: 'slider', height: 14, bottom: 6 }],
    series,
  };
}

export const stationLines = (stations: StationLite[], colors: string[], data: Record<number, ClassifiedPoint[]>, type: string, origin: string) =>
  stations.map((s, i) => ({ name: s.name, color: colors[i % colors.length], points: (data[s.id] ?? []).filter(p => p.sensor_measurement_type === type), origin }));
