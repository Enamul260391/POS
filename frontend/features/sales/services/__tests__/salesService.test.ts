import { beforeEach, describe, expect, it, vi } from "vitest";

import { createSale } from "@/features/sales/services/salesService";
import * as request from "@/lib/api/request";

// The service is the last mapping step before the wire: whatever it hands to
// `apiPost` is exactly what Laravel validates and persists.
vi.mock("@/lib/api/request", () => ({
  apiGet: vi.fn(),
  apiList: vi.fn(),
  apiPost: vi.fn().mockResolvedValue({
    id: 7,
    invoice_number: "INV-0001",
    customer_id: null,
    sale_date: "2026-01-15",
    subtotal: "200.00",
    discount: "0.00",
    tax: "0.00",
    grand_total: "200.00",
    paid_amount: "200.00",
    due_amount: "0.00",
    payment_status: "paid",
    status: "completed",
    notes: null,
    cashier_id: 1,
    items: [],
    payments: [],
    created_at: "2026-01-15T10:00:00.000000Z",
    updated_at: "2026-01-15T10:00:00.000000Z",
  }),
  apiPatch: vi.fn(),
  apiDelete: vi.fn(),
}));

const post = vi.mocked(request.apiPost);

beforeEach(() => {
  post.mockClear();
});

describe("createSale — the batch id on the wire", () => {
  it("sends the batch id of a price choice", async () => {
    // The API reads this as "the cashier chose the price of this batch" and
    // allocates FIFO across every eligible batch at that price.
    await createSale({
      saleDate: "2026-01-15",
      paidAmount: 200,
      items: [
        {
          productId: 1,
          quantity: 2,
          unitPrice: 100,
          batch_id: 42,
        },
      ],
    });

    expect(post).toHaveBeenCalledWith(
      "/sales",
      expect.objectContaining({
        items: [expect.objectContaining({ batch_id: 42 })],
      }),
    );
  });

  it("omits the batch id for an automatic single-price line", async () => {
    // No `batch_id` means "the confirmed price": the API allocates FIFO at it.
    await createSale({
      saleDate: "2026-01-15",
      paidAmount: 200,
      items: [{ productId: 1, quantity: 2, unitPrice: 100 }],
    });

    const body = post.mock.calls[0][1] as { items: Record<string, unknown>[] };
    expect(body.items[0]).not.toHaveProperty("batch_id");
  });

  it("keeps the unit price the cashier confirmed", async () => {
    await createSale({
      saleDate: "2026-01-15",
      paidAmount: 240,
      items: [{ productId: 1, quantity: 2, unitPrice: 120, batch_id: 42 }],
    });

    const body = post.mock.calls[0][1] as { items: Record<string, unknown>[] };
    expect(body.items[0].unit_price).toBe(120);
    expect(body.items[0].quantity).toBe(2);
  });

  it("never sends an invoice number", async () => {
    // The API allocates the number server side and rejects the field outright.
    await createSale({
      saleDate: "2026-01-15",
      paidAmount: 200,
      items: [{ productId: 1, quantity: 2, unitPrice: 100 }],
    });

    const body = post.mock.calls[0][1] as Record<string, unknown>;
    expect(body).not.toHaveProperty("invoice_number");
  });
});
