import { demoMeasurements, type Measurement, type Quality } from './data';

export type Thresholds = { maxTempDelta: number; maxRadDelta: number };
export const DEFAULT_THRESHOLDS: Thresholds = { maxTempDelta: 3, maxRadDelta: 400 };
export type Period = { start: string; end: string };
export type StationLite = { id: number; name: string; provider: string };
export type ClassifiedPoint = Measurement & { station_id: number; audited?: boolean; original_flag?: Quality };
export type OverrideMap = Record<string, Quality>;

export const STATION_COLORS = ['#0ea5e9', '#f97316', '#10b981', '#a855f7'];
export const TEMP = 'Air Temperature';
export const RAD = 'Solar Radiation';

export const overrideKey = (stationId: number, p: Pick<Measurement, 'timestamp' | 'sensor_measurement_type'> & Partial<Pick<Measurement, 'measurement_id'>>) =>
  p.measurement_id === undefined
    ? `${stationId}|${p.sensor_measurement_type}|${p.timestamp}`
    : `${stationId}|measurement|${p.measurement_id}`;

/** Lista de datas YYYY-MM-DD entre start e end (inclusive). */
export function daysBetween({ start, end }: Period): string[] {
  const out: string[] = [];
  const d = new Date(`${start}T12:00:00Z`);
  const last = new Date(`${end}T12:00:00Z`);
  while (d <= last && out.length < 31) { out.push(d.toISOString().slice(0, 10)); d.setUTCDate(d.getUTCDate() + 1); }
  return out;
}

/** Período anterior de mesma duração (default do "Comparar com..."). */
export function previousPeriod(p: Period): Period {
  const n = daysBetween(p).length;
  const s = new Date(`${p.start}T12:00:00Z`); s.setUTCDate(s.getUTCDate() - n);
  const e = new Date(`${p.start}T12:00:00Z`); e.setUTCDate(e.getUTCDate() - 1);
  return { start: s.toISOString().slice(0, 10), end: e.toISOString().slice(0, 10) };
}

/** Dados demo: substitua pelo retorno de /stations/compare quando conectar no backend. */
export function demoSeries(stationId: number, period: Period): Measurement[] {
  return daysBetween(period).flatMap(day => demoMeasurements(stationId, day));
}

/**
 * Reclassificação no cliente (espelha a regra de variação do QualityClassifier):
 * - value null            -> Lacuna (3)
 * - flag original 1        -> mantém Inválido (faixa física, decidido no backend)
 * - |Δ| vs último válido > limiar -> Suspeito (2)
 * - caso contrário        -> Válido (0)
 * Overrides manuais (auditoria) sempre vencem.
 */
export function reclassify(stationId: number, rows: Measurement[], t: Thresholds, overrides: OverrideMap): ClassifiedPoint[] {
  const last: Record<string, number | undefined> = {};
  return [...rows].sort((a, b) => a.timestamp.localeCompare(b.timestamp)).map(r => {
    const limit = r.sensor_measurement_type === TEMP ? t.maxTempDelta : t.maxRadDelta;
    let flag: Quality; let reason = r.flag_reason;
    if (r.value === null) flag = 3;
    else if (r.quality_flag === 1) flag = 1;
    else {
      const prev = last[r.sensor_measurement_type];
      if (prev !== undefined && Math.abs(r.value - prev) > limit) { flag = 2; reason = `Variação > ${limit}`; }
      else { flag = 0; reason = undefined; }
      if (flag === 0) last[r.sensor_measurement_type] = r.value;
    }
    const key = overrideKey(stationId, r);
    if (key in overrides) return { ...r, station_id: stationId, quality_flag: overrides[key], original_flag: flag, flag_reason: reason, audited: true };
    return { ...r, station_id: stationId, quality_flag: flag, flag_reason: reason };
  });
}

export type Summary = { avgTemp: number | null; peakRad: number | null; qualityScore: number; anomalies: number; audited: number };

export function summarize(points: ClassifiedPoint[]): Summary {
  const valid = (type: string) => points.filter(p => p.sensor_measurement_type === type && p.quality_flag === 0 && p.value !== null).map(p => p.value as number);
  const temps = valid(TEMP); const rads = valid(RAD);
  return {
    avgTemp: temps.length ? temps.reduce((a, b) => a + b, 0) / temps.length : null,
    peakRad: rads.length ? Math.max(...rads) : null,
    qualityScore: points.length ? (points.filter(p => p.quality_flag === 0).length / points.length) * 100 : 0,
    anomalies: points.filter(p => p.quality_flag === 1 || p.quality_flag === 2).length,
    audited: points.filter(p => p.audited).length,
  };
}

/** Variação percentual A vs B; null quando não comparável. */
export function delta(a: number | null, b: number | null): number | null {
  if (a === null || b === null || b === 0) return null;
  return ((a - b) / Math.abs(b)) * 100;
}
