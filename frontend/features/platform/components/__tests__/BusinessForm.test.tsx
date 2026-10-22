import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BusinessForm } from "@/features/platform/components/BusinessForm";
import type { Business } from "@/types/platform";

const { addBusiness, updateBusiness } = vi.hoisted(() => ({
  addBusiness: vi.fn<(...args: unknown[]) => Promise<Business>>(),
  updateBusiness: vi.fn<(...args: unknown[]) => Promise<Business>>(),
}));

vi.mock("@/stores/usePlatformBusinessStore", () => ({
  usePlatformBusinessStore: (
    selector: (state: {
      addBusiness: typeof addBusiness;
      updateBusiness: typeof updateBusiness;
    }) => unknown,
  ) => selector({ addBusiness, updateBusiness }),
}));

/** A business created before the locale was fixed, so it carries its own. */
const existingBusiness: Business = {
  id: 42,
  name: "Acme Corporation",
  phone: null,
  email: null,
  address: null,
  logo: null,
  currency: "EUR",
  timezone: "Europe/Berlin",
  status: "active",
  usersCount: 2,
  hasAdmin: true,
  createdAt: "2026-01-01T00:00:00.000000Z",
  updatedAt: "2026-01-01T00:00:00.000000Z",
};

beforeEach(() => {
  addBusiness.mockReset();
  updateBusiness.mockReset().mockResolvedValue(existingBusiness);
});

describe("BusinessForm — locale fields", () => {
  it("groups the fields into the intended rows", () => {
    render(
      <BusinessForm
        target={{ mode: "add" }}
        onOpenChange={vi.fn()}
        onSubmitSuccess={vi.fn()}
      />,
    );

    // The form grid holds one row per group: name, email/phone,
    // address/logo, currency/timezone, status.
    const nameRow = screen.getByLabelText("Business name").parentElement?.parentElement;
    const rows = Array.from(nameRow?.parentElement?.children ?? []) as HTMLElement[];

    expect(rows).toHaveLength(5);

    // 1. The business name spans the full width of the parent grid.
    expect(rows[0]).toBe(nameRow);
    expect(rows[0]?.className).toContain("lg:col-span-3");

    // 2. Email and phone share a row of two equal fields.
    expect(within(rows[1] as HTMLElement).getByLabelText("Email")).toBeDefined();
    expect(within(rows[1] as HTMLElement).getByLabelText("Phone")).toBeDefined();
    expect(rows[1]?.className).toContain("sm:grid-cols-2");
    expect(rows[1]?.className).toContain("lg:col-span-3");

    // 3. Address and logo share a row of two equal fields.
    expect(within(rows[2] as HTMLElement).getByLabelText("Address")).toBeDefined();
    expect(within(rows[2] as HTMLElement).getByLabelText("Logo URL")).toBeDefined();
    expect(rows[2]?.className).toContain("sm:grid-cols-2");
    expect(rows[2]?.className).toContain("lg:col-span-3");

    // 4. Currency and timezone share a row, currency first.
    const localeRow = rows[3] as HTMLElement;
    const currency = within(localeRow).getByLabelText("Currency");
    const timezone = within(localeRow).getByLabelText("Timezone");
    expect(rows[3]?.className).toContain("sm:grid-cols-2");
    expect(rows[3]?.className).toContain("lg:col-span-3");
    expect(
      currency.compareDocumentPosition(timezone) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();

    // 5. Status keeps the last position.
    expect(within(rows[4] as HTMLElement).getByLabelText("Status")).toBeDefined();
  });

  it("keeps currency and timezone on one row, currency first", () => {
    render(
      <BusinessForm
        target={{ mode: "add" }}
        onOpenChange={vi.fn()}
        onSubmitSuccess={vi.fn()}
      />,
    );

    const currency = screen.getByLabelText("Currency");
    const timezone = screen.getByLabelText("Timezone");

    // Both fields share one row container that splits into two columns, and
    // currency comes first (left).
    const row = currency.parentElement?.parentElement;
    expect(row).toBe(timezone.parentElement?.parentElement);
    expect(row?.className).toContain("grid-cols-2");
    expect(
      currency.compareDocumentPosition(timezone) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it("keeps a stored locale editable and submits it unchanged", async () => {
    render(
      <BusinessForm
        target={{ mode: "edit", business: existingBusiness }}
        onOpenChange={vi.fn()}
        onSubmitSuccess={vi.fn()}
      />,
    );

    const currency = screen.getByLabelText("Currency (ISO 4217)") as HTMLInputElement;
    const timezone = screen.getByLabelText("Timezone (IANA)") as HTMLInputElement;

    expect(currency.value).toBe("EUR");
    expect(timezone.value).toBe("Europe/Berlin");
    expect(currency.readOnly).toBe(false);
    expect(timezone.readOnly).toBe(false);

    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(updateBusiness).toHaveBeenCalledTimes(1));
    expect(updateBusiness.mock.calls[0]?.[1]).toMatchObject({
      currency: "EUR",
      timezone: "Europe/Berlin",
    });
  });

  it("falls back to the platform locale when the business carries none", () => {
    render(
      <BusinessForm
        target={{
          mode: "edit",
          business: { ...existingBusiness, currency: null, timezone: null },
        }}
        onOpenChange={vi.fn()}
        onSubmitSuccess={vi.fn()}
      />,
    );

    expect((screen.getByLabelText("Currency (ISO 4217)") as HTMLInputElement).value).toBe("BDT");
    expect((screen.getByLabelText("Timezone (IANA)") as HTMLInputElement).value).toBe("Asia/Dhaka");
  });

  it("accepts UTC as a timezone for a business that already carries it", async () => {
    render(
      <BusinessForm
        target={{
          mode: "edit",
          business: { ...existingBusiness, currency: "USD", timezone: "UTC" },
        }}
        onOpenChange={vi.fn()}
        onSubmitSuccess={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(updateBusiness).toHaveBeenCalledTimes(1));
    expect(updateBusiness.mock.calls[0]?.[1]).toMatchObject({
      currency: "USD",
      timezone: "UTC",
    });
  });
});
