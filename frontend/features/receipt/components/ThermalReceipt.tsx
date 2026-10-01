"use client";

import type { Ref } from "react";

import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import {
  formatReceiptDateTime,
  type ReceiptLine,
  type ReceiptSale,
} from "@/features/receipt/lib/receipt";
import receipt from "@/features/receipt/receipt.module.css";

interface ThermalReceiptProps {
  sale: ReceiptSale;
  /**
   * Points `react-to-print` at the paper node: only this element (and its
   * children) is cloned into the print iframe, so everything outside it —
   * dialog chrome, buttons, confirmation rows — never reaches the printer.
   */
  ref?: Ref<HTMLElement>;
}

/** Shown where a value exists but was not stored — never a made-up value. */
const EMPTY_CELL = "—";

/** One `label … value` line inside a receipt block. */
function ReceiptRow({ term, value }: { term: string; value: string }) {
  return (
    <div className={receipt.row}>
      <dt className={receipt.rowTerm}>{term}</dt>
      <dd className={receipt.rowValue}>{value}</dd>
    </div>
  );
}

/** One sold line: product name, then `qty × unit price` with the line total. */
function ReceiptItem({ line, currency }: { line: ReceiptLine; currency: string }) {
  return (
    <li className={receipt.item}>
      <p className={receipt.itemName}>{line.name}</p>
      <div className={receipt.row}>
        <span>
          {formatQuantity(line.quantity)} × {formatMoney(line.unitPrice, currency)}
        </span>
        <span className={receipt.itemTotal}>{formatMoney(line.itemTotal, currency)}</span>
      </div>
    </li>
  );
}

/**
 * 80 mm thermal receipt — the only node that prints.
 *
 * Presentation lives in `receipt.module.css`: screen preview (dashed paper
 * edge) and `@media print` (exact 80 mm, no preview chrome) are styled
 * separately while sharing one class set. All money and quantities go through
 * the centralised utilities (`formatMoney`, `formatQuantity`); no images or
 * icons, so the receipt stays readable on plain thermal paper.
 *
 * Two kinds of data arrive in the `ReceiptSale` snapshot, and the component
 * only ever renders what it is given:
 *
 *   - **Business identity** (`sale.business`) comes from the Settings API —
 *     `business_name`, `business_address`, `business_phone`, `business_email`,
 *     `receipt_header`, `receipt_footer` and the currency symbol. A field the
 *     business has not set is *omitted*, not replaced: there is no fallback
 *     name, address, phone or thank-you line, so a receipt can never print
 *     fabricated business information.
 *   - **The sale itself** (invoice number, date, cashier, customer, lines,
 *     totals, payments) comes from the persisted document.
 *
 * `currency` only selects the symbol `formatMoney` prints in front of an
 * already-computed amount; no amount is recalculated here.
 */
export function ThermalReceipt({ sale, ref }: ThermalReceiptProps) {
  const { business } = sale;
  const currency = business.currencySymbol;
  const cashier = sale.cashier === "" ? EMPTY_CELL : sale.cashier;

  return (
    <article
      ref={ref}
      className={receipt.receipt}
      aria-label={`Receipt ${sale.invoiceNumber}`}
    >
      <header className={receipt.header}>
        {business.name !== "" && (
          <p className={receipt.shopName}>{business.name}</p>
        )}
        {business.address !== "" && (
          <p className={receipt.shopLine}>{business.address}</p>
        )}
        {business.phone !== "" && <p className={receipt.shopLine}>Phone: {business.phone}</p>}
        {business.email !== "" && <p className={receipt.shopLine}>Email: {business.email}</p>}
      </header>

      <hr className={receipt.divider} />

      <dl className={receipt.infoList}>
        <ReceiptRow term="Invoice" value={sale.invoiceNumber} />
        <ReceiptRow term="Date" value={formatReceiptDateTime(sale.soldAt)} />
        <ReceiptRow term="Cashier" value={cashier} />
        <ReceiptRow term="Customer" value={sale.customer} />
      </dl>

      {business.header !== "" && (
        <p className={receipt.message}>{business.header}</p>
      )}

      <hr className={receipt.divider} />

      <ul className={receipt.items} aria-label="Sold items">
        {sale.lines.map((line, index) => (
          // Static snapshot: lines cannot change after the sale is confirmed.
          <ReceiptItem key={`${line.name}-${index}`} line={line} currency={currency} />
        ))}
      </ul>

      <hr className={receipt.divider} />

      <dl className={receipt.infoList}>
        <ReceiptRow term="Subtotal" value={formatMoney(sale.subtotal, currency)} />
        <ReceiptRow
          term="Discount"
          value={
            sale.discountTotal > 0
              ? `−${formatMoney(sale.discountTotal, currency)}`
              : formatMoney(0, currency)
          }
        />
        <ReceiptRow
          term={`Tax / VAT (${formatQuantity(sale.taxRate)}%)`}
          value={sale.taxTotal > 0 ? `+${formatMoney(sale.taxTotal, currency)}` : formatMoney(0, currency)}
        />
      </dl>

      <div className={receipt.grand}>
        <span>Grand total</span>
        <span className={receipt.grandTotal}>{formatMoney(sale.grandTotal, currency)}</span>
      </div>

      <hr className={receipt.divider} />

      <dl className={receipt.infoList}>
        <ReceiptRow term="Payment method" value={sale.paymentMethod} />
        <ReceiptRow term="Paid" value={formatMoney(sale.paidAmount, currency)} />
        <ReceiptRow term="Change" value={formatMoney(sale.change, currency)} />
      </dl>

      <hr className={receipt.divider} />

      {business.footer !== "" && <p className={receipt.thanks}>{business.footer}</p>}
    </article>
  );
}
