"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

import { BusinessForm, type BusinessFormTarget } from "@/features/platform/components/BusinessForm";
import type { Business } from "@/types/platform";

interface PlatformBusinessEditClientProps {
  initialBusiness: Business;
}

/**
 * Client component for editing a business.
 */
export function PlatformBusinessEditClient({ initialBusiness }: PlatformBusinessEditClientProps) {
  const router = useRouter();
  const [business] = useState<Business>(initialBusiness);
  const [formTarget] = useState<BusinessFormTarget>({ mode: "edit", business: initialBusiness });

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