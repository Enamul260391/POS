import { useState } from "react";

/**
 * Local text state for numeric cart fields (quantity, item discount, global
 * discount) that commit on blur or Enter instead of on every keystroke.
 *
 * The store validates and clamps every write, so the field must always end up
 * showing the store's answer. This hook derives instead of synchronising:
 *
 * - Not editing (`draft === null`) → the text is simply `String(value)`, so
 *   every external store change (clamp after a remove, clear cart, +/−
 *   buttons) shows up on the next render for free — no effect needed.
 * - Editing → the raw draft is kept locally so typing stays free (`1`, `12`,
 *   `2.5`, clearing the field).
 * - Commit → runs the store setter, then drops the draft, so the field snaps
 *   back to whatever was actually stored (validated, clamped). Empty or
 *   invalid input never reaches the store: an accidentally cleared field can
 *   never zero a quantity or discount.
 *
 * `onCommit` returns the value that was actually stored (the store setters
 * return the clamped amount), which is what the field displays afterwards.
 */
export interface CommittedNumberField {
  /** What the input displays: the local draft while editing, else the store value. */
  text: string;
  /** Replaces the local draft (no validation yet — that happens on commit). */
  setText: (text: string) => void;
  /** Parses, commits to the store, then returns to following the store. */
  commit: () => void;
}

export function useCommittedNumber(
  value: number,
  onCommit: (next: number) => number,
): CommittedNumberField {
  const [draft, setDraft] = useState<string | null>(null);

  const commit = () => {
    // Nothing was typed since the last commit: no store write at all.
    if (draft === null) {
      return;
    }
    const normalized = draft.trim().replace(",", ".");
    const parsed = normalized === "" ? Number.NaN : Number(normalized);
    if (Number.isFinite(parsed)) {
      onCommit(parsed);
    }
    // Drop the draft in every case: the field shows the store's answer — the
    // clamped amount, or the unchanged value when the input was invalid.
    setDraft(null);
  };

  return {
    text: draft ?? String(value),
    setText: (text: string) => setDraft(text),
    commit,
  };
}
