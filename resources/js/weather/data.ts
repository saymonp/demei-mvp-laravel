export type Quality = 0 | 1 | 2 | 3;
export type Measurement = { timestamp: string; value: number | null; quality_flag: Quality; flag_reason?: string | undefined; sensor_measurement_type: string };
export const qualityLabels = ['Válido', 'Inválido', 'Suspeito', 'Lacuna'] as const;
export const stations = [
 {id:1,name:'Ijuí',provider:'EST-01',lat:-28.37,lon:-53.94,alt:326,quality:'91,7',x:51,y:50},
 {id:2,name:'Santo Ângelo',provider:'EST-02',lat:-28.30,lon:-54.26,alt:289,quality:'97,9',x:35,y:36},
 {id:3,name:'Panambi',provider:'EST-03',lat:-28.28,lon:-53.50,alt:351,quality:'85,4',x:76,y:33},
 {id:4,name:'Cruz Alta',provider:'EST-04',lat:-28.64,lon:-53.61,alt:452,quality:'96,9',x:70,y:81},
 {id:5,name:'São Luiz Gonzaga',provider:'EST-05',lat:-28.41,lon:-54.96,alt:245,quality:'78,1',x:9,y:52},
] as const;
export function demoMeasurements(stationId: number, date: string): Measurement[] {
 const rows: Measurement[]=[];
 for(let i=0;i<48;i++) {
  const h=i/2; const shift=(stationId-1)*.65;
  let rad=Math.round(Math.max(0,Math.sin((h-6)/12*Math.PI))*958*(1-shift/20));
  let temp=Math.round((20.8+6.5*Math.sin((h-9)/12*Math.PI)+shift)*10)/10;
  let rf:Quality=0; let tf:Quality=0; let reason='';
  if(i===3||i===44){rad=i===3?-4:-2;rf=2;reason='Irradiação negativa';}
  if(i===26){rad=1340;rf=1;reason='Irradiação fora de faixa';}
  if(i===30){temp=58.4;tf=1;reason='Temperatura extrema';}
  if(i===23)rad=958;
  if(i===31)temp=27.3+shift;
  const gap=[18,19,20,40].includes(i);
  const timestamp=`${date}T${String(Math.floor(h)).padStart(2,'0')}:${i%2?'30':'00'}:00-03:00`;
  rows.push({timestamp,value:rad,quality_flag:rf,flag_reason:rf?reason:undefined,sensor_measurement_type:'Solar Radiation'});
  rows.push({timestamp,value:gap?null:temp,quality_flag:gap?3:tf,flag_reason:gap?'Temperatura nula, registro de lacuna gerado':tf?reason:undefined,sensor_measurement_type:'Air Temperature'});
 }
 return rows;
}
export function stats(rows:Measurement[]) {
 const values=rows.filter(r=>r.quality_flag===0&&r.value!==null).map(r=>Number(r.value));
 return {max:values.length?Math.max(...values):null,avg:values.length?values.reduce((a,b)=>a+b,0)/values.length:null};
}
export function utcTimestamp(value:string) {
 return /(?:Z|[+-]\d{2}:?\d{2})$/.test(value)?value:value.replace(' ','T')+'Z';
}
export const formatNumber=(n:number|null, digits=1)=>n===null?'—':n.toLocaleString('pt-BR',{minimumFractionDigits:digits,maximumFractionDigits:digits});
export const timeLabel=(timestamp:string)=>new Intl.DateTimeFormat('pt-BR',{hour:'2-digit',minute:'2-digit',timeZone:'America/Sao_Paulo'}).format(new Date(utcTimestamp(timestamp)));
export function csvContent(rows: Measurement[]) {
 return '\ufefftimestamp;sensor_measurement_type;value;quality_flag;flag_reason\n'+rows.map(r=>[r.timestamp,r.sensor_measurement_type,r.value??'',r.quality_flag,r.flag_reason??''].map(v=>'"'+String(v).replaceAll('"','""')+'"').join(';')).join('\n');
}
