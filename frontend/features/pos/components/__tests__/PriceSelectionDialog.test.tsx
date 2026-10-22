import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { PriceSelectionDialog } from "@/features/pos/components/PriceSelectionDialog";
import { INSUFFICIENT_BATCH_STOCK_MESSAGE, type PriceOption } from "@/types/priceOptions";

/**
 * Regression tests for the batch price selector.
 *
 * The shared `RadioGroupItem` renders only its own indicator and drops the
 * children it is given, so an option row that nests its content inside the
 * radio shows an empty box: the prices, batch numbers and available
 * quantities never reach the screen. These tests pin the row content, the
 * label association that keeps it visible and selectable, and the failure
 * message the cashier must be able to read while the dialog is still open.
 */

/** Two distinct selling prices, as the API returns them. */
const options: PriceOption[] = [
  {
    selling_price: "100.00",
    batch_id: 34,
    batch_number: "BATCH-34",
    available_quantity: "20",
  },
  {
    selling_price: "120.00",
    batch_id: 35,
    batch_number: "BATCH-35",
    available_quantity: "7",
  },
];

function renderDialog(onConfirm: (option: PriceOption) => string | null = vi.fn(() => null)) {
  render(
    <PriceSelectionDialog
      open
      onOpenChange={vi.fn()}
      productName="Rice 5kg"
      options={options}
      quantity={2}
      onConfirm={onConfirm}
    />,
  );

  return onConfirm;
}

describe("PriceSelectionDialog — the batch options", () => {
  it("shows the price, batch number and available quantity of every option", () => {
    renderDialog();

    expect(screen.getByText("৳100.00")).toBeDefined();
    expect(screen.getByText("Batch BATCH-34")).toBeDefined();
    expect(screen.getByText("৳120.00")).toBeDefined();
    expect(screen.getByText("Batch BATCH-35")).toBeDefined();

    // The available quantity is the combined stock at the chosen price.
    expect(screen.getByText("20 units")).toBeDefined();
    expect(screen.getByText("7 units")).toBeDefined();

    // Both options are offered, each with its own label.
    expect(screen.getAllByText("Available at this price")).toHaveLength(2);
  });

  it("associates every option row with its own radio", () => {
    renderDialog();

    for (const option of options) {
      const control = document.getElementById(`price-option-${option.batch_id}`);
      expect(control).not.toBeNull();
      expect(control?.getAttribute("type")).toBe("radio");

      // The row is a label pointing at that radio, so the whole row is the
      // accessible name of the control.
      const label = control?.closest("label");
      expect(label?.getAttribute("for")).toBe(`price-option-${option.batch_id}`);
      expect(label?.textContent).toContain(`Batch ${option.batch_number}`);
    }

    const radios = screen.getAllByRole("radio");
    expect(radios).toHaveLength(2);
    expect(radios[0]?.getAttribute("aria-labelledby")).toBeTruthy();
  });

  it("confirms the option the cashier picked", () => {
    const onConfirm = renderDialog();

    const second = screen.getByRole("radio", { name: /BATCH-35/ });
    fireEvent.click(second);

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm).toHaveBeenCalledWith(options[1]);
  });

  it("selects an option when its row is clicked", () => {
    const onConfirm = renderDialog();

    fireEvent.click(screen.getByText("Batch BATCH-35"));
    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm).toHaveBeenCalledWith(options[1]);
  });
});

describe("PriceSelectionDialog — a refused confirmation", () => {
  it("shows the failure message inside the open dialog", () => {
    // The parent refuses the choice and hands the message back: the screen
    // behind the modal is unreadable while the modal is open.
    renderDialog(vi.fn(() => INSUFFICIENT_BATCH_STOCK_MESSAGE));

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);

    // The message belongs to the dialog, not to the page behind it.
    expect(alert.closest('[role="dialog"]')).not.toBeNull();
  });

  it("clears the message when the cashier picks another option", () => {
    renderDialog(vi.fn(() => INSUFFICIENT_BATCH_STOCK_MESSAGE));

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));
    expect(screen.getByRole("alert").textContent).toBe(INSUFFICIENT_BATCH_STOCK_MESSAGE);

    fireEvent.click(screen.getByRole("radio", { name: /BATCH-35/ }));

    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("shows no message when the confirmation succeeds", () => {
    renderDialog(vi.fn(() => null));

    fireEvent.click(screen.getByRole("button", { name: "Confirm" }));

    expect(screen.queryByRole("alert")).toBeNull();
  });
});
