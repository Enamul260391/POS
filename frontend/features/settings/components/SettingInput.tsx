"use client";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "cn";

import type { SettingField } from "@/features/settings/lib/settings";

/**
 * A single settings input.
 *
 * Values are edited as **text in both directions**. The backend stores them in a
 * `text` column and validates `nullable|string`, so a value that happens to look
 * numeric stays the exact string the user typed: nothing here parses, rounds or
 * reformats, which is what keeps a tax rate of `"5.00"` from becoming `5`.
 *
 * The `number` input uses `type="number"` with `inputMode="decimal"` for the
 * numeric keypad and the browser's own stepper affordance, but the value is still
 * carried as a string — no `valueAsNumber`, no float round trip.
 */

const TEXTAREA_CLASSNAME =
  "flex w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30";

/** Inline validation message rendered under a field. */
function FormError({ message }: { message?: string }) {
  if (message === undefined) {
    return null;
  }
  return (
    <p className="text-xs text-destructive" role="alert">
      {message}
    </p>
  );
}

export interface SettingInputProps {
  field: SettingField;
  value: string;
  error?: string;
  disabled: boolean;
  onChange: (value: string) => void;
}

export function SettingInput({
  field,
  value,
  error,
  disabled,
  onChange,
}: SettingInputProps) {
  const inputId = `setting-${field.key}`;
  const describedBy = `${inputId}-hint`;

  return (
    <div className="grid gap-1.5">
      <Label htmlFor={inputId}>{field.label}</Label>

      {field.kind === "textarea" ? (
        <textarea
          id={inputId}
          rows={2}
          placeholder={field.placeholder}
          aria-invalid={error !== undefined}
          aria-describedby={describedBy}
          disabled={disabled}
          className={TEXTAREA_CLASSNAME}
          value={value}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : field.kind === "select" ? (
        <select
          id={inputId}
          aria-invalid={error !== undefined}
          aria-describedby={describedBy}
          disabled={disabled}
          className={cn(
            "h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive",
            // An unset select must render as empty, not as its first option, so
            // the "no value stored" state is visible rather than implied.
            value === "" ? "text-muted-foreground" : undefined,
          )}
          value={value}
          onChange={(event) => onChange(event.target.value)}
        >
          <option value="">Not set</option>
          {(field.options ?? []).map((option) => (
            <option key={option} value={option}>
              {option === "1" ? "Yes" : option === "0" ? "No" : option}
            </option>
          ))}
        </select>
      ) : (
        <Input
          id={inputId}
          type={field.kind === "number" ? "number" : "text"}
          inputMode={field.kind === "number" ? "decimal" : undefined}
          step="any"
          placeholder={field.placeholder}
          aria-invalid={error !== undefined}
          aria-describedby={describedBy}
          disabled={disabled}
          value={value}
          onChange={(event) => onChange(event.target.value)}
        />
      )}

      <p id={describedBy} className="text-xs text-muted-foreground">
        {field.hint}
      </p>
      <FormError message={error} />
    </div>
  );
}
