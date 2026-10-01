/**
 * Date-window handling for the `/reports` dashboard.
 *
 * The screen keeps a single {@link ReportDateFilter} — the preset the user
 * picked, plus the two dates of a custom range — and every report request is
 * made for the window that preset resolves to. Nothing is derived from a store
 * any more: each card is a pure function of one `GET /reports/*` response, so
 * this module's only remaining job is turning a preset into the two
 * `YYYY-MM-DD` strings the API validates.
 *
 * The two date dialects are kept strictly apart:
 *
 *   - a `Date` here is a *calendar* day in the browser's own timezone and is
 *     used only to derive the label shown in the filter chip;
 *   - a `YYYY-MM-DD` string is what goes on the wire, built by
 *     {@link toApiDate} from the local calendar getters so the day a user
 *     picked can never shift across a timezone boundary.
 */

const MS_PER_DAY = 24 * 60 * 60 * 1000;

const MONTH_SHORT = [
  "Jan", "Feb", "Mar", "Apr", "May", "Jun",
  "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
] as const;
const MONTH_FULL = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
] as const;

/** Preset the date filter can select. `custom` carries its own from/to. */
export type DatePreset = "today" | "yesterday" | "week" | "month" | "custom";

/** Preset label options for the filter select, in display order. */
export const DATE_PRESET_OPTIONS: readonly { value: DatePreset; label: string }[] =
  [
    { value: "today", label: "Today" },
    { value: "yesterday", label: "Yesterday" },
    { value: "week", label: "This week" },
    { value: "month", label: "This month" },
    { value: "custom", label: "Custom date range" },
  ];

/** Current filter state of the reports date picker. */
export interface ReportDateFilter {
  preset: DatePreset;
  /** `YYYY-MM-DD` strings, only meaningful when `preset === "custom"`. */
  from?: string;
  to?: string;
}

/** The resolved, ready-to-compare date window plus a human label. */
export interface ResolvedDateRange {
  preset: DatePreset;
  /** Inclusive start, at 00:00 local. */
  from: Date;
  /** Inclusive end, at 23:59:59.999 local. */
  to: Date;
  /** e.g. "Today", "This month", "Sep 21 – Sep 27". */
  label: string;
}

function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function endOfDay(date: Date): Date {
  return new Date(startOfDay(date).getTime() + MS_PER_DAY - 1);
}

/** Monday-based start of the week containing `date`. */
function startOfWeek(date: Date): Date {
  const day = startOfDay(date);
  const shift = day.getDay() === 0 ? -6 : 1 - day.getDay();
  return new Date(day.getTime() + shift * MS_PER_DAY);
}

/** End of the week (Sunday) containing `date`. */
function endOfWeek(date: Date): Date {
  return new Date(startOfWeek(date).getTime() + 6 * MS_PER_DAY - 1);
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function endOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0, 23, 59, 59, 999);
}

/** Parses a `YYYY-MM-DD` string as a local date. */
function parseLocalDate(value: string): Date {
  const parts = value.split("-").map(Number);
  if (parts.length !== 3 || parts.some(Number.isNaN)) {
    return new Date(NaN);
  }
  return new Date(parts[0], parts[1] - 1, parts[2]);
}


/** Resolves the chosen preset into a concrete date window. Anchored to `now`. */
export function resolveDateRange(
  filter: ReportDateFilter,
  now: Date = new Date(),
): ResolvedDateRange {
  let from: Date;
  let to: Date;

  switch (filter.preset) {
    case "today":
      from = startOfDay(now);
      to = endOfDay(now);
      break;
    case "yesterday": {
      const yesterday = new Date(now.getTime() - MS_PER_DAY);
      from = startOfDay(yesterday);
      to = endOfDay(yesterday);
      break;
    }
    case "week":
      from = startOfWeek(now);
      to = endOfWeek(now);
      break;
    case "month":
      from = startOfMonth(now);
      to = endOfMonth(now);
      break;
    case "custom": {
      const start = filter.from ? parseLocalDate(filter.from) : startOfMonth(now);
      const end = filter.to ? parseLocalDate(filter.to) : endOfMonth(now);
      from = startOfDay(start <= end ? start : end);
      to = endOfDay(start <= end ? end : start);
      break;
    }
  }

  return {
    preset: filter.preset,
    from,
    to,
    label: formatRangeLabel(filter.preset, from, to),
  };
}

function formatRangeLabel(preset: DatePreset, from: Date, to: Date): string {
  switch (preset) {
    case "today":
      return "Today";
    case "yesterday":
      return "Yesterday";
    case "week":
    case "custom":
      return `${formatDateShort(from)} – ${formatDateShort(to)}`;
    case "month":
      return `${MONTH_FULL[from.getMonth()]} ${from.getFullYear()}`;
  }
}

function formatDateShort(date: Date): string {
  return `${MONTH_SHORT[date.getMonth()]} ${date.getDate()}`;
}

/**
 * Formats a resolved window boundary as the `YYYY-MM-DD` the API expects.
 *
 * The local calendar getters (`getFullYear` / `getMonth` / `getDate`) are used
 * rather than `toISOString()`, which converts to UTC first: in any timezone
 * west of Greenwich that turns 2026-09-01 00:00 local into 2026-08-31, and
 * the server would then filter the wrong day. The report columns are plain
 * `date` values (`sale_date`, `purchase_date`, `expense_date`), so a bare
 * calendar date is exactly the right thing to send.
 */
export function toApiDate(date: Date): string {
  const year = String(date.getFullYear()).padStart(4, "0");
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}
