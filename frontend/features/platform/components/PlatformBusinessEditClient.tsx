"use client";

import { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import { BusinessForm, type BusinessFormTarget } from "@/features/platform/components/BusinessForm";
import { getBusiness } from "@/features/platform/services/platformBusinessService";
import type { Business } from "@/types/platform";

interface PlatformBusinessEditClientProps {
  businessId: number;
}

/**
 * Client component for editing a business.
 *
 * Loads the business from `GET /platform/businesses/{id}` on mount. The fetch
 * runs in the browser so the Sanctum token from `sessionStorage` is attached as
 * the `Authorization: Bearer` header — the Server Component shell deliberately
 * does not fetch, because SSR has no `window` and would produce a `401`. This
 * mirrors the view page (`[businessId]/page.tsx`).
 */
export function PlatformBusinessEditClient({ businessId }: PlatformBusinessEditClientProps) {
  const router = useRouter();
  const [business, setBusiness] = useState<Business | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setIsLoading(true);
      setError(null);
      try {
        const data = await getBusiness(businessId);
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
  }, [businessId]);

  if (isLoading) {
    return (
      <section aria-label="Edit Business" className="flex min-w-0 flex-1 flex-col gap-4">
        <div className="flex flex-1 items-center justify-center p-6" role="status" aria-live="polite">
          <div className="flex flex-col items-center gap-2">
            <div className="animate-spin rounded-full h-8 w-8 border-2 border-primary border-t-transparent" />
            <p className="text-sm text-muted-foreground">Loading business…</p>
          </div>
        </div>
      </section>
    );
  }

  if (error || !business) {
    return (
      <section aria-label="Edit Business" className="flex min-w-0 flex-1 flex-col gap-4">
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
      </section>
    );
  }

  const formTarget: BusinessFormTarget = { mode: "edit", business };

  return (
    <section aria-label="Edit Business" className="flex min-w-0 flex-1 flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Edit Business</h1>
          <p className="text-sm text-muted-foreground">Editing {business.name}</p>
        </div>
        <div className="flex gap-2">
          <button
            type="button"
            className="rounded-lg border border-border bg-background px-3 py-2 text-sm hover:bg-muted"
            onClick={() => router.push(`/platform/businesses/${business.id}`)}
          >
            View
          </button>
          <button
            type="button"
            className="rounded-lg border border-border bg-background px-3 py-2 text-sm hover:bg-muted"
            onClick={() => router.push("/platform/businesses")}
          >
            Back to list
          </button>
        </div>
      </div>

      <BusinessForm
        target={formTarget}
        onOpenChange={(open) => {
          if (!open) {
            router.push(`/platform/businesses/${business.id}`);
          }
        }}
        onSubmitSuccess={() => {
          router.push(`/platform/businesses/${business.id}`);
        }}
      />
    </section>
  );
}