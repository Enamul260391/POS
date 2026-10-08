"use client";

import { useCallback, useEffect, useState } from "react";
import { Building2, Loader2, Plus, RefreshCw, Search, X, Power, RotateCcw } from "lucide-react";
import { useRouter } from "next/navigation";
import type { AppRouterInstance } from "next/dist/shared/lib/app-router-context.shared-runtime";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

import {
  selectPlatformBusinesses,
  selectPlatformBusinessError,
  selectPlatformBusinessLoading,
  selectPlatformBusinessLoaded,
  selectPlatformBusinessPagination,
  usePlatformBusinessStore,
} from "@/stores/usePlatformBusinessStore";
import type { Business, BusinessStatus } from "@/types/platform";
import { BUSINESS_STATUS_OPTIONS } from "@/types/platform";
import { formatLocalDateTime } from "@/lib/datetime";
import { BusinessStatusDialog } from "@/features/platform/components/BusinessStatusDialog";

/** Debounce delay for search input (ms). */
const SEARCH_DEBOUNCE_MS = 300;

/**
 * Platform Business List screen.
 *
 * Server-side pagination, search, and status filter.
 * Uses the same patterns as `UsersScreen`.
 */
export function PlatformBusinessList() {
  const router = useRouter();
  const businesses = usePlatformBusinessStore(selectPlatformBusinesses);
  const pagination = usePlatformBusinessStore(selectPlatformBusinessPagination);
  const isLoading = usePlatformBusinessStore(selectPlatformBusinessLoading);
  const isLoaded = usePlatformBusinessStore(selectPlatformBusinessLoaded);
  const error = usePlatformBusinessStore(selectPlatformBusinessError);
  const load = usePlatformBusinessStore((state) => state.load);

  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<"all" | BusinessStatus | null>("all");

  /** Requests one page with the filters that are active right now. */
  const reload = useCallback((nextPage: number) => {
    void load(
      {
        search: query,
        status: status === "all" || status === null ? undefined : status,
      },
      nextPage,
    );
  }, [load, query, status]);

  // Debounced search/filter effect
  useEffect(() => {
    const handle = setTimeout(() => {
      reload(1);
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [reload]);

  const currentPage = pagination?.current_page ?? 1;
  const lastPage = pagination?.last_page ?? 1;
  const totalCount = pagination?.total ?? 0;
  const canGoBack = currentPage > 1;
  const canGoForward = currentPage < lastPage;
  const firstShown = pagination?.from ?? 0;
  const lastShown = pagination?.to ?? 0;

  return (
    <section aria-label="Platform Businesses" className="flex min-w-0 flex-1 flex-col gap-3">
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
        <Button type="button" onClick={() => router.push("/platform/businesses/create")} disabled={!isLoaded}>
          <Plus aria-hidden="true" />
          Create Business
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
            onClick={() => reload(currentPage)}
          >
            Try again
          </Button>
        </div>
      )}

      {/* Search and Filter Bar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px] max-w-md">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            type="search"
            className="pl-8"
            placeholder="Search businesses…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            aria-label="Search businesses"
          />
          {query && (
            <Button
              type="button"
              variant="ghost"
              size="icon-xs"
              className="absolute right-1.5 top-1/2 -translate-y-1/2"
              onClick={() => setQuery("")}
              aria-label="Clear search"
            >
              <X className="size-3" aria-hidden="true" />
            </Button>
          )}
        </div>

        <Select
          value={status}
          onValueChange={setStatus}
          disabled={isLoading}
        >
          <SelectTrigger className="w-[160px]">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {BUSINESS_STATUS_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="ml-auto text-xs text-muted-foreground">
          {totalCount === 0
            ? "No businesses"
            : `Showing ${firstShown}–${lastShown} of ${totalCount}`}
        </div>
      </div>

      <PlatformBusinessTable
        businesses={businesses}
        isLoading={isLoading}
        router={router}
      />

      {/* Server-side paging */}
      {pagination !== null && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-muted-foreground" aria-live="polite">
            {totalCount === 0
              ? "No businesses"
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
    </section>
  );
}

/**
 * Business table component.
 */
function PlatformBusinessTable({
  businesses,
  isLoading,
  router,
}: {
  businesses: readonly Business[];
  isLoading: boolean;
  router: AppRouterInstance;
}) {
  if (businesses.length === 0) {
    if (isLoading) {
      return (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Business</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Users</TableHead>
                <TableHead>Created</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...Array(5)].map((_, i) => (
                <TableRow key={i} className="animate-pulse">
                  <TableCell>
                    <div className="h-4 w-3/4 bg-muted rounded animate-pulse" />
                  </TableCell>
                  <TableCell>
                    <div className="h-4 w-20 bg-muted rounded animate-pulse" />
                  </TableCell>
                  <TableCell>
                    <div className="h-4 w-12 bg-muted rounded animate-pulse" />
                  </TableCell>
                  <TableCell>
                    <div className="h-4 w-24 bg-muted rounded animate-pulse" />
                  </TableCell>
                  <TableCell>
                    <div className="h-4 w-16 bg-muted rounded animate-pulse" />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      );
    }

    return (
      <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
        <Building2 className="size-8 text-muted-foreground" aria-hidden="true" />
        <p className="text-sm font-medium">No businesses found</p>
        <p className="text-xs text-muted-foreground">
          Try a different search or filter, or create a new business.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="min-w-[1000px]">
        <TableHeader>
          <TableRow>
            <TableHead>Business</TableHead>
            <TableHead>Contact</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Users</TableHead>
            <TableHead>Created</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {businesses.map((business) => (
            <BusinessRow key={business.id} business={business} router={router} />
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/**
 * Single business row with actions.
 */
function BusinessRow({
  business,
  router,
}: {
  business: Business;
  router: AppRouterInstance;
}) {
  const [showStatusDialog, setShowStatusDialog] = useState(false);
  const targetStatus = business.status === "active" ? "inactive" : "active";
  const ActionIcon = business.status === "active" ? Power : RotateCcw;
  const actionLabel = business.status === "active" ? "Deactivate" : "Activate";

  return (
    <TableRow>
      <TableCell className="max-w-48">
        <span className="block truncate font-medium" title={business.name}>
          {business.name}
        </span>
      </TableCell>
      <TableCell className="max-w-56">
        <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
          {business.email && (
            <span className="truncate" title={business.email}>{business.email}</span>
          )}
          {business.phone && (
            <span className="truncate" title={business.phone}>{business.phone}</span>
          )}
          {!business.email && !business.phone && <span>—</span>}
        </div>
      </TableCell>
      <TableCell>
        <span
          className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
            business.status === "active"
              ? "bg-green-100 text-green-800"
              : "bg-gray-100 text-gray-800"
          }`}
        >
          {business.status}
        </span>
      </TableCell>
      <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
        {business.usersCount ?? "—"}
      </TableCell>
      <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
        {formatLocalDateTime(business.createdAt)}
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => router.push(`/platform/businesses/${business.id}`)}
            aria-label={`View ${business.name}`}
          >
            <Building2 className="size-4" aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => router.push(`/platform/businesses/${business.id}/edit`)}
            aria-label={`Edit ${business.name}`}
          >
            <svg className="size-4" aria-hidden="true"><use href="#pencil" /></svg>
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => setShowStatusDialog(true)}
            aria-label={`${actionLabel} ${business.name}`}
          >
            <ActionIcon className="size-4" aria-hidden="true" />
          </Button>
          <BusinessStatusDialog
            business={business}
            targetStatus={targetStatus}
            open={showStatusDialog}
            onOpenChange={setShowStatusDialog}
          />
        </div>
      </TableCell>
    </TableRow>
  );
}