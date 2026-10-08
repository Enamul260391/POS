"use client";

import React from "react";
import { Building2, RefreshCw, Users } from "lucide-react";

import { Button } from "@/components/ui/button";
import { formatLocalDateTime } from "@/lib/datetime";

import {
  selectPlatformBusinesses,
  selectPlatformBusinessError,
  selectPlatformBusinessLoading,
  selectPlatformBusinessLoaded,
  usePlatformBusinessStore,
} from "@/stores/usePlatformBusinessStore";

/**
 * Platform Owner dashboard.
 *
 * Shows platform-level business metrics and recent businesses.
 * Data comes from the existing `GET /platform/businesses` API.
 */
export function PlatformDashboard() {
  const businesses = usePlatformBusinessStore(selectPlatformBusinesses);
  const pagination = usePlatformBusinessStore((state) => state.pagination);
  const isLoading = usePlatformBusinessStore(selectPlatformBusinessLoading);
  const error = usePlatformBusinessStore(selectPlatformBusinessError);
  const isLoaded = usePlatformBusinessStore(selectPlatformBusinessLoaded);
  const load = usePlatformBusinessStore((state) => state.load);

  React.useEffect(() => {
    void load();
  }, [load]);

  const totalBusinesses = pagination?.total ?? 0;
  const activeBusinesses = businesses.filter((b) => b.status === "active").length;
  const inactiveBusinesses = businesses.filter((b) => b.status === "inactive").length;
  const recentBusinesses = [...businesses]
    .sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
    .slice(0, 5);

  if (!isLoaded && isLoading) {
    return (
      <div className="flex flex-1 items-center justify-center p-6" role="status" aria-live="polite">
        <div className="flex flex-col items-center gap-2">
          <div className="animate-spin rounded-full h-8 w-8 border-2 border-primary border-t-transparent" />
          <p className="text-sm text-muted-foreground">Loading platform dashboard…</p>
        </div>
      </div>
    );
  }

  if (!isLoaded && error) {
    return (
      <div className="flex flex-1 items-center justify-center p-6">
        <div className="text-center">
          <p className="text-sm text-destructive" role="alert">Unable to load dashboard</p>
          <p className="text-xs text-muted-foreground mt-1">{error}</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-3"
            onClick={() => void load()}
          >
            <RefreshCw className="size-3 mr-1.5" aria-hidden="true" />
            Try again
          </Button>
        </div>
      </div>
    );
  }

  return (
    <section aria-label="Platform Dashboard" className="flex min-w-0 flex-1 flex-col gap-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Platform Dashboard</h1>
          <p className="text-sm text-muted-foreground">Overview of all businesses on the platform</p>
        </div>
        <Button
          type="button"
          variant="outline"
          onClick={() => void load()}
          disabled={isLoading}
        >
          {isLoading ? (
            <RefreshCw className="animate-spin" aria-hidden="true" />
          ) : (
            <RefreshCw aria-hidden="true" />
          )}
          Refresh
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
            onClick={() => void load()}
          >
            Try again
          </Button>
        </div>
      )}

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <KpiCard
          title="Total Businesses"
          value={totalBusinesses}
          subtitle={totalBusinesses === 1 ? "business" : "businesses"} registered
          iconNode={() => <Building2 className="size-4" aria-hidden="true" />}
        />
        <KpiCard
          title="Active"
          value={activeBusinesses}
          subtitle="Currently active businesses"
          iconNode={() => <Building2 className="size-4 text-green-500" aria-hidden="true" />}
          valueClassName="text-green-600"
        />
        <KpiCard
          title="Inactive"
          value={inactiveBusinesses}
          subtitle="Currently inactive businesses"
          iconNode={() => <Building2 className="size-4 text-gray-500" aria-hidden="true" />}
          valueClassName="text-gray-600"
        />
      </div>

      {/* Recent Businesses */}
      <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
        <header className="flex min-w-0 items-start justify-between gap-2">
          <div className="min-w-0">
            <h2 className="font-heading text-sm font-semibold tracking-tight flex items-center gap-2">
              <Users className="size-4" aria-hidden="true" />
              Recent Businesses
            </h2>
          </div>
        </header>
        {recentBusinesses.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-6 py-12 text-center">
            <Building2 className="size-8 text-muted-foreground" aria-hidden="true" />
            <p className="text-sm font-medium">No businesses found</p>
            <p className="text-xs text-muted-foreground">Create your first business to get started.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border">
                  <th className="text-left py-2 font-medium text-muted-foreground">Business</th>
                  <th className="text-left py-2 font-medium text-muted-foreground">Status</th>
                  <th className="text-left py-2 font-medium text-muted-foreground">Users</th>
                  <th className="text-left py-2 font-medium text-muted-foreground">Created</th>
                </tr>
              </thead>
              <tbody>
                {recentBusinesses.map((business) => (
                  <tr key={business.id} className="border-b border-border/50 last:border-0">
                    <td className="py-2 font-medium">{business.name}</td>
                    <td className="py-2">
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                          business.status === "active"
                            ? "bg-green-100 text-green-800"
                            : "bg-gray-100 text-gray-800"
                        }`}
                      >
                        {business.status}
                      </span>
                    </td>
                    <td className="py-2 text-muted-foreground">
                      {business.usersCount ?? "—"}
                    </td>
                    <td className="py-2 text-muted-foreground whitespace-nowrap">
                      {formatLocalDateTime(business.createdAt)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </section>
  );
}

interface KpiCardProps {
  title: string;
  value: number;
  subtitle: string;
  iconNode: () => React.ReactElement;
  valueClassName?: string;
  registered?: boolean;
}

function KpiCard({ title, value, subtitle, iconNode, valueClassName = "" }: KpiCardProps) {
  const IconElement = iconNode();
  return (
    <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
      <header className="flex min-w-0 items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="font-heading text-sm font-semibold tracking-tight">{title}</h2>
        </div>
        <div className="shrink-0 flex size-8 items-center justify-center rounded-lg bg-muted">
          {IconElement}
        </div>
      </header>
      <div className={`text-2xl font-bold ${valueClassName}`}>{value}</div>
      <p className="text-xs text-muted-foreground">{subtitle}</p>
    </section>
  );
}