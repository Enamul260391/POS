"use client";

import { useCallback, useMemo, useRef, useState } from "react";
import { Loader2, Plus, RefreshCw, Save } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SettingInput } from "@/features/settings/components/SettingInput";
import { SETTING_GROUPS, type SettingsFormValues } from "@/features/settings/lib/settings";
import {
  extraSettingKeys,
  fieldForExtraKey,
  hasPendingChanges,
  settingsBatchError,
  settingsValidationErrors,
  toSettingsFormValues,
  toSettingsPatch,
  validateSettingsForm,
  type SettingsFieldErrors,
} from "@/features/settings/lib/settingsSchema";
import {
  getSettings,
  updateSettings,
} from "@/features/settings/services/settingsService";
import { useApiResource } from "@/lib/api/useApiResource";
import { messageOfApiError } from "@/lib/api/validation";
import type { ApiValidationErrors } from "@/types/api";

/** Shared empty baseline, so the diff has a stable reference before the first load. */
const EMPTY_SETTINGS: Record<string, string | null> = {};
/** Shared empty diff, so `isDirty` never depends on a fresh object identity. */
const EMPTY_PATCH: Record<string, string | null> = {};

/**
 * `/settings` screen — the business's key/value configuration, API-backed.
 *
 * Everything on screen comes from `GET /settings` and is written back with
 * `PATCH /settings`. There is no store, no `localStorage`/`sessionStorage` and no
 * mock data in the path: the API is the only source of truth, so a saved value
 * survives a reload because a reload re-reads the database, not because it
 * restored local state.
 *
 * How it is wired:
 *
 *   - `useApiResource` runs the loader on mount, aborts the previous run, and
 *     exposes loading / refreshing / error / retry. It is the same hook every
 *     other API-backed screen uses, so there is one fetching pattern, not two.
 *   - The form is seeded **only** from the API response: `data` is copied into
 *     local state by an effect keyed on the response itself, so server values are
 *     never replaced by catalogue placeholders after the fact. A `null` value
 *     renders as an empty input, and stays empty until the user types.
 *   - Save sends only the changed keys (`PATCH` upserts exactly what it
 *     receives) and takes the returned collection as the new state, so the form
 *     always reflects what the server actually stored.
 *
 * Tenancy: the screen has no business selector and never sends `business_id`.
 * `SettingsController` resolves the owning business from the bearer token, so the
 * client has no way to address another tenant's settings.
 */
export function SettingsScreen() {
  const load = useCallback((signal: AbortSignal) => getSettings({ signal }), []);

  const {
    data,
    isLoading,
    isRefreshing,
    error,
    isForbidden,
    refresh,
  } = useApiResource(load, []);

  /**
   * The form's values plus the baseline they are diffed against.
   *
   * Held as one object so a new API response can be adopted in a single state
   * write (see the reset below) — the inputs and the "what is stored" baseline
   * can never end up out of step with each other.
   */
  interface SettingsForm {
    /** What the inputs currently show. */
    values: SettingsFormValues;
    /** The last values the server confirmed; the baseline of the diff. */
    saved: Record<string, string | null>;
  }

  const [form, setForm] = useState<SettingsForm | null>(null);
  const [fieldErrors, setFieldErrors] = useState<SettingsFieldErrors>({});
  const [saveError, setSaveError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [isSaved, setIsSaved] = useState(false);
  /** Key of a newly added, not-yet-saved setting. */
  const [newKey, setNewKey] = useState("");
  const [newKeyError, setNewKeyError] = useState<string | null>(null);

  /**
   * Guards a save against a second, concurrent submit.
   *
   * `isSaving` alone is not enough: React batches the state update, so two clicks
   * in the same tick would both read the old value. A ref is updated
   * synchronously and therefore closes that window.
   */
  const isSavingRef = useRef(false);
  /**
   * Identifies the newest save so a slow response can never overwrite the state
   * of a newer one (mirrors `useApiResource`'s run-id guard).
   */
  const saveRunIdRef = useRef(0);

  /**
   * The response the form was last seeded from.
   *
   * Comparing by identity (not deep-equality) is what makes this run exactly
   * once per response and never on a keystroke.
   */
  const [seededFrom, setSeededFrom] = useState(data);

  // Adopt a new API response during render — React's documented alternative to
  // an effect for "reset state when an input changes". The comparison above is
  // the guard, so this is a state write only when the response actually changed.
  if (data !== seededFrom) {
    setSeededFrom(data);
    setForm(
      data === null ? null : { values: toSettingsFormValues(data), saved: data },
    );
    setFieldErrors({});
    setSaveError(null);
    setIsSaved(false);
  }

  const values = form === null ? null : form.values;
  const saved = form === null ? EMPTY_SETTINGS : form.saved;

  const patch = useMemo(
    () => (values === null ? EMPTY_PATCH : toSettingsPatch(values, saved)),
    [values, saved],
  );
  const isDirty = hasPendingChanges(patch);
  const isBusy = isSaving || isLoading;

  const setValue = useCallback((key: string, value: string) => {
    setForm((current) =>
      current === null
        ? current
        : { ...current, values: { ...current.values, [key]: value } },
    );
    // Any edit invalidates the previous "Saved" confirmation.
    setIsSaved(false);
    // The API owns the wording; clear a previous message as soon as the field is
    // edited so a stale error is not left sitting on a corrected input.
    setFieldErrors((current) => {
      if (!(key in current)) {
        return current;
      }
      const next = { ...current };
      delete next[key];
      return next;
    });
  }, []);

  const addKey = useCallback(() => {
    const key = newKey.trim();
    if (key === "" || values === null) {
      return;
    }
    if (key in values) {
      setNewKeyError("A setting with this key already exists");
      return;
    }
    const problem = validateNewKey(key);
    if (problem !== null) {
      setNewKeyError(problem);
      return;
    }
    setForm((current) =>
      current === null
        ? current
        : { ...current, values: { ...current.values, [key]: "" } },
    );
    setIsSaved(false);
    setNewKey("");
    setNewKeyError(null);
  }, [newKey, values]);



  /**
   * Saves the changed keys.
   *
   * The happy path takes the API's own response as the new state — both the form
   * values and the diff baseline — so what is displayed is what the database now
   * holds, not what was typed. A `422` maps its error bag onto the setting keys
   * the API keyed it by; anything else shows the backend's message and leaves the
   * form exactly as the user left it, so a failed save can never look like a
   * successful one.
   */
  const save = useCallback(async () => {
    if (values === null || isSavingRef.current) {
      return;
    }

    const localErrors = validateSettingsForm(values);
    if (localErrors !== null) {
      setFieldErrors(localErrors);
      setSaveError(null);
      return;
    }

    const body = toSettingsPatch(values, saved);
    if (!hasPendingChanges(body)) {
      return;
    }

    isSavingRef.current = true;
    setIsSaving(true);
    setSaveError(null);
    setFieldErrors({});
    saveRunIdRef.current += 1;
    const runId = saveRunIdRef.current;

    try {
      const result = await updateSettings(body);
      if (runId !== saveRunIdRef.current) {
        return;
      }
      setForm({ values: toSettingsFormValues(result), saved: result });
      setIsSaved(true);
    } catch (failure) {
      if (runId !== saveRunIdRef.current) {
        return;
      }
      if (isValidationFailure(failure)) {
        const errors = validationErrorsOfFailure(failure);
        setFieldErrors(settingsValidationErrors(errors));
        setSaveError(settingsBatchError(errors));
      } else {
        // Network, 401/403, 500: the settings were not written, so the form keeps
        // the user's edits and the API's own message is shown.
        setSaveError(messageOfApiError(failure, "Could not save settings."));
      }
    } finally {
      if (runId === saveRunIdRef.current) {
        isSavingRef.current = false;
        setIsSaving(false);
      }
    }
  }, [values, saved]);

  const extras = useMemo(
    () => (values === null ? [] : extraSettingKeys(values, saved)),
    [values, saved],
  );

  // Discards local edits and returns the inputs to the last stored state, which
  // is cheaper than a reload and needs no second request.
  const discardChanges = useCallback(() => {
    if (form === null || isSaving) {
      return;
    }
    setForm({ values: toSettingsFormValues(form.saved), saved: form.saved });
    setIsSaved(false);
    setFieldErrors({});
    setSaveError(null);
  }, [form, isSaving]);

  return (
    <section aria-label="Settings" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <p className="mr-auto text-xs text-muted-foreground" aria-live="polite">
          {isRefreshing
            ? "Refreshing…"
            : isSaved
              ? "Settings saved."
              : isDirty
                ? "Unsaved changes."
                : "Up to date."}
        </p>

        <Button
          type="button"
          variant="outline"
          onClick={refresh}
          disabled={isLoading || isSaving}
        >
          {isRefreshing ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
        </Button>

        {isDirty && (
          <Button
            type="button"
            variant="outline"
            onClick={discardChanges}
            disabled={isSaving}
          >
            Discard
          </Button>
        )}

        <Button type="button" onClick={() => void save()} disabled={isBusy || !isDirty}>
          {isSaving ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <Save aria-hidden="true" />
          )}
          {isSaving ? "Saving…" : "Save changes"}
        </Button>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2"
        >
          <p className="text-xs text-destructive">
            {isForbidden ? "You do not have permission to view settings." : error}
          </p>
          <Button type="button" variant="outline" size="sm" onClick={refresh}>
            Try again
          </Button>
        </div>
      )}

      {saveError !== null && (
        <p role="alert" className="text-xs text-destructive">
          {saveError}
        </p>
      )}

      {isLoading && values === null ? (
        <LoadingState />
      ) : values === null ? null : (
        <form
          className="flex min-w-0 flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault();
            void save();
          }}
        >
          {SETTING_GROUPS.map((group) => (
            <fieldset
              key={group.title}
              className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5"
              disabled={isBusy}
            >
              <legend className="px-1">
                <span className="font-heading text-sm font-semibold tracking-tight">
                  {group.title}
                </span>
                <span className="block text-xs font-normal text-muted-foreground">
                  {group.description}
                </span>
              </legend>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {group.fields.map((field) => (
                  <SettingInput
                    key={field.key}
                    field={field}
                    value={values[field.key] ?? ""}
                    error={fieldErrors[field.key]}
                    disabled={isBusy}
                    onChange={(next) => setValue(field.key, next)}
                  />
                ))}
              </div>
            </fieldset>
          ))}

          {extras.length > 0 && (
            <fieldset
              className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5"
              disabled={isBusy}
            >
              <legend className="px-1">
                <span className="font-heading text-sm font-semibold tracking-tight">
                  Other settings
                </span>
                <span className="block text-xs font-normal text-muted-foreground">
                  Keys stored for this business that this screen does not present a
                  dedicated field for. They are still saved through the same API.
                </span>
              </legend>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {extras.map((key) => (
                  <SettingInput
                    key={key}
                    field={fieldForExtraKey(key)}
                    value={values[key] ?? ""}
                    error={fieldErrors[key]}
                    disabled={isBusy}
                    onChange={(next) => setValue(key, next)}
                  />
                ))}
              </div>
            </fieldset>
          )}

          <AddSettingRow
            newKey={newKey}
            newKeyError={newKeyError}
            disabled={isBusy}
            onNewKeyChange={(next) => {
              setNewKey(next);
              setNewKeyError(null);
            }}
            onAdd={addKey}
          />

          {/* Keeps Enter in any input a submit, without a second visible button. */}
          <button type="submit" className="sr-only" disabled={isBusy || !isDirty}>
            Save changes
          </button>
        </form>
      )}
    </section>
  );
}

/**
 * Adds a new setting key.
 *
 * The backend is a free-form key/value store, so adding a setting means writing a
 * new key — there is no dedicated "create" endpoint and none is needed. The key is
 * checked against the same rule the API applies, so an unusable key is caught
 * before the round trip; the API remains the authority.
 */
function AddSettingRow({
  newKey,
  newKeyError,
  disabled,
  onNewKeyChange,
  onAdd,
}: {
  newKey: string;
  newKeyError: string | null;
  disabled: boolean;
  onNewKeyChange: (value: string) => void;
  onAdd: () => void;
}) {
  return (
    <section className="flex min-w-0 flex-col gap-1.5 rounded-lg border border-dashed border-border p-4">
      <Label htmlFor="setting-new-key">Add a setting</Label>
      <div className="flex flex-wrap items-start gap-2">
        <div className="grid min-w-[12rem] flex-1 gap-1.5">
          <Input
            id="setting-new-key"
            placeholder="receipt_prefix"
            aria-invalid={newKeyError !== null}
            disabled={disabled}
            value={newKey}
            onChange={(event) => onNewKeyChange(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                onAdd();
              }
            }}
          />
          <p className="text-xs text-muted-foreground">
            Letters, numbers, spaces and . _ - :. Stored against this business only.
          </p>
          {newKeyError !== null && (
            <p role="alert" className="text-xs text-destructive">
              {newKeyError}
            </p>
          )}
        </div>
        <Button
          type="button"
          variant="outline"
          onClick={onAdd}
          disabled={disabled || newKey.trim() === ""}
        >
          <Plus aria-hidden="true" />
          Add key
        </Button>
      </div>
    </section>
  );
}

/** First-load placeholder, so no form is rendered before the API answers. */
function LoadingState() {
  return (
    <div role="status" aria-live="polite" className="flex flex-col gap-4">
      {[0, 1, 2].map((index) => (
        <div
          key={index}
          className="h-40 animate-pulse rounded-lg border border-border bg-card"
        />
      ))}
      <span className="sr-only">Loading settings…</span>
    </div>
  );
}

/**
 * `true` when a rejection was a Laravel `422`.
 *
 * Read from the normalised `ApiError` rather than a status code check, so the
 * screen behaves the same as every other form in the app.
 */
function isValidationFailure(error: unknown): boolean {
  return (
    typeof error === "object" &&
    error !== null &&
    "isValidationError" in error &&
    (error as { isValidationError: boolean }).isValidationError
  );
}

/** The raw field-level error bag of a `422`, or an empty bag. */
function validationErrorsOfFailure(error: unknown): ApiValidationErrors {
  if (typeof error === "object" && error !== null && "validationErrors" in error) {
    return (error as { validationErrors: ApiValidationErrors }).validationErrors;
  }
  return {};
}

/** The error message of a proposed new key, or `null` when it is usable. */
function validateNewKey(key: string): string | null {
  // Reuses the form's per-pair rule, which mirrors the API's key checks.
  return validateSettingsForm({ [key]: "" })?.[key] ?? null;
}

