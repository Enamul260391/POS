"use client";

import { useEffect, useState } from "react";
import { Loader2, Plus, RefreshCw } from "lucide-react";

import { Button } from "@/components/ui/button";
import { UserDetails } from "@/features/users/components/UserDetails";
import { UserForm, type UserFormTarget } from "@/features/users/components/UserForm";
import { UserSearch } from "@/features/users/components/UserSearch";
import { UserTable } from "@/features/users/components/UserTable";
import {
  selectDirectoryPagination,
  selectDirectoryUsers,
  useUserDirectoryStore,
} from "@/stores/useUserDirectoryStore";
import type { User, UserStatus } from "@/types/user";

/**
 * `/users` screen — composition only.
 *
 * Owns the local filter state and which dialog (if any) is mounted. The
 * directory itself comes from `useUserDirectoryStore`, hydrated from
 * `GET /users`; no mock user data is involved anywhere in this screen.
 *
 * Filtering is server-side: the search term is debounced and then sent to the
 * API together with the status and role filters, so the table never has to hold
 * the whole staff list. The screen therefore gained the loading and error
 * states an API-backed view needs.
 *
 * The "N of M users" counter splits the two counts deliberately: `N` is the
 * rows on screen (one server page), while `M` is the API's own `pagination.total`
 * for the active filters. Deriving `M` from the page length would report "20 of
 * 20" for any business with more than one page of staff.
 *
 * Pagination stays server-side. The screen never owns a page number: the current
 * page, the last page, the total and the page size all come from the API's
 * pagination block, so the controls can never drift from what the backend
 * actually returned. A new search term or filter restarts at page 1, because the
 * page the user was on belongs to the previous result set.
 */
export function UsersScreen() {
  const users = useUserDirectoryStore(selectDirectoryUsers);
  const pagination = useUserDirectoryStore(selectDirectoryPagination);
  const isLoading = useUserDirectoryStore((state) => state.isLoading);
  const isLoaded = useUserDirectoryStore((state) => state.isLoaded);
  // The two errors are read separately because the two requests are gated on
  // different capabilities. A refused role catalogue (`roles.view`) is not a
  // failure of this screen: the banner belongs to the user list alone, so it can
  // no longer sit on top of a directory that loaded correctly. A refused
  // catalogue shows up as the role filter and picker simply being absent (both
  // render only when roles exist), not as a screen-level error.
  const usersError = useUserDirectoryStore((state) => state.usersError);
  const load = useUserDirectoryStore((state) => state.load);
  const loadRoles = useUserDirectoryStore((state) => state.loadRoles);

  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<"all" | UserStatus>("all");
  const [roleId, setRoleId] = useState<number | null>(null);
  const [formTarget, setFormTarget] = useState<UserFormTarget | null>(null);
  const [detailsTarget, setDetailsTarget] = useState<User | null>(null);

  // The role picker and the role filter both need the real catalogue, so it is
  // fetched once for the screen rather than per dialog open.
  useEffect(() => {
    void loadRoles();
  }, [loadRoles]);

  /** Requests one page with the filters that are active right now. */
  function reload(nextPage: number) {
    void load(
      {
        search: query,
        status: status === "all" ? undefined : status,
        roleId: roleId ?? undefined,
      },
      nextPage,
    );
  }

  // One request per filter change, always from page 1 — the page the user was on
  // belongs to the previous result set. The term is debounced so typing does not
  // fire a request per keystroke.
  useEffect(() => {
    const handle = setTimeout(() => {
      reload(1);
    }, 300);
    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [load, query, status, roleId]);

  // The API's pagination block is the only source of the paging state: the
  // buttons, the page counter and the "showing X–Y of Z" range all read it, so
  // they can never disagree with the rows the backend actually returned.
  const currentPage = pagination?.current_page ?? 1;
  const lastPage = pagination?.last_page ?? 1;
  const totalCount = pagination?.total ?? 0;
  const canGoBack = currentPage > 1;
  const canGoForward = currentPage < lastPage;
  const firstShown = pagination?.from ?? 0;
  const lastShown = pagination?.to ?? 0;

  return (
    <section aria-label="Users" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => reload(currentPage)}
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
          onClick={() => setFormTarget({ mode: "add" })}
          disabled={!isLoaded}
        >
          <Plus aria-hidden="true" />
          Add user
        </Button>
      </div>

      {usersError !== null && (
        <div
          role="alert"
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          <span>{usersError}</span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => reload(currentPage)}
          >
            Try again
          </Button>
        </div>
      )}

      <UserSearch
        query={query}
        onQueryChange={setQuery}
        status={status}
        onStatusChange={setStatus}
        roleId={roleId}
        onRoleIdChange={setRoleId}
        shownCount={users.length}
        totalCount={totalCount}
        isLoading={isLoading}
      />

      <UserTable
        users={users}
        onView={setDetailsTarget}
        onEdit={(user) => setFormTarget({ mode: "edit", user })}
      />

      {/* Server-side paging: the row reports the API's own range and page count,
          so a directory larger than one page is reachable without ever holding
          the whole list in the browser. */}
      {pagination !== null && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-muted-foreground" aria-live="polite">
            {totalCount === 0
              ? "No users"
              : `Showing ${firstShown}–${lastShown} of ${totalCount}`}
          </p>
          {lastPage > 1 && (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!canGoBack || isLoading}
                onClick={() => reload(currentPage - 1)}
              >
                Previous
              </Button>
              <p className="text-xs text-muted-foreground" aria-live="polite">
                Page {currentPage} of {lastPage}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!canGoForward || isLoading}
                onClick={() => reload(currentPage + 1)}
              >
                Next
              </Button>
            </div>
          )}
        </div>
      )}

      {/* Dialogs mount only while open: every open starts from fresh state. */}
      {formTarget !== null && (
        <UserForm
          target={formTarget}
          onOpenChange={(open) => {
            if (!open) {
              setFormTarget(null);
            }
          }}
        />
      )}
      {detailsTarget !== null && (
        <UserDetails
          user={detailsTarget}
          onOpenChange={(open) => {
            if (!open) {
              setDetailsTarget(null);
            }
          }}
        />
      )}
    </section>
  );
}
