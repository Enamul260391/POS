"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, BanknoteIcon, Loader2, Printer, Undo2 } from "lucide-react";
import Link from "next/link";
import { useReactToPrint } from "react-to-print";

import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatMoney, formatQuantity } from "@/features/pos/lib/format";
import { ThermalReceipt } from "@/features/receipt/components/ThermalReceipt";
import { useReceiptSettings } from "@/features/receipt/hooks/useReceiptSettings";
import { SalePaymentDialog } from "@/features/sales/components/SalePaymentDialog";
import { SalePaymentStatusBadge } from "@/features/sales/components/SalePaymentStatusBadge";
import { SaleReturnDialog } from "@/features/sales/components/SaleReturnDialog";
import { SaleReturnHistory } from "@/features/sales/components/SaleReturnHistory";
import { SaleStatusBadge } from "@/features/sales/components/SaleStatusBadge";
import { receiptSaleFromSale } from "@/features/sales/lib/saleReceipt";
import {
  refundTotalForSale,
  returnableQuantities,
  saleReturnsFor,
} from "@/features/sales/lib/returns";
import {
  cashierName,
  effectiveTaxRate,
  paymentMethodLabel,
  saleCustomerName,
  saleLineCount,
  saleUnitCount,
} from "@/features/sales/lib/sales";
import { formatLocalDateTime } from "@/lib/datetime";
import { selectCustomers, useCustomerStore } from "@/stores/useCustomerStore";
import { selectSaleReturns, selectSales, useSalesStore } from "@/stores/useSalesStore";
import type { PaymentStatus } from "@/types/payment";
import { cn } from "cn";

/** Wording of a single payment's state, used by the payment rows. */
const PAYMENT_STATUS_LABELS: Readonly<Record<PaymentStatus, string>> = {
  pending: "Pending",
  completed: "Completed",
  failed: "Failed",
  refunded: "Refunded",
};

/**
 * Read-only `label … value` row of the information lists.
 *
 * `value` is a node so a timestamp can be the one element that suppresses the
 * hydration warning: the page is prerendered, and only the time zone of the
 * reader differs from the build machine.
 */
function DetailRow({
  term,
  value,
  valueClassName,
}: {
  term: string;
  value: React.ReactNode;
  valueClassName?: string;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 py-1">
      <dt className="shrink-0 text-sm text-muted-foreground">{term}</dt>
      <dd className={cn("text-right text-sm font-medium break-words", valueClassName)}>
        {value}
      </dd>
    </div>
  );
}

/** One titled block of the invoice, so every section reads the same way. */
function InfoSection({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="min-w-0">
      <h3 className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {title}
      </h3>
      {children}
    </section>
  );
}

interface SaleDetailsScreenProps {
  /** Id of the invoice to show, taken from the route segment. */
  saleId: number;
}

/**
 * `/sales/[id]` screen — one invoice in full (Phase 10).
 *
 * Everything is read from the stored invoice (`Sale`): invoice information,
 * customer, the sold lines with quantity/price/discount/tax/total, the payment
 * information and the returns already recorded against it. Nothing is
 * recalculated, so this screen can never contradict the invoice it shows.
 *
 * Two shared pieces are reused rather than re-implemented: the return flow
 * (`SaleReturnDialog`, mounted only while open) and the Phase 6 receipt —
 * `receiptSaleFromSale` maps the invoice onto the same `ReceiptSale` the POS
 * checkout prints, so an old invoice reprints on the same 80 mm paper.
 */
export function SaleDetailsScreen({ saleId }: SaleDetailsScreenProps) {
  const sales = useSalesStore(selectSales);
  const saleReturns = useSalesStore(selectSaleReturns);
  const customers = useCustomerStore(selectCustomers);
  const isLoading = useSalesStore((state) => state.isLoading);
  const isLoaded = useSalesStore((state) => state.isLoaded);
  const error = useSalesStore((state) => state.error);
  const loadSale = useSalesStore((state) => state.loadSale);
  const loadReturns = useSalesStore((state) => state.loadReturns);
  const returnsLoaded = useSalesStore((state) => state.returnsLoaded);

  // Shop identity and printed messages for the reprinted receipt, read through
  // the existing Settings service. Failure is non-fatal: the invoice is already
  // persisted, so the receipt renders without a business header rather than
  // blocking the screen or falling back to placeholder data.
  const {
    settings: receiptSettings,
    isLoading: isLoadingReceiptSettings,
    error: receiptSettingsError,
  } = useReceiptSettings();

  // Direct navigation lands here with an empty cache: fetch the invoice once
  // per id (the ref stops React Strict Mode's double effect and any re-render
  // from issuing a second request) and read the return catalogue so the refund
  // and returnable figures are netted off correctly.
  const requestedSaleId = useRef<number | null>(null);
  useEffect(() => {
    if (requestedSaleId.current !== saleId) {
      requestedSaleId.current = saleId;
      void loadSale(saleId);
    }
  }, [saleId, loadSale]);

  useEffect(() => {
    if (!returnsLoaded) {
      void loadReturns();
    }
  }, [returnsLoaded, loadReturns]);

  const sale = useMemo(
    () => sales.find((candidate) => candidate.id === saleId),
    [sales, saleId],
  );
  const returns = useMemo(
    () => (sale === undefined ? [] : saleReturnsFor(saleReturns, sale.id)),
    [sale, saleReturns],
  );
  const refundedTotal = useMemo(
    () => (sale === undefined ? 0 : refundTotalForSale(saleReturns, sale.id)),
    [sale, saleReturns],
  );
  const canReturn = useMemo(() => {
    if (sale === undefined || sale.status === "void") {
      return false;
    }
    return [...returnableQuantities(sale, saleReturns).values()].some(
      (quantity) => quantity > 0,
    );
  }, [sale, saleReturns]);
  // Settling an invoice needs money still owed on it. A voided sale is not a
  // payable document, and `dueAmount` is the API's own figure — a fully paid
  // invoice simply has nothing outstanding, so no request is ever sent.
  const canRecordPayment = useMemo(
    () => sale !== undefined && sale.status !== "void" && sale.dueAmount > 0,
    [sale],
  );
  // Contact details come from the customer directory while the invoice itself
  // only snapshots the name (the API will return the same relation).
  const customer = useMemo(() => {
    if (sale === undefined || sale.customerId === null) {
      return null;
    }
    return customers.find((candidate) => candidate.id === sale.customerId) ?? null;
  }, [customers, sale]);

  const receiptSale = useMemo(
    () => (sale === undefined ? null : receiptSaleFromSale(sale, receiptSettings)),
    [sale, receiptSettings],
  );

  const receiptRef = useRef<HTMLElement>(null);
  const printReceipt = useReactToPrint({
    contentRef: receiptRef,
    documentTitle: () => sale?.invoiceNumber ?? "receipt",
  });

  const [isReturnOpen, setIsReturnOpen] = useState(false);
  const [isPaymentOpen, setIsPaymentOpen] = useState(false);

  if (sale === undefined) {
    // The screen shows the spinner while loading or before the first fetch completes;
    // once loaded, if the invoice is still missing, the not-found state renders.
    if (isLoading || !isLoaded) {
      return (
        <section className="flex min-w-0 flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border px-6 py-16 text-center">
          <Loader2 className="size-5 animate-spin text-muted-foreground" aria-hidden="true" />
          <p className="text-sm text-muted-foreground">Loading invoice…</p>
        </section>
      );
    }

    return (
      <section className="flex min-w-0 flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border px-6 py-16 text-center">
        {error !== null ? (
          <>
            <p className="font-heading text-base font-semibold">Could not load invoice</p>
            <p className="max-w-md text-sm text-muted-foreground">{error}</p>
          </>
        ) : (
          <>
            <p className="font-heading text-base font-semibold">Invoice not found</p>
            <p className="text-sm text-muted-foreground">
              Invoice #{saleId} is not in the sales history.
            </p>
          </>
        )}
        <Link href="/sales" className={cn(buttonVariants({ variant: "outline" }), "mt-1")}>
          Back to sales
        </Link>
      </section>
    );
  }

  return (
    <section
      aria-label={`Invoice ${sale.invoiceNumber}`}
      className="flex min-w-0 flex-1 flex-col gap-4"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <Link
            href="/sales"
            className={cn(buttonVariants({ variant: "ghost", size: "sm" }), "-ml-2.5")}
          >
            <ArrowLeft aria-hidden="true" />
            All sales
          </Link>
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-mono text-lg font-semibold">{sale.invoiceNumber}</h2>
            <SaleStatusBadge status={sale.status} />
            <SalePaymentStatusBadge status={sale.paymentStatus} />
            {refundedTotal > 0 && (
              <Badge variant="secondary">Refunded {formatMoney(refundedTotal)}</Badge>
            )}
          </div>
          <p className="text-sm text-muted-foreground">
            {saleCustomerName(sale)} · {cashierName(sale)} ·{" "}
            <span suppressHydrationWarning>{formatLocalDateTime(sale.soldAt)}</span>
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={!canRecordPayment}
            aria-disabled={!canRecordPayment}
            title={
              canRecordPayment
                ? `Settle the ${formatMoney(sale.dueAmount)} still due`
                : sale.status === "void"
                  ? "This invoice was cancelled"
                  : "Nothing is outstanding on this invoice"
            }
            onClick={() => setIsPaymentOpen(true)}
          >
            <BanknoteIcon aria-hidden="true" />
            Record payment
          </Button>
          <Button type="button" variant="outline" onClick={printReceipt}>
            <Printer aria-hidden="true" />
            Print receipt
          </Button>
          <Button
            type="button"
            disabled={!canReturn}
            aria-disabled={!canReturn}
            title={
              canReturn
                ? "Return items from this invoice"
                : "Nothing left to return on this invoice"
            }
            onClick={() => setIsReturnOpen(true)}
          >
            <Undo2 aria-hidden="true" />
            Return items
          </Button>
        </div>
      </div>

      <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="flex min-w-0 flex-col gap-4">
          <InfoSection title="Invoice information">
            <dl className="divide-y divide-border rounded-lg border border-border px-3 py-1">
              <DetailRow term="Invoice number" value={sale.invoiceNumber} />
              <DetailRow
                term="Date"
                value={
                  <span suppressHydrationWarning>{formatLocalDateTime(sale.soldAt)}</span>
                }
              />
              <DetailRow term="Status" value={<SaleStatusBadge status={sale.status} />} />
              <DetailRow term="Cashier" value={cashierName(sale)} />
              <DetailRow
                term="Items"
                value={`${saleLineCount(sale)} lines · ${formatQuantity(saleUnitCount(sale))} units`}
              />
              <DetailRow term="Note" value={sale.note ?? "—"} />
              <DetailRow
                term="Created"
                value={
                  <span suppressHydrationWarning>
                    {formatLocalDateTime(sale.createdAt)}
                  </span>
                }
              />
            </dl>
          </InfoSection>

          <InfoSection title="Items">
            <div className="overflow-hidden rounded-lg border border-border">
              <Table className="min-w-[680px]">
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead>SKU</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead className="text-right">Price</TableHead>
                    <TableHead className="text-right">Discount</TableHead>
                    <TableHead className="text-right">Tax</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sale.items.map((item) => (
                    <TableRow key={item.id}>
                      <TableCell className="max-w-52">
                        <span className="block truncate" title={item.productName}>
                          {item.productName}
                        </span>
                      </TableCell>
                      <TableCell className="font-mono text-xs">{item.sku}</TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatQuantity(item.quantity)} {item.unit}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatMoney(item.unitPrice)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {item.discount > 0 ? `−${formatMoney(item.discount)}` : "—"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {item.taxAmount > 0 ? formatMoney(item.taxAmount) : "—"}
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {formatMoney(item.lineTotal)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            <dl className="mt-2 rounded-lg border border-border bg-muted/40 px-3 py-1.5">
              <DetailRow term="Subtotal" value={formatMoney(sale.subtotal)} />
              <DetailRow term="Discount" value={`−${formatMoney(sale.discountTotal)}`} />
              <DetailRow
                term={`Tax / VAT (${formatQuantity(effectiveTaxRate(sale))}%)`}
                value={formatMoney(sale.taxTotal)}
              />
              <DetailRow
                term="Grand total"
                value={formatMoney(sale.grandTotal)}
                valueClassName="font-heading font-semibold"
              />
            </dl>
          </InfoSection>
          <InfoSection title="Payment information">
            {sale.payments.length === 0 ? (
              <div className="rounded-lg border border-dashed border-border px-4 py-6 text-center">
                <p className="text-sm font-medium">No payment recorded</p>
                <p className="text-xs text-muted-foreground">
                  Nothing has been settled on this invoice yet.
                </p>
              </div>
            ) : (
              <div className="overflow-hidden rounded-lg border border-border">
                <Table className="min-w-[720px]">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Reference</TableHead>
                      <TableHead>Method</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-right">Amount</TableHead>
                      <TableHead className="text-right">Tendered</TableHead>
                      <TableHead className="text-right">Change</TableHead>
                      <TableHead>Paid at</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sale.payments.map((payment) => (
                      <TableRow key={payment.id}>
                        <TableCell className="font-mono text-xs">
                          {payment.reference}
                        </TableCell>
                        <TableCell>
                          {paymentMethodLabel(payment)}
                          {payment.transactionId !== null && (
                            <span className="block text-xs text-muted-foreground">
                              {payment.transactionId}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="text-muted-foreground">
                          {PAYMENT_STATUS_LABELS[payment.status]}
                        </TableCell>
                        <TableCell className="text-right font-medium tabular-nums">
                          {formatMoney(payment.amount)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">
                          {payment.tenderedAmount === null
                            ? "—"
                            : formatMoney(payment.tenderedAmount)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">
                          {payment.changeAmount === null
                            ? "—"
                            : formatMoney(payment.changeAmount)}
                        </TableCell>
                        <TableCell
                          className="whitespace-nowrap text-xs text-muted-foreground"
                          suppressHydrationWarning
                        >
                          {formatLocalDateTime(payment.paidAt)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}

            <dl className="mt-2 rounded-lg border border-border bg-muted/40 px-3 py-1.5">
              <DetailRow term="Paid" value={formatMoney(sale.paidAmount)} />
              <DetailRow
                term="Due"
                value={formatMoney(sale.dueAmount)}
                valueClassName={sale.dueAmount > 0 ? "text-destructive" : undefined}
              />
              <DetailRow
                term="Payment status"
                value={<SalePaymentStatusBadge status={sale.paymentStatus} />}
              />
              <DetailRow
                term="Refunded"
                value={formatMoney(refundedTotal)}
                valueClassName={refundedTotal > 0 ? "text-destructive" : undefined}
              />
            </dl>
          </InfoSection>

          <InfoSection title="Returns">
            <SaleReturnHistory saleReturns={returns} />
          </InfoSection>
        </div>

        <aside className="flex min-w-0 flex-col gap-4">
          <InfoSection title="Customer">
            <dl className="divide-y divide-border rounded-lg border border-border px-3 py-1">
              <DetailRow term="Name" value={saleCustomerName(sale)} />
              <DetailRow term="Phone" value={customer?.phone ?? "—"} />
              <DetailRow term="Address" value={customer?.address ?? "—"} />
              <DetailRow
                term="Opening balance"
                value={formatMoney(customer?.openingBalance ?? 0)}
              />
            </dl>
          </InfoSection>

          {/* The paper the POS prints, rebuilt from this invoice. */}
          <InfoSection title="Receipt · 80 mm thermal">
            <div className="flex justify-center rounded-lg border border-border bg-background p-2">
              <div className="max-h-[65dvh] overflow-y-auto">
                {receiptSale !== null && (
                  <ThermalReceipt ref={receiptRef} sale={receiptSale} />
                )}
              </div>
            </div>
            {isLoadingReceiptSettings && (
              <p className="text-xs text-muted-foreground">
                Loading business details for the receipt…
              </p>
            )}
            {receiptSettingsError !== null && (
              <p className="text-xs text-muted-foreground">
                Business details could not be loaded ({receiptSettingsError}); the receipt
                prints the sale only.
              </p>
            )}
          </InfoSection>
        </aside>
      </div>

      {/* Mounted only while open: every payment starts from the invoice's
          current outstanding amount, and a recorded one is read back from the
          API through `loadSale` before this closes. */}
      {isPaymentOpen && sale.dueAmount > 0 && (
        <SalePaymentDialog
          sale={sale}
          onOpenChange={(open) => {
            if (!open) {
              setIsPaymentOpen(false);
            }
          }}
        />
      )}

      {/* Mounted only while open: every return starts from fresh state. */}
      {isReturnOpen && (
        <SaleReturnDialog
          sale={sale}
          onOpenChange={(open) => {
            if (!open) {
              setIsReturnOpen(false);
            }
          }}
        />
      )}
    </section>
  );
}
