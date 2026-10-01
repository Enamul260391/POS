"use client";

import { cn } from "cn";

import type { ChartPoint } from "./charts";
import { chartColor, toPercent } from "./charts";

export interface PieChartProps {
  /** Segments of the ring; order is the draw order. */
  data: readonly ChartPoint[];
  /** Renders the total centred in the ring. Defaults to the raw number. */
  totalFormatter?: (value: number) => string;
  /** Label shown under the total. */
  totalLabel?: string;
  className?: string;
}

const SIZE = 240;
const RADIUS = 96;
const INNER = 56;
const CENTER = 128;

/** SVG polar coordinate with 0° at the top, going clockwise. */
function polar(cx: number, cy: number, radius: number, degrees: number) {
  const a = ((degrees - 90) * Math.PI) / 180;
  return { x: cx + radius * Math.cos(a), y: cy + radius * Math.sin(a) };
}

/** Builds a single donut segment path between two angles (clockwise). */
function arcPath(cx: number, cy: number, radius: number, inner: number, startDeg: number, endDeg: number): string {
  const startOuter = polar(cx, cy, radius, startDeg);
  const endOuter = polar(cx, cy, radius, endDeg);
  const startInner = polar(cx, cy, inner, startDeg);
  const endInner = polar(cx, cy, inner, endDeg);
  const largeArc = endDeg - startDeg > 180 ? 1 : 0;
  return (
    `M ${startOuter.x.toFixed(2)} ${startOuter.y.toFixed(2)}` +
    ` A ${radius} ${radius} 0 ${largeArc} 1 ${endOuter.x.toFixed(2)} ${endOuter.y.toFixed(2)}` +
    ` L ${endInner.x.toFixed(2)} ${endInner.y.toFixed(2)}` +
    ` A ${inner} ${inner} 0 ${largeArc} 0 ${startInner.x.toFixed(2)} ${startInner.y.toFixed(2)} Z`
  );
}

const formatNumber = (value: number): string => String(Math.round(value));

/**
 * A minimal donut chart rendered in SVG — no third-party dependency.
 *
 * Each segment is an arc whose share of 360° is its fraction of the total, so a
 * small method still reads as a sliver. The total sits in the centre and a
 * colour legend below maps each method to its share. The same primitive can be
 * reused for any partition by passing a different `data` array.
 */
export function PieChart({
  data,
  totalFormatter = formatNumber,
  totalLabel = "Total",
  className,
}: PieChartProps) {
  const total = data.reduce((sum, point) => sum + point.value, 0);

  return (
    <div className={cn("flex w-full flex-col items-center gap-3", className)}>
      <div className="relative" style={{ width: SIZE, height: SIZE }}>
        <svg
          viewBox={`0 0 ${SIZE} ${SIZE}`}
          className="h-full w-full"
          role="img"
          aria-label={`${totalLabel} breakdown`}
        >
          <title>{totalLabel} breakdown</title>

          {total <= 0 ? (
            <text
              x={SIZE / 2}
              y={SIZE / 2}
              textAnchor="middle"
              dominantBaseline="middle"
              className="fill-muted-foreground text-xs"
            >
              No data
            </text>
          ) : (
            data.map((point, index) => {
              const fraction = point.value / total;
              const accumulated =
                (data
                  .slice(0, index)
                  .reduce((sum, p) => sum + p.value, 0) / total) *
                360;
              const path = arcPath(CENTER, CENTER, RADIUS, INNER, accumulated, accumulated + fraction * 360);
              return (
                <g key={point.label}>
                  <path d={path} fill={chartColor(index)} />
                  <title>{`${point.label}: ${totalFormatter(point.value)} (${toPercent(point.value, total)}%)`}</title>
                </g>
              );
            })
          )}

          {/* Centre total. */}
          <text
            x={CENTER}
            y={CENTER - 6}
            textAnchor="middle"
            dominantBaseline="middle"
            className="fill-foreground text-xl font-semibold tabular-nums"
          >
            {totalFormatter(total)}
          </text>
          <text
            x={CENTER}
            y={CENTER + 14}
            textAnchor="middle"
            className="fill-muted-foreground text-xs"
          >
            {totalLabel}
          </text>
        </svg>

        {/* Legend. */}
        <ul className="mt-2 flex flex-col gap-1.5 text-xs">
          {data.map((point, index) => (
            <li key={point.label} className="flex items-center justify-between gap-3">
              <span className="flex items-center gap-1.5">
                <span
                  className="block size-2.5 shrink-0 rounded-sm"
                  style={{ backgroundColor: chartColor(index) }}
                />
                <span className="truncate">{point.label}</span>
              </span>
              <span className="tabular-nums text-muted-foreground">
                {totalFormatter(point.value)} ({toPercent(point.value, total)}%)
              </span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

