import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import PlatformBusinessDetailsPage from "@/app/platform/businesses/[businessId]/page";
import type { Business, BusinessAdminResponse } from "@/types/platform";

const { push, refresh, getBusiness, provisionFirstAdmin } = vi.hoisted(() => ({
  push: vi.fn(),
  refresh: vi.fn(),
  getBusiness: vi.fn<(businessId: number) => Promise<Business>>(),
  provisionFirstAdmin: vi.fn<(businessId: number, values: unknown) => Promise<BusinessAdminResponse>>(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh }),
}));

vi.mock("@/features/platform/services/platformBusinessService", () => ({
  getBusiness: (...args: unknown[]) => getBusiness(...(args as [number])),
}));

vi.mock("@/stores/usePlatformBusinessStore", () => ({
  usePlatformBusinessStore: (
    selector: (state: { provisionFirstAdmin: typeof provisionFirstAdmin }) => unknown,
  ) => selector({ provisionFirstAdmin }),
}));

const business: Business = {
  id: 42,
  name: "Acme Corporation",
  phone: null,
  email: null,
  address: null,
  logo: null,
  currency: "USD",
  timezone: "America/New_York",
  status: "active",
  usersCount: 0,
  hasAdmin: false,
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
  roles: ["admin"],
  capabilities: [],
};

beforeEach(() => {
  push.mockReset();
  refresh.mockReset();
  getBusiness.mockReset().mockResolvedValue({ ...business });
  provisionFirstAdmin.mockReset().mockResolvedValue(admin);
});

/** Renders the business detail screen for the fixture business. */
function renderDetails(): void {
  render(<PlatformBusinessDetailsPage params={Promise.resolve({ businessId: String(business.id) })} />);
}

/** Fills the first-admin form with a valid administrator. */
async function fillAdminForm(): Promise<void> {
  fireEvent.change(await screen.findByLabelText("Full name"), {
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
}

/** Opens the first-admin dialog from the recovery action. */
async function openAdminDialog(): Promise<void> {
  fireEvent.click(await screen.findByRole("button", { name: /create first admin/i }));
  await screen.findByRole("dialog");
}

describe("Business details — first admin recovery", () => {
  it("offers the first admin setup when the business has no administrator", async () => {
    getBusiness.mockResolvedValue({ ...business, hasAdmin: false });

    renderDetails();

    expect(await screen.findByRole("button", { name: /create first admin/i })).toBeDefined();
    expect(screen.getByText("Not provisioned")).toBeDefined();
  });

  it("does not offer the first admin setup when an administrator already exists", async () => {
    getBusiness.mockResolvedValue({ ...business, hasAdmin: true, usersCount: 1 });

    renderDetails();

    await screen.findByRole("heading", { name: "Identity" });
    expect(screen.queryByRole("button", { name: /create first admin/i })).toBeNull();
    expect(screen.getByText("Provisioned")).toBeDefined();
  });

  it("keeps the action hidden when the API does not report the admin state", async () => {
    getBusiness.mockResolvedValue({ ...business, hasAdmin: undefined });

    renderDetails();

    await screen.findByRole("heading", { name: "Identity" });
    expect(screen.queryByRole("button", { name: /create first admin/i })).toBeNull();
  });

  it("provisions the first admin and refreshes the business", async () => {
    let current: Business = { ...business, hasAdmin: false };
    getBusiness.mockImplementation(async () => current);
    provisionFirstAdmin.mockImplementation(async () => {
      current = { ...current, hasAdmin: true, usersCount: 1 };
      return admin;
    });

    renderDetails();
    await openAdminDialog();
    await fillAdminForm();

    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: /create admin/i }));

    await waitFor(() => expect(provisionFirstAdmin).toHaveBeenCalledTimes(1));
    // The business is reloaded, so the recovery action disappears.
    await waitFor(() => expect(getBusiness).toHaveBeenCalledTimes(2));
    await waitFor(() =>
      expect(screen.queryByRole("button", { name: /create first admin/i })).toBeNull(),
    );
    expect(screen.getByText("Provisioned")).toBeDefined();
  });

  it("shows an actionable error and allows a retry when provisioning fails", async () => {
    getBusiness.mockResolvedValue({ ...business, hasAdmin: false });
    provisionFirstAdmin.mockRejectedValueOnce(
      new Error("Could not create the administrator. Please try again."),
    );

    renderDetails();
    await openAdminDialog();
    await fillAdminForm();

    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: /create admin/i }));

    expect(
      await screen.findByText("Could not create the administrator. Please try again."),
    ).toBeDefined();
    // The user stays on the business detail page with the dialog open.
    expect(push).not.toHaveBeenCalled();
    expect(screen.getByRole("dialog")).toBeDefined();

    provisionFirstAdmin.mockResolvedValueOnce(admin);
    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: /create admin/i }));

    await waitFor(() => expect(provisionFirstAdmin).toHaveBeenCalledTimes(2));
  });

  it("does not submit twice while the request is in flight", async () => {
    getBusiness.mockResolvedValue({ ...business, hasAdmin: false });
    let settle: ((created: BusinessAdminResponse) => void) | undefined;
    provisionFirstAdmin.mockImplementation(
      () =>
        new Promise<BusinessAdminResponse>((resolve) => {
          settle = resolve;
        }),
    );

    renderDetails();
    await openAdminDialog();
    await fillAdminForm();

    const dialog = screen.getByRole("dialog");
    fireEvent.click(within(dialog).getByRole("button", { name: /create admin/i }));

    const submit = await waitFor(() =>
      within(dialog).getByRole("button", { name: /creating admin/i }),
    );
    expect((submit as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(submit);
    expect(provisionFirstAdmin).toHaveBeenCalledTimes(1);

    await act(async () => {
      settle?.(admin);
    });
  });
});
