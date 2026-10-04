"use client";

import { useMemo, type ReactNode } from "react";
import { Loader2 } from "lucide-react";

import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { permissionsByModule } from "@/features/users/lib/userPermissionModules";
import {
  PERMISSION_MODE_OPTIONS,
  type PermissionMode,
  type PermissionSelection,
} from "@/features/users/lib/userPermissions";
import { selectPermissions, useRoleStore } from "@/stores/useRoleStore";
import { cn } from "cn";

interface UserPermissionEditorProps {
  mode: PermissionMode;
  onModeChange: (mode: PermissionMode) => void;
  /** Capabilities ticked so far, keyed by catalogue permission id. */
  selected: PermissionSelection;
  onSelectedChange: (selected: PermissionSelection) => void;
  /**
   * What the selected role grants, or `null` while that is unknown.
   *
   * `null` means "not yet", never "nothing": until it resolves the list is
   * disabled, because a tick made against an unread baseline would silently
   * diverge from the one a save diffs against.
   */
  grantedByRole: ReadonlySet<number> | null;
  /**
   * Whether a role is selected at all.
   *
   * Drives the mode radio group only. Permission mode is a decision *about* a
   * role, so with no role neither option can be chosen — the group stays on
   * screen (so the field does not appear and vanish as the picker changes) but
   * is disabled, and no customisation is submitted.
   */
  hasRole: boolean;
}

/**
 * Ticks or unticks every id in `ids`, leaving the rest of the selection alone.
 *
 * The module checkbox and a single child row both go through this, which is what
 * guarantees they write the same state: a module toggle and its children can
 * never disagree about what "selected" means.
 */
function withIds(
  selection: PermissionSelection,
  ids: readonly number[],
  isSelected: boolean,
): PermissionSelection {
  const next = new Set(selection);
  for (const id of ids) {
    if (isSelected) {
      next.add(id);
    } else {
      next.delete(id);
    }
  }
  return next;
}

/** How many of `ids` are ticked, and whether all of them are. */
function countSelected(
  selection: PermissionSelection,
  ids: readonly number[],
): { selected: number; all: boolean } {
  let selected = 0;
  for (const id of ids) {
    if (selection.has(id)) {
      selected += 1;
    }
  }
  return { selected, all: selected === ids.length };
}

interface PermissionModuleRowProps {
  label: string;
  /** Every capability this row stands for — what its checkbox toggles. */
  permissionIds: readonly number[];
  selection: PermissionSelection;
  onSelectionChange: (selection: PermissionSelection) => void;
  disabled: boolean;
  /**
   * Child rows. Empty for a single-capability module, whose checkbox *is* that
   * capability and would only be repeated by a row of its own.
   */
  children?: ReactNode;
}

/** One child capability under a module. */
function PermissionChildRow({
  label,
  permissionIds,
  selection,
  onSelectionChange,
  disabled,
}: PermissionModuleRowProps) {
  const { selected, all } = countSelected(selection, permissionIds);

  return (
    <label className="flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground">
      <Checkbox
        checked={all}
        indeterminate={!all && selected > 0}
        disabled={disabled}
        onCheckedChange={() =>
          onSelectionChange(withIds(selection, permissionIds, !all))
        }
      />
      <span>{label}</span>
    </label>
  );
}

/**
 * One module: a parent checkbox and its indented children.
 *
 * The parent never locks the children. It is a bulk control that mirrors their
 * combined state — fully ticked when all are ticked, partial (`indeterminate`)
 * when some are, empty when none are — and clicking it selects or clears the whole
 * module. An individual child can always be unticked afterwards, which moves the
 * parent to the partial state; that is the intended workflow, not an edge case.
 */
function PermissionModuleRow({
  label,
  permissionIds,
  selection,
  onSelectionChange,
  disabled,
  children,
}: PermissionModuleRowProps) {
  const { all } = countSelected(selection, permissionIds);
  const isPartial = !all && permissionIds.some((id) => selection.has(id));

  return (
    <div className="space-y-1.5">
      <label className="flex items-center gap-2 text-sm font-medium">
        <Checkbox
          checked={all}
          indeterminate={isPartial}
          disabled={disabled}
          onCheckedChange={() =>
            onSelectionChange(withIds(selection, permissionIds, !all))
          }
        />
        <span>{label}</span>
      </label>

      {children !== undefined && children !== null && (
        <div className="flex flex-wrap gap-x-4 gap-y-1.5 pl-6">{children}</div>
      )}
    </div>
  );
}

/**
 * Permission mode + per-user permission editor for the Add user dialog.
 *
 * The rows are the API's own catalogue: `GET /permissions` through
 * `useRoleStore`, arranged by `@/features/users/lib/userPermissionModules`. The
 * real ids and slugs are used throughout, so no id can be submitted that the API
 * never issued.
 *
 * The selected role is the *baseline*: `UserForm` reads `GET /roles/{id}` and
 * starts the checkboxes from the role's effective permissions, so the
 * administrator never has to re-tick them. This component is presentation plus
 * local toggle logic only — it writes nothing, and the overrides a save produces
 * apply to the single user being created. The role itself, and every other user
 * holding it, are untouched.
 *
 * In `role` mode nothing renders below the radio group and no override is ever
 * submitted, so an account created that way behaves exactly as it did before
 * this section existed.
 */
export function UserPermissionEditor({
  mode,
  onModeChange,
  selected,
  onSelectedChange,
  grantedByRole,
  hasRole,
}: UserPermissionEditorProps) {
  const permissions = useRoleStore(selectPermissions);
  const isLoadingPermissions = useRoleStore(
    (state) => state.isLoadingPermissions,
  );

  // Memoised on the catalogue so the module rows are not rebuilt per keystroke.
  const modules = useMemo(() => permissionsByModule(permissions), [permissions]);

  // Until the role's grants are known, ticking a box would silently diverge from
  // the baseline a save diffs against, so the list waits rather than guesses.
  // This is also what holds the list still while a *new* role is being read:
  // `grantedByRole` is `null` until the new role's own permissions arrive, so the
  // previous role's ticks can never be edited against.
  const isDisabled = grantedByRole === null;

  return (
    <div className="grid gap-2 sm:col-span-2">
      <div className="grid gap-1.5">
        <Label htmlFor="permissionMode">Permission mode</Label>
        <RadioGroup
          id="permissionMode"
          value={mode}
          disabled={!hasRole}
          onValueChange={(value: PermissionMode) => onModeChange(value)}
          className="gap-1.5"
        >
          {PERMISSION_MODE_OPTIONS.map((option) => (
            <label
              key={option.value}
              htmlFor={`permissionMode-${option.value}`}
              className={cn(
                "flex items-start gap-2 rounded-lg border border-input p-2.5 transition-colors",
                hasRole
                  ? "cursor-pointer hover:bg-muted/50 has-[[data-checked]]:border-ring has-[[data-checked]]:bg-muted/50"
                  : "cursor-not-allowed bg-muted/30 opacity-60",
              )}
            >
              <RadioGroupItem
                id={`permissionMode-${option.value}`}
                value={option.value}
                className="mt-0.5"
              />
              <span className="grid gap-0.5">
                <span className="text-sm font-medium">{option.label}</span>
                <span className="text-xs text-muted-foreground">
                  {option.description}
                </span>
              </span>
            </label>
          ))}
        </RadioGroup>
        {/* Explains the disabled state instead of leaving the administrator to
            work out why the only new field in the form cannot be touched. */}
        {!hasRole && (
          <p className="text-xs text-muted-foreground">
            Choose a role to set permissions for this user.
          </p>
        )}
      </div>

      {mode === "customize" && hasRole && (
        <div className="grid gap-2">
          <div className="grid gap-1">
            <p className="text-sm font-medium">Permissions</p>
            <p className="text-xs text-muted-foreground">
              Changes apply to this user only — the role is left untouched and no
              other user is affected.
            </p>
          </div>

          {isLoadingPermissions && permissions.length === 0 ? (
            <p className="flex items-center gap-2 text-xs text-muted-foreground">
              <Loader2 className="animate-spin" aria-hidden="true" />
              Loading permissions…
            </p>
          ) : permissions.length === 0 ? (
            <p className="text-xs text-muted-foreground">
              The permission catalogue could not be loaded. The user can still be
              created, but no permissions can be set.
            </p>
          ) : (
            <>
              {/* While a role is being read the count is deliberately withheld:
                  it would otherwise report the *previous* role's ticks, and the
                  spinner says the list is being rebuilt rather than broken. */}
              <p
                className="flex items-center gap-1.5 text-xs text-muted-foreground"
                aria-live="polite"
              >
                {isDisabled ? (
                  <>
                    <Loader2 className="animate-spin" aria-hidden="true" />
                    Loading permissions for the selected role…
                  </>
                ) : (
                  `${selected.size} of ${permissions.length} permissions selected.`
                )}
              </p>
              {/* No height cap of its own: the form body is already the dialog's
                  single scroll region, and a second scroller inside it would
                  trap the wheel. */}
              <div className="space-y-4 pr-1">
                {modules.map((module) => (
                  <PermissionModuleRow
                    key={module.id}
                    label={module.label}
                    permissionIds={module.permissionIds}
                    selection={selected}
                    onSelectionChange={onSelectedChange}
                    disabled={isDisabled}
                  >
                    {/* A single-capability module passes no children, so its
                        header checkbox is the capability itself. */}
                    {module.children.map((child) => (
                      <PermissionChildRow
                        key={child.id}
                        label={child.label}
                        permissionIds={child.permissionIds}
                        selection={selected}
                        onSelectionChange={onSelectedChange}
                        disabled={isDisabled}
                      />
                    ))}
                  </PermissionModuleRow>
                ))}
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}
