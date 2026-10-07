"use client";

import { useState } from "react";
import { cn } from "@/components/ui";

export type DailyPoint = { date: string; value: number };

const dayMonth = new Intl.DateTimeFormat("es", { day: "numeric", month: "short" });
const fullDate = new Intl.DateTimeFormat("es", { weekday: "short", day: "numeric", month: "short" });

/** «2026-10-15» como fecha local (new Date("2026-10-15") sería UTC y podría mostrarse el día anterior). */
const parse = (iso: string) => {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d);
};

/** Qué representa cada columna del gráfico y cómo se rotula. */
export type Axis = {
  /** Encabezado de la primera columna de la tabla («Día», «Hora»). */
  column: string;
  /** Texto completo de una columna (tooltip, lectores de pantalla y tabla). */
  full: (key: string) => string;
  /** Etiqueta corta del eje X. */
  tick: (key: string) => string;
  /** Una etiqueta cada tantas columnas (más la última). */
  every: number;
  /** En la tabla, de la más reciente a la más antigua (días) o en orden (horas). */
  newestFirst: boolean;
};

export const DAY_AXIS: Axis = {
  column: "Día",
  full: (key) => fullDate.format(parse(key)),
  tick: (key) => dayMonth.format(parse(key)),
  every: 7,
  newestFirst: true,
};

/** Columnas por hora del día: `date` = «0» … «23». */
export const HOUR_AXIS: Axis = {
  column: "Hora",
  full: (key) => `${key.padStart(2, "0")}:00 a ${key.padStart(2, "0")}:59`,
  // «0 h», «6 h»…: cortas y cada 6 columnas para que no se pisen a 375 px de ancho (24 columnas en ~300 px).
  tick: (key) => `${Number(key)} h`,
  every: 6,
  newestFirst: false,
};

/** Tope del eje con marcas enteras limpias (0, mitad, máximo). */
function niceMax(value: number) {
  if (value <= 4) return 4;
  const step = value <= 20 ? 4 : value <= 100 ? 20 : 100;
  return Math.ceil(value / step) * step;
}

const PLOT_HEIGHT = 160; // px del área de barras; el eje X va aparte, así el contenedor nunca recorta sus etiquetas

/**
 * Columnas de una sola serie en el tiempo. Una serie = un color y sin leyenda (el título dice qué es).
 * Marcas: ≤ 24 px, extremo superior redondeado de 4 px y base recta; 2 px de aire entre barras;
 * cuadrícula de 1 px recesiva. Solo se etiqueta el máximo; el resto, por tooltip (hover y foco) y tabla.
 */
export function DailyBars({
  data,
  unit,
  label,
  format = String,
  axis = DAY_AXIS,
}: {
  data: DailyPoint[];
  unit: [string, string];
  label: string;
  /** Cómo se escribe un valor en el eje, la etiqueta y el tooltip (p. ej. dinero). */
  format?: (value: number) => string;
  /** Qué representa cada columna. Por defecto, días (`date` = «2026-10-15»); para otra cosa, p. ej. horas, ver HOUR_AXIS. */
  axis?: Axis;
}) {
  const [hover, setHover] = useState<number | null>(null);
  const [asTable, setAsTable] = useState(false);

  const max = Math.max(0, ...data.map((d) => d.value));
  const top = niceMax(max);
  const peak = max > 0 ? data.findIndex((d) => d.value === max) : -1;
  const noun = (n: number) => (n === 1 ? unit[0] : unit[1]);

  return (
    <figure className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <figcaption className="sr-only">{label}</figcaption>
        <button
          type="button"
          onClick={() => setAsTable((v) => !v)}
          aria-pressed={asTable}
          className="ml-auto rounded-lg px-2 py-1 text-xs text-muted hover:bg-brand-soft hover:text-foreground"
        >
          {asTable ? "Ver gráfico" : "Ver como tabla"}
        </button>
      </div>

      {asTable ? (
        <div className="max-h-64 overflow-y-auto rounded-lg border border-border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{label}</caption>
            <thead className="sticky top-0 bg-card text-xs text-muted">
              <tr>
                <th className="px-3 py-2 font-medium">{axis.column}</th>
                <th className="px-3 py-2 text-right font-medium">{unit[1][0].toUpperCase() + unit[1].slice(1)}</th>
              </tr>
            </thead>
            <tbody>
              {(axis.newestFirst ? [...data].reverse() : data).map((d) => (
                <tr key={d.date} className="border-t border-border">
                  <td className="px-3 py-1.5">{axis.full(d.date)}</td>
                  <td className="px-3 py-1.5 text-right tabular-nums">{format(d.value)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div role="group" aria-label={label} className="grid grid-cols-[2rem_1fr] gap-x-2">
          {/* Eje Y: solo las marcas que se leen sin etiquetar cada barra */}
          <div className="relative text-right text-xs text-muted" style={{ height: PLOT_HEIGHT }}>
            {[top, top / 2, 0].map((tick) => (
              <span key={tick} className="absolute right-0 -translate-y-1/2" style={{ top: `${(1 - tick / top) * 100}%` }}>
                {format(tick)}
              </span>
            ))}
          </div>

          <div>
            <div className="relative" style={{ height: PLOT_HEIGHT }}>
              {[0, 0.5, 1].map((fraction) => (
                <div key={fraction} aria-hidden className="absolute inset-x-0 border-t border-border" style={{ top: `${fraction * 100}%` }} />
              ))}

              <div className="absolute inset-0 flex items-end gap-[2px]">
                {data.map((d, i) => {
                  const height = (d.value / top) * 100;
                  const side = i < 4 ? "left-0" : i > data.length - 5 ? "right-0" : "left-1/2 -translate-x-1/2";
                  return (
                    // La columna entera (alto completo) es el objetivo del hover/foco, no solo los píxeles pintados.
                    <div
                      key={d.date}
                      role="img"
                      tabIndex={0}
                      aria-label={`${axis.full(d.date)}: ${format(d.value)} ${noun(d.value)}`}
                      onPointerEnter={() => setHover(i)}
                      onPointerLeave={() => setHover(null)}
                      onFocus={() => setHover(i)}
                      onBlur={() => setHover(null)}
                      className="relative flex h-full min-w-0 flex-1 items-end justify-center outline-none focus-visible:bg-brand-soft"
                    >
                      {d.value > 0 && (
                        <div
                          className={cn("w-full max-w-6 rounded-t-[4px] bg-chart-1 transition-[filter]", hover === i && "brightness-125")}
                          style={{ height: `${height}%`, minHeight: 2 }}
                        />
                      )}
                      {i === peak && hover !== i && (
                        <span className="pointer-events-none absolute -translate-y-full pb-1 text-xs font-medium" style={{ bottom: `${height}%` }}>
                          {format(d.value)}
                        </span>
                      )}
                      {hover === i && (
                        <div
                          role="tooltip"
                          className={cn(
                            "pointer-events-none absolute z-10 whitespace-nowrap rounded-lg border border-border bg-card px-2.5 py-1.5 text-xs shadow-md",
                            side,
                          )}
                          style={{ bottom: `calc(${height}% + 8px)` }}
                        >
                          <span className="block text-sm font-semibold">
                            {format(d.value)} {noun(d.value)}
                          </span>
                          <span className="text-muted">{axis.full(d.date)}</span>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Eje X: una etiqueta cada 7 días y la del último (sin pisarla con la anterior) */}
            <div className="flex h-6 items-start gap-[2px] pt-1.5 text-xs text-muted" aria-hidden>
              {data.map((d, i) => (
                <div key={d.date} className="relative min-w-0 flex-1">
                  {((i % axis.every === 0 && i < data.length - 4) || i === data.length - 1) && (
                    <span className={cn("absolute whitespace-nowrap", i === data.length - 1 ? "right-0" : "left-0")}>
                      {axis.tick(d.date)}
                    </span>
                  )}
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </figure>
  );
}
