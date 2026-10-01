/**
 * Local date/time formatting shared across management screens (Phase 7).
 *
 * Deliberately manual (getters + padding) instead of `toLocaleString`: the
 * output is identical everywhere, so a screen that renders during SSR and in
 * the browser only ever differs by time zone — never by locale. Elements that
 * paint before interaction (e.g. the movement list) can add
 * `suppressHydrationWarning` to cover that time zone gap.
 */

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

/** `2026-09-23 14:05` from an ISO string or Date, in local time. */
export function formatLocalDateTime(value: string | Date): string {
  const date = typeof value === "string" ? new Date(value) : value;
  if (Number.isNaN(date.getTime())) {
    return "—";
  }
  return (
    `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())} ` +
    `${pad2(date.getHours())}:${pad2(date.getMinutes())}`
  );
}
