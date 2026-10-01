"use client";

import { cn } from "cn";

import type { ChartPoint } from "./charts";

export interface LineChartProps {
  /** Points plotted left to right, in order (e.g. one per day). */
  data: readonly ChartPoint[];
  /** Renders each value on the axis and in the tooltip, e.g. a money formatter. */
  valueFormatter?: (value: number) => string;
  /** Stroke colour of the line and dots (a CSS variable works for dark mode). */
  color?: string;
  /** Chart height in pixels. */
  height?: number;
  className?: string;
}

const WIDTH = 760;
const DEFAULT_HEIGHT = 220;
const LEFT = 48;
const RIGHT = 16;
const TOP = 16;
const BOTTOM = 40;
/** Number of horizontal grid bands drawn. */
const GRID = 4;

const formatNumber = (value: number): string => String(Math.round(value));

/**
 * A minimal, dependency-free line chart rendered in SVG.
 *
 * Draws a value axis with a gentle grid, a filled area under the polyline, the
 * connecting line and one dot per point. It powers the sales-trend report; the
 * same primitive can be reused for any time series by swapping `data`.
 */
export function LineChart({
  data,
  valueFormatter = formatNumber,
  color = "var(--primary)",
  height = DEFAULT_HEIGHT,
  className,
}: LineChartProps) {
  if (data.length === 0) {
    return (
      <div
        className={cn("relative w-full", className)}
        style={{ height }}
        role="img"
        aria-label="No data to display"
      >
        <svg
          viewBox={`0 0 ${WIDTH} ${height}`}
          preserveAspectRatio="none"
          className="absolute inset-0 h-full w-full"
        >
          <text
            x={WIDTH / 2}
            y={height / 2}
            textAnchor="middle"
            className="fill-muted-foreground text-xs"
          >
            No data to display
          </text>
        </svg>
      </div>
    );
  }

  const max = Math.max(...data.map((point) => point.value), 0) || 1;
  const plotW = WIDTH - LEFT - RIGHT;
  const plotH = Math.max(height - TOP - BOTTOM, 32);
  const count = data.length;
  const xStep = count > 1 ? plotW / (count - 1) : 0;

  const points = data.map((point, index) => ({
    x: LEFT + (count > 1 ? index * xStep : plotW / 2),
    y: TOP + plotH - (point.value / max) * plotH,
    value: point.value,
    label: point.label,
  }));

  // "M x y L x y ..." for the stroke, reused by the area fill below it.
  const linePath = points
    .map((point, index) => `${index === 0 ? "M" : "L"} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`)
    .join(" ");

  const first = points[0];
  const last = points[points.length - 1];
  const areaPath = `${linePath} L ${last.x.toFixed(1)} ${TOP} L ${first.x.toFixed(1)} ${TOP} Z`;

  // Show at most ~8 x-axis labels, always including the last point.
  const labelStep = Math.max(1, Math.ceil(count / 8));

  return (
    <div className={cn("relative w-full", className)} style={{ height }}>
      <svg
        viewBox={`0 0 ${WIDTH} ${height}`}
        preserveAspectRatio="none"
        className="h-full w-full"
        role="img"
        aria-label="Sales trend chart"
      >
        <title>Sales trend</title>

        {/* Grid + y-axis value labels. */}
        {Array.from({ length: GRID + 1 }).map((_, index) => {
          const fraction = index / GRID;
          const y = TOP + plotH - fraction * plotH;
          return (
                        <g key={index}>
              <line
                x1={LEFT}
                y1={y}
                x2={LEFT + plotW}
                y2={y}
                className="stroke-muted-foreground/35"
              />
              <text
                x={LEFT - 6}
                y={y}
                textAnchor="end"
                dominantBaseline="middle"
                className="fill-muted-foreground text-xs"
              >
                {valueFormatter(fraction * max)}
              </text>
            </g>
          );
        })}

        {/* Area under the line (kept behind the stroke). */}
        <path d={areaPath} fill={color} fillOpacity={0.12} />

        {/* The connecting polyline. */}
        <path
          d={linePath}
          stroke={color}
          strokeWidth={2}
          fill="none"
          strokeLinecap="round"
          strokeLinejoin="round"
        />

        {/* One dot per point, with a text alternative for screen readers. */}
        {points.map((point, index) => (
          <g key={index}>
            <circle cx={point.x} cy={point.y} r={3.5} fill={color} />
            <title>{`${point.label}: ${valueFormatter(point.value)}`}</title>
          </g>
        ))}

        {/* X-axis labels, rotated so dense dates stay readable. */}
        {points.map((point, index) => {
          if (index % labelStep !== 0 && index !== count - 1) {
            return null;
          }
          const baseline = height - BOTTOM + 8;
          return (
            <text
              key={index}
              x={point.x}
              y={baseline}
              textAnchor="end"
              transform={`rotate(-38 ${point.x.toFixed(1)} ${baseline})`}
              className="fill-muted-foreground text-xs"
            >
              {point.label}
            </text>
          );
        })}
      </svg>
    </div>
  );
}
