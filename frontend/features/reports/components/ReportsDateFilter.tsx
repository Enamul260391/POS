"use client";

import { useMemo } from "react";

import { Calendar } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import {
  DATE_PRESET_OPTIONS,
  resolveDateRange,
  type DatePreset,
  type ReportDateFilter,
} from "@/features/reports/lib/reports";
import { cn } from "cn";

interface ReportsDateFilterProps {
  /** Controlled filter value; the screen owns the state. */
  value: ReportDateFilter;
  /** Called with a new filter whenever the preset or custom range changes. */
  onChange: (filter: ReportDateFilter) => void;
}

/**
 * The date window picker for the reports dashboard (Phase 12).
 *
 * Offers the requested presets (Today / Yesterday / This week / This month /
 * Custom) and, for the custom preset, two native `<input type="date">` controls.
 * It is fully controlled — `ReportsScreen` holds the single `ReportDateFilter`
 * and re-derives every report from it, so switching a preset never shows stale
 * data. Custom dates are clamped so `to >= from` and never future-dated; a reset
 * button returns to the last preset selection.
 */
export function ReportsDateFilter({
  value,
  onChange,
}: ReportsDateFilterProps) {
  // `new Date()` sampled once on mount so "today" stays stable for the session.
  const todayISO = useMemo(() => {
    const now = new Date();
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  }, []);

  const resolved = useMemo(() => resolveDateRange(value), [value]);
  const presetLabel =
    DATE_PRESET_OPTIONS.find((option) => option.value === value.preset)
      ?.label ?? "This month";
  const isCustom = value.preset === "custom";

  const from = value.from ?? "";
  const to = value.to ?? "";

    const onPresetChange = (preset: DatePreset | null) => {
    if (preset !== null) {
      onChange({ preset });
    }
  };

  const onFromChange = (next: string) => {
    // Keep `to` >= `from`: if the new start is after the end, extend the end.
    const nextTo = to && next && next > to ? next : to;
    onChange({ ...value, preset: "custom", from: next, to: nextTo });
  };
  const onToChange = (next: string) => {
    // Keep `from` <= `to`: if the new end is before the start, move the start.
    const nextFrom = from && next && from > next ? next : from;
    onChange({ ...value, preset: "custom", from: nextFrom, to: next });
  };

  const canReset = isCustom && (from || to);

  return (
    <div className="flex flex-col gap-2 sm:items-center sm:justify-between sm:gap-3 sm:flex-row">
      <div className="flex flex-wrap items-center gap-2">
        <Calendar className="size-4 text-muted-foreground" aria-hidden="true" />
        <span className="text-xs font-medium text-muted-foreground">
          Period
        </span>

        <Select value={value.preset} onValueChange={onPresetChange}>
          <SelectTrigger
            aria-label="Report period"
            className="w-full min-w-44"
          >
            <span
              className={cn(
                "truncate",
                isCustom ? undefined : "text-muted-foreground",
              )}
            >
              {presetLabel}
            </span>
          </SelectTrigger>
          <SelectContent>
            {DATE_PRESET_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {isCustom && (
          <>
            <Input
              type="date"
              aria-label="Start date"
              value={from}
              max={todayISO}
              onChange={(event) => onFromChange(event.target.value)}
              className="w-44"
            />
            <Input
              type="date"
              aria-label="End date"
              value={to}
              min={from || undefined}
              max={todayISO}
              onChange={(event) => onToChange(event.target.value)}
              className="w-44"
            />
          </>
        )}
      </div>

      <div className="flex items-center gap-2">
        <span
          className="rounded-md bg-muted px-2.5 py-1 text-xs font-medium tabular-nums"
          aria-live="polite"
        >
          {resolved.label}
        </span>
        {canReset && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onChange({ preset: "month" })}
          >
            Reset
          </Button>
        )}
      </div>
    </div>
  );
}

function pad(value: number): string {
  return String(value).padStart(2, "0");
}

