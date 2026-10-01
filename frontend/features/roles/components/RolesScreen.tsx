"use client";

import { useCallback, useEffect, useState } from "react";
import { Loader2, Plus, RefreshCw, Search } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { RoleForm, type RoleFormTarget } from "@/features/roles/components/RoleForm";
import { RolesTable } from "@/features/roles/components/RolesTable";
import { roleLabel } from "@/features/roles/lib/roles";
import { messageOfApiError } from "@/lib/api/validation";
import {
  selectPermissions,
  selectRoles,
  useRoleStore,
} from "@/stores/useRoleStore";
import type { Role } from "@/types/role";

/** Debounce for the search box, so typing does not fire a request per keystroke. */
const SEARCH_DEBOUNCE_MS = 300;

/**
 * `/roles` screen — composition only.
 *
 * Owns the local filter state, the current page and which dialog (if any) is
 * mounted. The catalogue comes from `useRoleStore`, hydrated from `GET /roles`,
 * and the permission matrix is driven by `GET /permissions` — the real
 * catalogue rather than a hardcoded list, so what is granted here is exactly
 * what the backend authorises on.
 *
 * Filtering and pagination are server-side, which is why the screen gained the
 * loading and error states an API-backed view needs; the "Users" column is the
 * API's own `users_count`.
 */
export function RolesScreen() {
  const roles = useRoleStore(selectRoles);
  const permissions = useRoleStore(selectPermissions);
  const isLoading = useRoleStore((state) => state.isLoading);
  const isLoaded = useRoleStore((state) => state.isLoaded);
  const error = useRoleStore((state) => state.error);
  const pagination = useRoleStore((state) => state.pagination);
  const load = useRoleStore((state) => state.load);
  const loadPermissions = useRoleStore((state) => state.loadPermissions);

  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [formTarget, setFormTarget] = useState<RoleFormTarget | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Role | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // The matrix needs the global catalogue as well as the role list.
  useEffect(() => {
    void loadPermissions();
  }, [loadPermissions]);

  const reload = useCallback(
    (nextPage: number) => {
      void load({ search: query.trim() || undefined }, nextPage);
    },
    [load, query],
  );

  // One request per filter change. The term is debounced so typing does not
  // fire a request per keystroke, and any change restarts at page 1 because the
  // requested page of the previous result set no longer means anything.
  useEffect(() => {
    const handle = setTimeout(() => {
      void load({ search: query.trim() || undefined }, 1);
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(handle);
  }, [load, query]);

  async function confirmDelete() {
    if (deleteTarget === null || isDeleting) {
      return;
    }
    setIsDeleting(true);
    setDeleteError(null);
    try {
      await useRoleStore.getState().removeRole(deleteTarget.id);
      setDeleteTarget(null);
      // `users_count` and the totals only refresh on a reload.
      reload(page);
    } catch (failure) {
      // A `409` lands here when a user was assigned between the render and the
      // click; the API's own wording explains why.
      setDeleteError(
        messageOfApiError(failure, "Could not delete the role."),
      );
    } finally {
      setIsDeleting(false);
    }
  }

  function goToPage(next: number) {
    setPage(next);
    reload(next);
  }

  const canGoBack = pagination !== null && pagination.current_page > 1;
  const canGoForward =
    pagination !== null && pagination.current_page < pagination.last_page;

  return (
    <section aria-label="Roles" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => reload(page)}
          disabled={isLoading}
        >
          {isLoading ? (
            <Loader2 className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
        </Button>
        <Button
          type="button"
          onClick={() => setFormTarget({ mode: "create" })}
          disabled={!isLoaded}
        >
          <Plus aria-hidden="true" />
          Create role
        </Button>
      </div>

      {error !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{error}</span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => reload(page)}
          >
            Try again
          </Button>
        </div>
      )}

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <div className="relative min-w-0 flex-1">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            type="search"
            className="pl-8"
            placeholder="Search name, slug or description…"
            aria-label="Search roles"
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
              setPage(1);
            }}
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                setQuery("");
              }
            }}
          />
        </div>
        <p className="shrink-0 text-xs text-muted-foreground sm:ml-auto">
          {roles.length} of {pagination?.total ?? 0} roles
        </p>
      </div>

      <RolesTable
        roles={roles}
        totalPermissions={permissions.length}
        onEdit={(role) => setFormTarget({ mode: "edit", role })}
        onDelete={setDeleteTarget}
      />

      {pagination !== null && pagination.last_page > 1 && (
        <div className="flex items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canGoBack || isLoading}
            onClick={() => goToPage(pagination.current_page - 1)}
          >
            Previous
          </Button>
          <p className="text-xs text-muted-foreground" aria-live="polite">
            Page {pagination.current_page} of {pagination.last_page}
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canGoForward || isLoading}
            onClick={() => goToPage(pagination.current_page + 1)}
          >
            Next
          </Button>
        </div>
      )}

      {/* Dialogs mount only while open: every open starts from fresh state. */}
      {formTarget !== null && (
        <RoleForm
          target={formTarget}
          onOpenChange={(open) => {
            if (!open) {
              setFormTarget(null);
            }
          }}
        />
      )}

      {deleteTarget !== null && (
        <div
          role="alertdialog"
          aria-label="Delete role"
          className="rounded-lg border border-destructive/40 bg-card px-4 py-3"
        >
          <p className="text-sm font-medium">
            Delete {roleLabel(deleteTarget)}?
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            This removes the role and its permission grants permanently. A role
            assigned to a user cannot be deleted — remove the assignment first.
          </p>
          {deleteError !== null && (
            <p role="alert" className="mt-2 text-xs text-destructive">
              {deleteError}
            </p>
          )}
          <div className="mt-3 flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={isDeleting}
              onClick={() => setDeleteTarget(null)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={isDeleting}
              onClick={confirmDelete}
            >
              {isDeleting ? "Deleting…" : "Delete role"}
            </Button>
          </div>
        </div>
      )}
    </section>
  );
}
