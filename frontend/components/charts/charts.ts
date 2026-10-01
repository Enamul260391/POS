/**
 * Shared types and palette for the lightweight SVG chart components.
 *
 * Every chart below is rendered with hand-rolled SVG and no third-party
 * dependency, so the bundle never grows a charting library. Colours reference
 * CSS variables from `app/globals.css`, which means charts adapt to light and
 * dark mode automatically and the whole palette can be re-skinned from a single
 * place — the same `--chart-N` and status tokens the rest of the UI already uses.
 *
 * The shapes here are deliberately generic (`{ label, value }`); each report
 * passes it the slice of data it cares about, which is exactly the seam a Laravel
 * API response will fill when the mock stores are swapped for real data.
 */

/** A single point plotted by a chart. */
export interface ChartPoint {
  /** Dimension label, e.g. a date or product name. */
  label: string;
  /** Magnitude to plot. */
  value: number;
}

/**
 * Palette cycled through for multi-segment (pie) or multi-bar charts.
 * Semantic status colours keep meaning consistent across reports.
 */
export const CHART_PALETTE: readonly string[] = [
  "var(--primary)",
  "var(--success)",
  "var(--info)",
  "var(--warning)",
  "var(--secondary)",
  "var(--destructive)",
  "var(--muted-foreground)",
  "var(--chart-3)",
];

/** Resolves a palette colour for an index, cycling past the end. */
export function chartColor(index: number): string {
  return CHART_PALETTE[index % CHART_PALETTE.length];
}

/** Turns `value/total` into a rounded percentage (0–100). Zero total -> 0. */
export function toPercent(value: number, total: number): number {
  return total <= 0 ? 0 : Math.round((value / total) * 100);
}
