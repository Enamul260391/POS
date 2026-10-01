"use client";

import { cn } from "cn";

import type { ChartPoint } from "./charts";

export interface BarChartProps {
  /** Bars drawn top to bottom, in order. */
  data: readonly ChartPoint[];
  /** Renders each bar value, e.g. a money formatter. */
  valueFormatter?: (value: number) => string;
  /** Bar colour (a CSS variable works for dark mode). */
  color?: string;
  /** Explicit chart height; otherwise sized from the number of rows. */
  height?: number;
  className?: string;
}

const WIDTH = 760;
const ROW_HEIGHT = 34;
const LEFT = 130;
const RIGHT = 24;
const TOP = 16;
const BOTTOM = 24;
const BAR_HEIGHT = 12;

const formatNumber = (value: number): string => String(Math.round(value));

/**
 * A minimal, dependency-free horizontal bar chart rendered in SVG.
 *
 * A value axis with gridlines sits on the left, each row carries a label, the
 * bar and its formatted value. Powers the "top products" report; reuse it for
 * any ranked list by passing a different `data` array.
 */
export function BarChart({
  data,
  valueFormatter = formatNumber,
  color = "var(--primary)",
  height: heightProp,
  className,
}: BarChartProps) {
  const count = data.length;
  const height = heightProp ?? Math.max(180, count * ROW_HEIGHT + TOP + BOTTOM + 12);

  if (count === 0) {
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
  const gridBuckets = 4;

  return (
    <div className={cn("relative w-full", className)} style={{ height }}>
      <svg
        viewBox={`0 0 ${WIDTH} ${height}`}
        preserveAspectRatio="none"
        className="h-full w-full"
        role="img"
        aria-label="Top products chart"
      >
        <title>Top products by sales</title>

        {/* Vertical gridlines + x-axis value labels. */}
        {Array.from({ length: gridBuckets + 1 }).map((_, index) => {
          const fraction = index / gridBuckets;
          const x = LEFT + fraction * plotW;
          const value = fraction * max;
          return (
            <g key={index}>
              <line
                x1={x}
                y1={TOP}
                x2={x}
                y2={TOP + count * ROW_HEIGHT - ROW_HEIGHT / 2 + BAR_HEIGHT / 2}
                stroke="currentColor"
                strokeWidth={1}
                style={{ opacity: 0.35 }}
              />
              <text
                x={x - 4}
                y={TOP - 6}
                textAnchor="end"
                className="fill-muted-foreground text-xs"
              >
                {valueFormatter(value)}
              </text>
            </g>
          );
        })}

        {/* Bars. */}
        {data.map((point, index) => {
          const centerY = TOP + index * ROW_HEIGHT + ROW_HEIGHT / 2;
          const barW = (point.value / max) * plotW;
          return (
            <g key={point.label}>
              <rect
                x={LEFT}
                y={centerY - BAR_HEIGHT / 2}
                width={barW}
                height={BAR_HEIGHT}
                fill={color}
                rx={2}
              />
              <title>{`${point.label}: ${valueFormatter(point.value)}`}</title>
              <text
                x={LEFT - 8}
                y={centerY}
                textAnchor="end"
                dominantBaseline="middle"
                className="fill-muted-foreground text-xs"
                aria-label={point.label}
              >
                {point.label}
              </text>
              <text
                x={LEFT + barW + 8}
                y={centerY}
                dominantBaseline="middle"
                className="fill-muted-foreground text-xs"
              >
                {valueFormatter(point.value)}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}
