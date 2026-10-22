import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import PlatformBusinessCreatePage from "@/app/platform/businesses/create/page";
import type { Business, BusinessAdminResponse } from "@/types/platform";

const { push, addBusiness, updateBusiness, provisionFirstAdmin } = vi.hoisted(() => ({
  push: vi.fn(),
  addBusiness: vi.fn<(...args: unknown[]) => Promise<Business>>(),
  updateBusiness: vi.fn<(...args: unknown[]) => Promise<Business>>(),
  provisionFirstAdmin: vi.fn<(...args: unknown[]) => Promise<BusinessAdminResponse>>(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push }),
}));

vi.mock("@/stores/usePlatformBusinessStore", () => ({
  usePlatformBusinessStore: (
    selector: (state: {
      addBusiness: typeof addBusiness;
      updateBusiness: typeof updateBusiness;
      provisionFirstAdmin: typeof provisionFirstAdmin;
    }) => unknown,
  ) => selector({ addBusiness, updateBusiness, provisionFirstAdmin }),
}));

const business: Business = {
  id: 42,
  name: "Acme Corporation",
  phone: null,
  email: null,
  address: null,
  logo: null,
  currency: "USD",
  timezone: "UTC",
  status: "active",
  usersCount: null,
  createdAt: "2026-01-01T00:00:00.000000Z",
  updatedAt: "2026-01-01T00:00:00.000000Z",
};

const admin: BusinessAdminResponse = {
  id: 7,
  business_id: business.id,
  is_platform_owner: false,
  name: "Admin User",
  email: "admin@example.test",
  created_at: "2026-01-01T00:00:00.000000Z",
  roles: ["business_admin"],
  capabilities: [],
};

beforeEach(() => {
  push.mockReset();
  addBusiness.mockReset().mockResolvedValue(business);
  updateBusiness.mockReset();
  provisionFirstAdmin.mockReset().mockResolvedValue(admin);
});

/** Submits step 1 and waits until the First Admin form is shown. */
async function openFirstAdminStep(): Promise<void> {
  render(<PlatformBusinessCreatePage />);

  fireEvent.change(screen.getByLabelText("Business name"), {
    target: { value: "Acme Corporation" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Create business" }));

  await waitFor(() => expect(addBusiness).toHaveBeenCalledTimes(1));
  await screen.findByLabelText("Full name");
}

describe("PlatformBusinessCreatePage — dialog close and cancel navigation", () => {
  it("shows the fixed Bangladesh locale as read-only and submits it", async () => {
    render(<PlatformBusinessCreatePage />);

    const timezone = (await screen.findByLabelText("Timezone")) as HTMLInputElement;
    const currency = screen.getByLabelText("Currency") as HTMLInputElement;

    expect(timezone.value).toBe("Asia/Dhaka");
    expect(timezone.readOnly).toBe(true);
    expect(currency.value).toBe("BDT — ৳");
    expect(currency.readOnly).toBe(true);
    expect(screen.getByText("UTC+06:00")).toBeDefined();
    expect(screen.getByText("Bangladeshi Taka")).toBeDefined();

    // The read-only fields cannot be edited.
    fireEvent.change(currency, { target: { value: "USD" } });
    fireEvent.change(timezone, { target: { value: "Europe/Berlin" } });
    expect(currency.value).toBe("BDT — ৳");
    expect(timezone.value).toBe("Asia/Dhaka");

    fireEvent.change(screen.getByLabelText("Business name"), {
      target: { value: "Acme Corporation" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Create business" }));

    await waitFor(() => expect(addBusiness).toHaveBeenCalledTimes(1));
    expect(addBusiness.mock.calls[0]?.[0]).toMatchObject({
      currency: "BDT",
      timezone: "Asia/Dhaka",
    });
  });

  it("navigates to the business list when Create Business is cancelled", async () => {
    render(<PlatformBusinessCreatePage />);

    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/platform/businesses"));
  });

  it("navigates to the business list when the dialog close button is used", async () => {
    render(<PlatformBusinessCreatePage />);

    fireEvent.click(screen.getByRole("button", { name: "Close" }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/platform/businesses"));
  });

  it("stays on the First Admin step after creating a business", async () => {
    await openFirstAdminStep();

    expect(addBusiness).toHaveBeenCalledTimes(1);
    expect(push).not.toHaveBeenCalled();
    expect(screen.queryByLabelText("Business name")).toBeNull();
  });

  it("navigates to the created business when First Admin setup is cancelled", async () => {
    await openFirstAdminStep();

    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));

    await waitFor(() =>
      expect(push).toHaveBeenCalledWith(`/platform/businesses/${business.id}`),
    );
    expect(push).toHaveBeenCalledTimes(1);
    expect(screen.queryByLabelText("Business name")).toBeNull();
  });

  it("navigates exactly once after creating the first admin", async () => {
    await openFirstAdminStep();

    fireEvent.change(screen.getByLabelText("Full name"), {
      target: { value: admin.name },
    });
    fireEvent.change(screen.getByLabelText("Email"), {
      target: { value: admin.email },
    });
    fireEvent.change(screen.getByLabelText("Password"), {
      target: { value: "password123" },
    });
    fireEvent.change(screen.getByLabelText("Confirm password"), {
      target: { value: "password123" },
    });

    fireEvent.click(screen.getByRole("button", { name: "Create admin" }));

    await waitFor(() => expect(provisionFirstAdmin).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(push).toHaveBeenCalledWith(`/platform/businesses/${business.id}`),
    );
    expect(push).toHaveBeenCalledTimes(1);
  });
});
