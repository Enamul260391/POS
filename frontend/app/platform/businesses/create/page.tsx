"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

import { BusinessForm, type BusinessFormTarget } from "@/features/platform/components/BusinessForm";
import { FirstAdminForm } from "@/features/platform/components/FirstAdminForm";
import type { Business } from "@/types/platform";

/**
 * `/platform/businesses/create` — Create a new business and provision its first admin.
 *
 * Two-step flow:
 * 1. Create Business
 * 2. Create First Business Admin
 */
export default function PlatformBusinessCreatePage() {
  const router = useRouter();
  const [formTarget] = useState<BusinessFormTarget>({ mode: "add" });
  const [createdBusiness, setCreatedBusiness] = useState<Business | null>(null);
  const [showAdminForm, setShowAdminForm] = useState(false);

  const handleBusinessCreated = async (business: Business) => {
    setCreatedBusiness(business);
    setShowAdminForm(true);
  };

  const handleBusinessDialogOpenChange = (open: boolean) => {
    if (!open) {
      router.push("/platform/businesses");
    }
  };

  const handleAdminDialogOpenChange = (open: boolean) => {
    if (open) {
      return;
    }

    router.push(
      createdBusiness
        ? `/platform/businesses/${createdBusiness.id}`
        : "/platform/businesses",
    );
  };

  const handleAdminCreated = () => {
    // Navigate to the business detail page to show the created business and admin
    router.push(`/platform/businesses/${createdBusiness?.id}`);
  };

  // Step 1: Create Business
  if (!showAdminForm) {
    return (
      <section aria-label="Create Business" className="flex min-w-0 flex-1 flex-col gap-4">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">Create Business</h1>
            <p className="text-sm text-muted-foreground">Add a new business tenant to the platform</p>
          </div>
        </div>

        <BusinessForm
          target={formTarget}
          onOpenChange={handleBusinessDialogOpenChange}
          onSubmitSuccess={handleBusinessCreated}
        />
      </section>
    );
  }

  // Step 2: Create First Business Admin
  if (!createdBusiness) {
    return null;
  }

  return (
    <section aria-label="Create First Business Admin" className="flex min-w-0 flex-1 flex-col gap-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Create First Business Admin</h1>
          <p className="text-sm text-muted-foreground">
            Provision the initial administrator for <strong>{createdBusiness.name}</strong>
          </p>
        </div>
      </div>

      <FirstAdminForm
        business={createdBusiness}
        onOpenChange={handleAdminDialogOpenChange}
        onSuccess={handleAdminCreated}
      />
    </section>
  );
}