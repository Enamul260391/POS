"use client";

import { useEffect, useState } from "react";
import { Building2, Mail, MapPin, Phone, RefreshCw } from "lucide-react";
import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import { formatLocalDateTime } from "@/lib/datetime";

import { getBusiness } from "@/features/platform/services/platformBusinessService";
import type { Business } from "@/types/platform";

interface BusinessDetailsPageProps {
  params: Promise<{ businessId: string }>;
}

/**
 * `/platform/businesses/:businessId` — Business details.
 *
 * Fetches business data from the API on mount.
 */
export default function PlatformBusinessDetailsPage({ params }: BusinessDetailsPageProps) {
  const router = useRouter();
  const [resolvedParams, setResolvedParams] = useState<{ businessId: string } | null>(null);

  useEffect(() => {
    params.then(setResolvedParams);
  }, [params]);

  const id = resolvedParams ? Number(resolvedParams.businessId) : 0;

  const [business, setBusiness] = useState<Business | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!resolvedParams) return;

    let cancelled = false;

    async function load() {
      setIsLoading(true);
      setError(null);
      try {
        const data = await getBusiness(id);
        if (!cancelled) {
          setBusiness(data);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load business");
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    load();
    return () => {
      cancelled = true;
    };
  }, [id, resolvedParams]);

  if (isLoading) {
    return (
      <div className="flex flex-1 items-center justify-center p-6" role="status" aria-live="polite">
        <div className="flex flex-col items-center gap-2">
          <div className="animate-spin rounded-full h-8 w-8 border-2 border-primary border-t-transparent" />
          <p className="text-sm text-muted-foreground">Loading business…</p>
        </div>
      </div>
    );
  }

  if (error || !business) {
    return (
      <div className="flex flex-1 items-center justify-center p-6">
        <div className="text-center">
          <p className="text-sm text-destructive" role="alert">Unable to load business</p>
          {error && <p className="text-xs text-muted-foreground mt-1">{error}</p>}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="mt-3"
            onClick={() => router.refresh()}
          >
            <RefreshCw className="size-3 mr-1.5" aria-hidden="true" />
            Try again
          </Button>
        </div>
      </div>
    );
  }

  return (
    <section aria-label="Business Details" className="flex min-w-0 flex-1 flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{business.name}</h1>
          <p className="text-sm text-muted-foreground">Business ID: {business.id}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => router.push(`/platform/businesses/${business.id}/edit`)}>
            Edit
          </Button>
          <Button variant="outline" onClick={() => router.push("/platform/businesses")}>
            Back to list
          </Button>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {/* Identity */}
        <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
          <header className="flex min-w-0 items-start justify-between gap-2">
            <div className="min-w-0">
              <h2 className="font-heading text-sm font-semibold tracking-tight flex items-center gap-2">
                <Building2 className="size-4" aria-hidden="true" />
                Identity
              </h2>
            </div>
          </header>
          <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
            <dt className="text-muted-foreground">Name</dt>
            <dd className="font-medium">{business.name}</dd>
            <dt className="text-muted-foreground">ID</dt>
            <dd className="font-medium">{business.id}</dd>
            <dt className="text-muted-foreground">Status</dt>
            <dd>
              <span
                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                  business.status === "active"
                    ? "bg-green-100 text-green-800"
                    : "bg-gray-100 text-gray-800"
                }`}
              >
                {business.status}
              </span>
            </dd>
            <dt className="text-muted-foreground">Users</dt>
            <dd className="font-medium">{business.usersCount ?? "—"}</dd>
          </dl>
        </section>

        {/* Contact */}
        <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
          <header className="flex min-w-0 items-start justify-between gap-2">
            <div className="min-w-0">
              <h2 className="font-heading text-sm font-semibold tracking-tight flex items-center gap-2">
                <Mail className="size-4" aria-hidden="true" />
                Contact
              </h2>
            </div>
          </header>
          <div className="space-y-2">
            {business.email && (
              <div className="flex items-center gap-2 text-sm">
                <Mail className="size-4 text-muted-foreground shrink-0" aria-hidden="true" />
                <a href={`mailto:${business.email}`} className="text-primary hover:underline">
                  {business.email}
                </a>
              </div>
            )}
            {business.phone && (
              <div className="flex items-center gap-2 text-sm">
                <Phone className="size-4 text-muted-foreground shrink-0" aria-hidden="true" />
                <a href={`tel:${business.phone}`} className="text-primary hover:underline">
                  {business.phone}
                </a>
              </div>
            )}
            {business.address && (
              <div className="flex items-start gap-2 text-sm">
                <MapPin className="size-4 text-muted-foreground shrink-0 mt-0.5" aria-hidden="true" />
                <address className="not-italic text-muted-foreground">{business.address}</address>
              </div>
            )}
            {business.logo && (
              <div className="flex items-center gap-2 text-sm">
                <Building2 className="size-4 text-muted-foreground shrink-0" aria-hidden="true" />
                <a href={business.logo} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                  View logo
                </a>
              </div>
            )}
            {!business.email && !business.phone && !business.address && !business.logo && (
              <p className="text-sm text-muted-foreground">No contact information provided</p>
            )}
          </div>
        </section>

        {/* Locale */}
        <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
          <header className="flex min-w-0 items-start justify-between gap-2">
            <div className="min-w-0">
              <h2 className="font-heading text-sm font-semibold tracking-tight">Locale</h2>
            </div>
          </header>
          <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
            <dt className="text-muted-foreground">Currency</dt>
            <dd className="font-medium">{business.currency ?? "—"}</dd>
            <dt className="text-muted-foreground">Timezone</dt>
            <dd className="font-medium">{business.timezone ?? "—"}</dd>
          </dl>
        </section>
      </div>

      {/* Timestamps */}
      <section className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:p-5">
        <header className="flex min-w-0 items-start justify-between gap-2">
          <div className="min-w-0">
            <h2 className="font-heading text-sm font-semibold tracking-tight">Timestamps</h2>
          </div>
        </header>
        <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
          <dt className="text-muted-foreground">Created</dt>
          <dd className="whitespace-nowrap font-medium">{formatLocalDateTime(business.createdAt)}</dd>
          <dt className="text-muted-foreground">Updated</dt>
          <dd className="whitespace-nowrap font-medium">{formatLocalDateTime(business.updatedAt)}</dd>
        </dl>
      </section>
    </section>
  );
}