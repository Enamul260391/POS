<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Business;
use App\Models\CashRegisterTransaction;
use App\Models\CustomerLedgerEntry;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\CashRegister\CashRegisterService;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\ReportDateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, tenant-scoped business reporting.
 *
 * Every figure this service returns is an aggregate computed in the database
 * over rows the authenticated user's business owns. Nothing is computed on the
 * client, and no figure is ever assembled from another endpoint.
 *
 * ## Policies this service enforces
 *
 * **Dates.** Every report filters through {@see ReportDateRange}: `date_from`
 * and `date_to` are inclusive calendar days, and the window is applied as a
 * half-open interval so it behaves identically on a `DATE` column, a `DATETIME`
 * column, MySQL and SQLite. There is no timezone conversion — every column these
 * reports filter on is a plain `DATE` carrying no offset of its own, so an
 * applied offset would invent one.
 *
 * **Gross vs net.** Returns are never hidden and never folded into an invoice.
 * Sales and profit both publish the gross figure *and* the reversal *and* the
 * net, so `gross - returns = net` can be checked from the payload alone.
 *
 * **Refunds are outbound.** A refund is a `Payment` with `type = 'refund'`,
 * a null `sale_id` and a `sale_return_id`. It therefore cannot enter an inbound
 * sum, and it is reported on its own lines rather than dropped.
 *
 * **Receivables come from the ledger.** `customer_ledger_entries` is the source
 * of truth for what a customer owes. `sales.due_amount` is a per-invoice
 * historical figure and is never used as a balance.
 *
 * **Payables come from the purchase flow.** `supplierOutstandingReport()` reads
 * `suppliers.opening_balance` plus `SUM(purchases.due_amount)`. There is no
 * supplier ledger table and none is introduced: unlike `sales.due_amount`, the
 * purchase column is rewritten by every write that moves it, and the schema has no
 * supplier return or credit note that could leave it stale.
 * `payments.purchase_id` is never read, because no write path fills it.
 *
 * **Cost comes from history.** `sale_items.unit_cost` is a snapshot taken when
 * the sale was written; a product's *current* `cost_price` is never substituted
 * for it, here or in the return reversal.
 *
 * **Nothing is invented.** Where the schema cannot support an honest figure —
 * stock valuation, profit while any line is unpriced — the figure is absent or
 * withheld and the reason is returned alongside it. No figure is estimated from
 * a neighbouring one.
 *
 * ## Cash Register Movement (Stage 9)
 *
 * {@see cashRegisterMovements()} reads `cash_register_transactions` and nothing
 * else. That is deliberate and is the whole point of the report: it is the one
 * place that can answer "what actually hit the drawer", because a sale may be
 * part cash / part card, a refund may be settled by bank transfer, and an
 * expense may be paid from the bank account. Only the register rows carry the
 * *physical* cash movement. Re-deriving it from `payments`, `expenses` or
 * `sale_returns` would report money that never went near the drawer.
 *
 * The data that supports it is already sound and is left untouched:
 *
 *  - a cash **sale** collection is an inbound `Payment` plus one automatic
 *    `cash_register_transactions` row of type `sale`;
 *  - a cash **refund** is one automatic row of type `refund`, posted only for the
 *    cash portion of a return;
 *  - a card / bank / cheque refund is a `Payment` only — **no** cash-register
 *    movement, because no cash left the drawer;
 *  - credit / due never touches the drawer at all; it is a `customer_ledger_entries`
 *    matter.
 *
 * Automatic movements always carry a `reference_type` / `reference_id`, which is
 * what keeps `CashRegisterService::isManual()` from letting a report- or
 * system-written row be edited by the manual cash-in/cash-out endpoints.
 */
final class ReportService
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    public function sales(int $businessId, array $filters): array
    {
        $query = $this->scopedSales($businessId, $filters);

        // The payment-method split is a second, grouped aggregate over the
        // `payments` of exactly the invoices the header totals cover.
        //
        // Why the id set rather than a join: joining `payments` into the header
        // aggregate would multiply every sale figure by its payment count, so
        // the breakdown is built from the already-filtered sale ids instead. The
        // subquery is uncorrelated, exactly like the `purchase_items` sum in
        // `purchases()`, so it never mixes an outer column with aggregates under
        // ONLY_FULL_GROUP_BY.
        //
        // `payment_method` filter: the header restricts to invoices that *have*
        // a payment of that method, so the breakdown restricts its own rows to
        // the same method — otherwise the card would contradict the totals above
        // it. `whereNotNull(sale_id)` also keeps purchase payments out.
        //
        // `->inbound()` is what makes the refund exclusion a property of the
        // *schema* rather than a side effect of how a refund row happens to be
        // filled in. A refund is written against its return with `sale_id` null,
        // so it is already outside this set — but "collected" must mean "money
        // received", stated where the reader can see it. See
        // `returnsAggregate()` for the outbound half, which is reported
        // separately instead of being silently dropped.
        $payments = Payment::query()
            ->inbound()
            ->whereNotNull('payments.sale_id')
            ->whereIn('payments.sale_id', (clone $query)->select('sales.id'));

        if (($filters['payment_method'] ?? null) !== null) {
            $payments->where('payments.payment_method', $filters['payment_method']);
        }

        $breakdown = $payments->toBase()
            ->selectRaw('payments.payment_method, COUNT(*) as payment_count, COALESCE(SUM(payments.amount), 0) as total_amount')
            ->groupBy('payments.payment_method')
            // Largest first, so the colour the client assigns to each row (by
            // index) always belongs to the biggest slice of the period.
            ->orderByDesc('total_amount')
            ->orderBy('payments.payment_method')
            ->get()
            ->map(fn (object $row): array => [
                'payment_method' => $row->payment_method,
                'payment_count' => (int) $row->payment_count,
                'total_amount' => Money::format($row->total_amount),
            ])->values()->all();

        // The daily series is a second, grouped aggregate over *exactly* the
        // invoices the header totals below cover — the same `scopedSales()`
        // builder, cloned before any `selectRaw` is added to it, so no second
        // interpretation of "the sales population" can exist.
        //
        // The monthly series is that same aggregate one granularity up: the very
        // same clone, grouped by the calendar month the invoice's own `sale_date`
        // falls in. Both are taken here, before the header aggregate, because
        // `$query->selectRaw(...)` mutates the builder in place — the *scope* is
        // identical either way, and the boundary conditions are the ones
        // `scopedSales()` and `ReportDateRange` already established. Keeping the
        // two together is what stops them drifting: there is no window, filter or
        // predicate one honours and the other does not.
        $monthlySeries = $this->monthlySalesSeries(clone $query, $filters);

        $dailySeries = $this->dailySalesSeries(clone $query, $filters);

        // Top products is the third grouped aggregate over that same builder —
        // one step further down, from invoices to their sale lines. Taken here
        // for the same reason as the two series: before the header aggregate
        // mutates the builder in place.
        $topProducts = $this->topProducts($businessId, clone $query);

        // Top categories is that same aggregate one join further again: the same
        // clone, resolved through `products` to `categories`. Taken here for the
        // same reason as the three above — before the header aggregate mutates the
        // builder in place.
        $topCategories = $this->topCategories($businessId, clone $query);

        // Top customers is the same aggregate one join *sideways* instead of
        // down: the same clone, grouped by the customer the invoice names.
        // Taken here for the same reason as the four above — before the header
        // aggregate mutates the builder in place.
        $topCustomers = $this->topCustomers($businessId, clone $query);

        // Top users is the same aggregate joined one hop sideways again: the same
        // clone, grouped by the staff member recorded on the invoice itself
        // (`sales.cashier_id` — the relationship `SaleService::create()` writes).
        // Taken here for the same reason as the five above — before the header
        // aggregate mutates the builder in place.
        $topUsers = $this->topUsers($businessId, clone $query);

        $row = $query->selectRaw('COUNT(*) as sale_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as subtotal')
            ->selectRaw('COALESCE(SUM(discount), 0) as discount')
            ->selectRaw('COALESCE(SUM(tax), 0) as tax')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid_amount')
            // `sales.due_amount` records what the invoice itself says is outstanding. It
            // is revised when the invoice is paid — and only then. A return never
            // touches it, which is exactly why it cannot be the balance.
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due_amount')->first();

        // Returns are revenue in their own right, dated on their own document.
        // They are subtracted, never hidden: `total_sales` stays gross and
        // `net_sales` becomes gross minus returns, so the arithmetic that got
        // there is visible on the payload.
        $returns = $this->returnsAggregate($businessId, $filters);
        $netSales = Money::subtract($row->total_sales, $returns['returns_amount']);

        return $this->withPeriod(filters: $filters, metrics: [
            'sale_count' => (int) $row->sale_count,
            'total_sales' => Money::format($row->total_sales),
            'subtotal' => Money::format($row->subtotal),
            'discount' => Money::format($row->discount),
            'tax' => Money::format($row->tax),
            // grand_total is already net of the header discount and inclusive of
            // tax; those are never applied a second time here. This figure is
            // gross of returns — `net_sales` is the one that has them removed.
            'net_sales' => $netSales,
            'returns_count' => $returns['returns_count'],
            'returns_amount' => $returns['returns_amount'],
            'paid_amount' => Money::format($row->paid_amount),
            'due_amount' => Money::format($row->due_amount),
            // The receivable the ledger says is outstanding *now*, which is not
            // the same question as the `due_amount` above.
            'customer_outstanding' => $this->customerOutstanding($businessId, $filters['customer_id'] ?? null),
            'payment_breakdown' => $breakdown,
            // Outbound settlement, reported on its own lines so a refund is never
            // mistaken for a collection and never disappears from the books.
            'refund_count' => $returns['refund_count'],
            'refund_amount' => $returns['refund_amount'],
            'refund_breakdown' => $returns['refund_breakdown'],
            'net_collected_amount' => Money::subtract($row->paid_amount, $returns['refund_amount']),
            // The same population as `total_sales`, broken down by the invoice's
            // own `sale_date`. Gross, like `total_sales`: returns and refunds are
            // not netted in here, and summing the series reproduces `total_sales`
            // exactly.
            'daily_series' => $dailySeries,
            // The same population again, at month granularity. Gross, for the
            // same reasons: summing it reproduces `total_sales` exactly, so the
            // monthly chart cannot disagree with the total printed beside it.
            'monthly_series' => $monthlySeries,
            // The same population again, resolved all the way down to the sale
            // lines and grouped by product. Gross, for the same reasons, and
            // additive only: nothing above it is derived from this field.
            'top_products' => $topProducts,
            // The same population again, one join further down, grouped by the
            // category its product belongs to. Gross, for the same reasons, and
            // additive only: `total_sales`, the two series and `top_products` are
            // all unchanged by it and none of them read it.
            'top_categories' => $topCategories,
            // The same population again, grouped by the customer each invoice
            // names. Gross, for the same reasons, and additive only: nothing
            // above it is derived from this field and none of it read it.
            //
            // It is deliberately *not* additive to `total_sales`: walk-in sales
            // (`customer_id` null) are in the total and necessarily absent here,
            // so the two are not expected to reconcile. See topCustomers().
            'top_customers' => $topCustomers,
            // The same population again, grouped by the staff member recorded on
            // the invoice (`sales.cashier_id`, the canonical sale-to-user
            // relationship this schema already writes). Gross, for the same
            // reasons, and additive only: nothing above it is derived from this
            // field and none of it read it.
            //
            // Like `top_customers` it is deliberately *not* additive to
            // `total_sales`: an unassigned sale (`cashier_id` null) is in the total
            // and necessarily absent here, so the two are not expected to
            // reconcile. See topUsers().
            'top_users' => $topUsers,
        ]);
    }

    /**
     * Purchase summary for the window.
     *
     * Semantics, as they actually are today:
     *
     *  - **Date field:** `purchases.purchase_date`, a `DATE`, through the shared
     *    {@see ReportDateRange} — inclusive of the whole `date_to` day.
     *  - **Tenant:** `forBusiness($businessId)`; a foreign purchase is "not
     *    found" rather than filtered at read time.
     *  - **Status: no filter is applied, so draft purchases are included.**
     *    `Purchase::STATUS_DRAFT` is the only status `PurchaseService::create()`
     *    ever writes, and there is no "complete" transition in the system, so
     *    excluding drafts would make the report permanently empty. This is the
     *    existing behaviour and is left exactly as it is; the frontend types
     *    already warn that `status` is not filtered here.
     *  - **Supplier:** an exact `supplier_id` equality filter.
     *  - **Money:** `paid_amount` and `due_amount` are read from the purchase
     *    **header columns**, not summed from `payments`. The columns are what
     *    the purchase flow writes and they agree with each other by
     *    construction (`due = grand_total - paid`).
     *  - **Purchase payments are not independently reported.** `payments` rows
     *    with a `purchase_id` exist and are correctly excluded from the sales
     *    breakdown, but the purchase report has no per-method payment split and
     *    does not reconcile its header against those rows. Recorded here as a
     *    known limitation rather than papered over.
     *  - **Item quantity** is summed database-side over `purchase_items` of the
     *    purchases in scope, resolved through an uncorrelated id subquery so the
     *    pure aggregate stays valid under `ONLY_FULL_GROUP_BY`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function purchases(int $businessId, array $filters): array
    {
        $query = Purchase::query()->forBusiness($businessId);
        $this->applyDateRange($query, $filters, 'purchases.purchase_date');
        if (($filters['supplier_id'] ?? null) !== null) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        // Item quantity is aggregated in a separate, *uncorrelated* scalar
        // subquery rather than with a `whereColumn(... = purchases.id)`
        // correlated selectSub in the select list.
        //
        // Why: the outer query is a pure aggregate (COUNT/SUM with no GROUP BY),
        // so it collapses to a single row in which `purchases.id` is not a value
        // of any particular purchase. Selecting a subquery correlated to it
        // mixed a non-aggregated outer column with aggregates, which MySQL and
        // MariaDB reject under ONLY_FULL_GROUP_BY (SQLSTATE 1140). It was also
        // ambiguous about intent: correlated, it would have summed the items of
        // one arbitrary purchase.
        //
        // Uncorrelated, it sums the quantities of every item belonging to the
        // purchases already in scope — the same reading as `purchase_count`,
        // `purchase_total`, `paid_amount` and `due_amount`, which are all totals
        // over the filtered set. The inner query is a clone of the already
        // business-scoped and date/supplier-filtered builder, so the figures
        // can never describe a different slice than the header totals.
        //
        // `purchase_items` has no `business_id` of its own, so the tenant is
        // carried by the parent id set: only items of this business's purchases
        // can ever be summed.
        //
        // This stays one database-side aggregate — no rows reach PHP.
        $itemQuantity = PurchaseItem::query()
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereIn('purchase_items.purchase_id', (clone $query)->select('purchases.id'));

        $row = $query->selectRaw('COUNT(*) as purchase_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as purchase_total')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due_amount')
            ->selectSub($itemQuantity, 'item_quantity')->first();

        return $this->withPeriod($filters, [
            'purchase_count' => (int) $row->purchase_count,
            'purchase_total' => Money::format($row->purchase_total),
            'paid_amount' => Money::format($row->paid_amount),
            'due_amount' => Money::format($row->due_amount),
            'item_quantity' => Quantity::format($row->item_quantity),
        ]);
    }

    /**
     * Expense summary for the window, with a category breakdown.
     *
     *  - **Date field:** `expenses.expense_date`, a `DATE`, through the shared
     *    {@see ReportDateRange}.
     *  - **Tenant:** `forBusiness($businessId)`, and the category join is itself
     *    constrained to the same business, so a category row from another tenant
     *    cannot supply a name to this tenant's figures.
     *  - **No status filter:** expenses have no lifecycle — there is no draft
     *    expense in the schema.
     *  - **Payment method is stored but not reported.** `expenses.payment_method`
     *    is recorded by the write path and validated against
     *    `Expense::PAYMENT_METHODS`, but this report groups by **category only**.
     *    There is no `payment_method_breakdown` on the payload, and none is added
     *    here: the data exists and would be safe to group, but adding a new
     *    grouping to the payload is a Reports feature, not a policy correction,
     *    and belongs in a later stage. The cash-share card in the UI stays
     *    unavailable for exactly this reason.
     *  - **Category grouping:** an expense with no category (or whose category
     *    row was deleted) forms its own bucket named `"Uncategorized"`, sorted
     *    last, rather than being dropped or attributed to a category it does not
     *    belong to. Both buckets sum to `total_expense`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function expenses(int $businessId, array $filters): array
    {
        $query = Expense::query()->forBusiness($businessId);
        $this->applyDateRange($query, $filters, 'expenses.expense_date');
        if (($filters['expense_category_id'] ?? null) !== null) {
            $query->where('expense_category_id', $filters['expense_category_id']);
        }
        $row = (clone $query)->selectRaw('COUNT(*) as expense_count')->selectRaw('COALESCE(SUM(amount), 0) as total_expense')->first();
        $breakdown = (clone $query)
            ->leftJoin('expense_categories', function ($join) use ($businessId): void {
                $join->on('expense_categories.id', '=', 'expenses.expense_category_id')
                    ->where('expense_categories.business_id', '=', $businessId);
            })
            ->groupBy('expenses.expense_category_id', 'expense_categories.name', 'expense_categories.status')
            ->orderByRaw('expense_categories.id IS NULL')
            ->orderBy('expense_categories.name')
            ->toBase()
            ->selectRaw('COUNT(*) as expense_count, SUM(expenses.amount) as total_expense')
            ->addSelect([
                'expenses.expense_category_id',
                'expense_categories.name as category_name',
                'expense_categories.status as category_status',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'expense_category_id' => $row->expense_category_id,
                'name' => $row->category_name ?? 'Uncategorized',
                'status' => $row->category_status,
                'expense_count' => (int) $row->expense_count,
                'total_expense' => Money::format($row->total_expense),
            ])->values()->all();

        return $this->withPeriod($filters, [
            'expense_count' => (int) $row->expense_count,
            'total_expense' => Money::format($row->total_expense),
            'category_breakdown' => $breakdown,
        ]);
    }

    /**
     * Profit and loss for the window — revenue, cost of goods, gross profit and
     * net profit after expenses.
     *
     * Cost of goods is computable because `sale_items.unit_cost` snapshots the
     * product's `cost_price` at the moment of the sale, so the cost of a
     * historical line is the cost actually incurred rather than today's price.
     * The *reversal* of that cost on a return is read the same way, through the
     * return line's `sale_item` pointer — never through the product's current
     * `cost_price`.
     *
     * ## The identity this report holds itself to
     *
     *     gross revenue  - return revenue      = net revenue
     *     gross COGS     - returned COGS       = net COGS
     *     net revenue    - net COGS - expenses = net profit
     *
     * Every term of that identity is on the payload, so the net figure can be
     * checked rather than trusted. `revenue` and `cost_of_goods` stay gross (they
     * are what the invoices and their lines actually recorded); `net_revenue`,
     * `returned_cost_of_goods` and `net_cost_of_goods` are the netted halves;
     * `gross_profit` is gross-revenue less gross-COGS and `net_profit` is the
     * full identity above.
     *
     * ## The one honest caveat: `NULL` unit costs
     *
     * The column is nullable: a service line has no product and therefore no
     * cost, and rows written before the column existed may never have been
     * backfilled. Summing those lines as if they cost nothing would understate
     * COGS and *overstate* profit, so instead the report counts them and refuses
     * to publish any cost- or profit-derived figure while any are present.
     * `unpriced_lines` is returned either way so the UI can say how many rows are
     * holding the figure back. A *returned* line with no recorded cost counts
     * too: reversing an unknown cost would put a made-up number into the
     * reversal.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function profitLoss(int $businessId, array $filters): array
    {
        $sales = $this->scopedSales($businessId, $filters);

        // The revenue side of the identity.
        $revenueRow = (clone $sales)
            ->selectRaw('COALESCE(SUM(grand_total), 0) as revenue')
            ->selectRaw('COALESCE(SUM(discount), 0) as discount')
            ->selectRaw('COALESCE(SUM(tax), 0) as tax')
            ->first();

        $expenses = $this->expenses($businessId, $filters);
        $totalExpense = $expenses['metrics']['total_expense'];

        // Both cost halves, drawn from the same return population `sales()`
        // reports, so the two can never disagree about which returns count.
        $returns = $this->returnsAggregate($businessId, $filters);
        $cost = $this->costOfGoodsAggregate($businessId, $filters, $sales);

        $revenue = Money::format($revenueRow->revenue);
        $costOfGoods = $cost['cost_of_goods'];
        $returnsAmount = $returns['returns_amount'];
        $returnedCostOfGoods = $cost['returned_cost_of_goods'];
        // Sale lines with no recorded cost, plus returned lines whose sale line
        // had none. Either makes a profit figure unknowable rather than zero.
        $unpriced = $cost['unpriced_lines'];

        // Only a fully priced population can produce a trustworthy profit.
        $available = $unpriced === 0;

        $netRevenue = Money::subtract($revenue, $returnsAmount);
        $netCostOfGoods = Money::subtract($costOfGoods, $returnedCostOfGoods);

        $grossProfit = $available ? Money::subtract($revenue, $costOfGoods) : null;
        $netGrossProfit = $available ? Money::subtract($netRevenue, $netCostOfGoods) : null;
        $netProfit = $available ? Money::subtract($netGrossProfit, $totalExpense) : null;

        return $this->withPeriod($filters, [
            'available' => $available,
            'limitation' => $available ? null : sprintf(
                'Cost of goods is unavailable: %d sale line(s) in this period have no recorded unit cost, so a profit figure would overstate it.',
                $unpriced,
            ),
            'revenue' => $revenue,
            'discount' => Money::format($revenueRow->discount),
            'tax' => Money::format($revenueRow->tax),
            // The return side of the revenue identity.
            'returns_count' => $returns['returns_count'],
            'returns_amount' => $returnsAmount,
            'net_revenue' => $netRevenue,
            'cost_of_goods' => $available ? $costOfGoods : null,
            // The return side of the cost identity, read from the historical unit
            // cost of the line being returned.
            'returned_cost_of_goods' => $available ? $returnedCostOfGoods : null,
            'net_cost_of_goods' => $available ? $netCostOfGoods : null,
            'gross_profit' => $grossProfit,
            'net_gross_profit' => $netGrossProfit,
            'gross_margin_percent' => $available && ! Money::isZero($revenue)
                ? Money::format(
                    bcdiv(bcmul($grossProfit, '100', 10), $revenue, 10)
                )
                : null,
            'total_expense' => $totalExpense,
            'net_profit' => $netProfit,
            'unpriced_lines' => $unpriced,
        ]);
    }

    /**
     * Current stock on hand — a snapshot, not a period report.
     *
     * There is no `period` wrapper and the request's dates are deliberately not
     * applied: on-hand quantity is a *current* reading, and answering it "as at
     * the 1st" from a cumulative ledger would mean reconstructing a point in
     * time by replaying every movement, which the schema cannot do exactly once
     * a movement has been corrected.
     *
     * Three separate things, kept separate here because only one of them is
     * reliable today:
     *
     *  1. **Current stock quantity** — `SUM(stock_movements.quantity)` per
     *     product. Reliable: the ledger is the single source of truth for
     *     quantity and every flow writes a signed row to it.
     *  2. **Current stock valuation** — **not reported, and not derivable.**
     *     `stock_movements` records no cost, so there is no way to value the
     *     units on hand without re-reading `products.cost_price`, which is
     *     *today's* price and would misstate every unit bought at a different
     *     one. Inventing a valuation here would be the same error as pricing a
     *     historical sale at today's cost, so the figure is absent rather than
     *     wrong.
     *  3. **Historical inventory movement** — `movement_count`, `total_stock_in`
     *     and `total_stock_out` are cumulative totals over the *whole* ledger,
     *     not the requested period, and are labelled as such. Movement history
     *     per period is served by `/inventory/stock-movements`.
     *
     * Service units are excluded: they have no stock and a service line's
     * movement ledger would be meaningless. A product with no movement rows
     * reads as `0` via the aggregate rather than being absent.
     *
     * `low_stock_count` counts products at or below their own
     * `low_stock_threshold`. It is a count only — which products those are comes
     * from the products endpoint, so this report never returns a list.
     */
    public function inventory(int $businessId): array
    {
        $stock = StockMovement::query()
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('stock_movements.product_id', 'products.id')
            ->where('stock_movements.business_id', $businessId);

        $products = Product::query()
            ->forBusiness($businessId)
            ->where('unit', '!=', Product::UNIT_SERVICE)
            ->select('products.*')
            ->selectSub($stock, 'current_stock');

        $stockRow = DB::query()
            ->fromSub($products, 'inventory_products')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(current_stock), 0) as total_stock')
            ->selectRaw('SUM(CASE WHEN current_stock <= low_stock_threshold THEN 1 ELSE 0 END) as low_stock_count')
            ->first();

        $movementRow = StockMovement::query()->forBusiness($businessId)
            ->selectRaw('COUNT(*) as movement_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) as total_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) as total_out')
            ->first();

        return [
            'product_count' => (int) $stockRow->product_count,
            'total_stock' => Quantity::format($stockRow->total_stock),
            'low_stock_count' => (int) $stockRow->low_stock_count,
            'stock_movement_count' => (int) $movementRow->movement_count,
            'total_stock_in' => Quantity::format($movementRow->total_in),
            'total_stock_out' => Quantity::format($movementRow->total_out),
            'source' => 'stock_movements',
        ];
    }

    /**
     * Today's headline figures, in the *business* timezone.
     *
     * Today-only by construction: the window is derived here and the request's
     * own `date_from` / `date_to` are ignored, because a "today" screen that
     * silently honoured a different range would be lying about its own title. The
     * controller still validates the pair, so a malformed request is a 422
     * rather than a silent no-op.
     *
     * "Today" is resolved in the business's timezone, not the application's,
     * because the cashier standing in that shop at 23:50 is having that day, not
     * UTC's. That day string is then handed to the same
     * {@see ReportDateRange} every other report uses, so a dashboard's today is
     * exactly a one-day report window.
     *
     * Every figure is delegated — the dashboard computes nothing itself, so its
     * semantics are by construction those of `sales()`, `purchases()`,
     * `expenses()` and `inventory()`. `today_net_sales` in particular inherits
     * the gross-minus-returns reading. `current_cash_balance` is the *open*
     * register's balance and is `'0.00'` when no drawer is open, not an error.
     */
    public function dashboard(int $businessId, int $userId): array
    {
        $timezone = Business::query()->whereKey($businessId)->value('timezone') ?? config('app.timezone');
        $today = Carbon::now($timezone)->toDateString();
        $filters = ['date_from' => $today, 'date_to' => $today];
        $sales = $this->sales($businessId, $filters);
        $purchases = $this->purchases($businessId, $filters);
        $expenses = $this->expenses($businessId, $filters);
        $inventory = $this->inventory($businessId);
        $register = $this->cashRegisters->currentOrNull($businessId, $userId);

        return [
            'date' => $today,
            'today_sale_count' => $sales['metrics']['sale_count'],
            'today_total_sales' => $sales['metrics']['total_sales'],
            'today_net_sales' => $sales['metrics']['net_sales'],
            'today_returns_count' => $sales['metrics']['returns_count'],
            'today_returns_amount' => $sales['metrics']['returns_amount'],
            'today_purchase_count' => $purchases['metrics']['purchase_count'],
            'today_purchase_total' => $purchases['metrics']['purchase_total'],
            'today_expense_count' => $expenses['metrics']['expense_count'],
            'today_total_expense' => $expenses['metrics']['total_expense'],
            'current_cash_balance' => $register?->current_balance ?? '0.00',
            'low_stock_count' => $inventory['low_stock_count'],
        ];
    }

    /**
     * Who owes whom, right now — the customer receivables / credit listing.
     *
     * ## The ledger is the only thing read
     *
     * Every balance below is `SUM(customer_ledger_entries.amount)` grouped by
     * customer. `sales.due_amount` is never touched: it is maintained in one
     * direction only (a later payment revises it, a later *return* never does),
     * so summing it would report money the business already has. That is the
     * same reasoning {@see customerOutstanding()} already applies to the sales
     * report's single `customer_outstanding` figure; this endpoint breaks that
     * one number out per customer.
     *
     * Nothing here re-derives a balance from sales and payments, and nothing
     * re-applies a refund: `SaleReturnService` records a `sale_return` entry for
     * only the part of a return no refund method could cover, and
     * `PaymentService` caps a later payment by the returns that already relieved
     * that invoice — so every movement is applied exactly once at write time and
     * summing the column is the complete answer.
     *
     * ## Current balance is NOT a period figure
     *
     * This is the distinction the whole report turns on. A balance is a standing
     * position — everything the ledger has ever recorded — so it is deliberately
     * **never** narrowed by `date_from` / `date_to`. Filtering that sum by a
     * window would answer a different and wrong question ("what did they owe as
     * of the end of this month"), because a payment made last month reduces the
     * debt today whether or not it falls inside the window.
     *
     * `period_movement` is the separate, dated question: how much the ledger
     * moved *within* the window, summed from those same entries but filtered on
     * their own `created_at`. It is published as its own field and is never
     * substituted for `balance`.
     *
     *
     * ## What "as of a past date" would need, and why it is not published
     *
     * `customer_ledger_entries` does carry a usable transaction timestamp
     * (`created_at`, always written by the recording flow and never back-dated by
     * any code in the project), so a point-in-time balance *is* derivable from
     * the current schema — a cumulative sum bounded by `created_at < to + 1
     * day`. It is deliberately **not** exposed here: the window a client sends
     * is a reporting period, not an "as at" date, and presenting a movement
     * figure as though it were a balance is exactly the confusion this method
     * exists to prevent. Recorded as a known, deliberately unexposed capability
     * rather than an omission, and no migration is added to enable it.
     *
     * ## Classification is the ledger's own sign convention
     *
     * **Positive = the customer owes the business. Negative = the business owes
     * the customer.** That is `CustomerLedgerEntry`'s documented rule, applied
     * unchanged — a return larger than the debt carries the balance negative,
     * and it is reported as customer credit rather than suppressed or shown as a
     * negative receivable.
     *
     * The summary keeps the two directions apart rather than netting them into
     * one figure: `receivable` is the sum of positive balances,
     * `customer_credit` is the *magnitude* of the negative ones, and
     * `net_outstanding` is their difference. A business with 3,000 owed to it
     * and 500 of credit held reports both plus the net — never one number that
     * has quietly swallowed a liability.
     *
     * ## Zero balances are omitted, and the totals are unaffected either way
     *
     * A settled customer is not outstanding, so they are filtered out of
     * `customers[]` by `HAVING balance != 0`. They contribute nothing to
     * `receivable` or `customer_credit`, so the summary is identical with or
     * without them. The project has no competing convention here — the other
     * customer-facing report, {@see topCustomers()}, is a ranking that has rows
     * only for customers who transacted at all — and an outstanding list padded
     * with settled accounts would make the count beside the money meaningless.
     *
     * ## Ordering
     *
     * Positive receivables first and largest first, then customer credit largest
     * first, then `customers.id` ascending as the tie-break. The sign split comes
     * first so money the business is owed is never buried under money it owes,
     * and each direction is ordered by size via `ABS(...)` of the aggregate — a plain
     * `balance DESC` would sort the credits by the raw number and leave the
     * smallest credit at the top. The id tie-break makes the order total: two
     * customers can never swap places between two identical requests.
     *
     * The aggregate is written out rather than referenced by its `balance` alias
     * in `HAVING` and `ORDER BY`. MySQL rejects a group-function alias used inside
     * another expression ("Reference 'balance' not supported") while SQLite
     * accepts it, so the shorthand would pass this whole test suite and then fail
     * against the project's real driver. One `$balance` string feeds all of them.
     *
     *
     * ## Tenant isolation is stated on both sides of the join
     *
     * `customer_ledger_entries.business_id` is scoped, **and** the joined
     * `customers` row is independently pinned to the same business.
     * `customer_ledger_entries.customer_id` is a plain foreign key with no
     * composite constraint tying it to the entry's own tenant, so a corrupted or
     * hand-edited row can point a local entry at another business's customer. The
     * ledger scope alone would then group that entry and print **the other
     * business's customer id, name and balance** here. The inner join with
     * `customers.business_id = $businessId` drops such a row entirely rather than
     * attributing it to somebody, and the summary — aggregated from the same
     * builder — excludes its amount along with it.
     *
     * Soft-deleted customers are excluded via `deleted_at IS NULL`, matching
     * {@see topCustomers()}: `Customer` soft-deletes, and a report must agree
     * with the customer screen about whether a removed customer still exists.
     *
     * ## No walk-in bucket, because the schema cannot hold one
     *
     * `customer_ledger_entries.customer_id` is a **non-nullable** foreign key and
     * every write path resolves the customer through
     * `Customer::query()->forBusiness($businessId)`. A sale with no customer
     * therefore writes no ledger row at all (see `SaleService::create()`), so
     * there is nothing here to report and no reserved row to invent. No "Walk-in
     * Customer" placeholder is fabricated.
     *
     *
     * @param  array<string, mixed>  $filters
     */
    public function customerOutstandingReport(int $businessId, array $filters): array
    {
        $range = ReportDateRange::fromFilters($filters);
        [$movementPredicate, $movementBindings] = $this->periodMovementWindow($range);

        // The signed sum, named once and reused everywhere the balance is needed
        // *as an expression*.
        //
        // MySQL will not let a `GROUP BY` alias be referenced from inside another
        // expression — `HAVING balance != 0` and `ORDER BY CASE WHEN balance < 0 …`
        // both fail with "Reference 'balance' not supported (reference to group
        // function)" — while SQLite accepts both. Repeating the aggregate keeps the
        // query correct on the project's real driver, and the repetition is
        // textual rather than a second calculation, so the alias and the clauses
        // cannot drift: they are built from this one string.
        $balance = 'COALESCE(SUM(customer_ledger_entries.amount), 0)';

        // The per-customer balances, as one grouped aggregate. `created_at` is the
        // entry's own transaction timestamp, so the movement column is a genuine
        // dated sum of the *same* rows the balance is summed from — the two can
        // never describe different populations.
        $balances = CustomerLedgerEntry::query()
            ->forBusiness($businessId)
            ->toBase()
            // An inner join on the customer, plus the independent tenant guard
            // described above. Both are required: the ledger scope alone does not
            // stop a local entry from naming a foreign customer.
            ->join('customers', 'customers.id', '=', 'customer_ledger_entries.customer_id')
            ->where('customers.business_id', $businessId)
            ->whereNull('customers.deleted_at')
            ->selectRaw('customers.id as customer_id')
            ->selectRaw('customers.name as customer_name')
            ->selectRaw($balance.' as balance')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN '.$movementPredicate.' THEN customer_ledger_entries.amount ELSE 0 END), 0) as period_movement',
                $movementBindings
            )
            // Grouping by the id as well as the name keeps this valid under
            // `ONLY_FULL_GROUP_BY` without relying on functional dependency, and
            // is identical on SQLite.
            ->groupBy('customers.id', 'customers.name');

        if (($filters['customer_id'] ?? null) !== null) {
            $balances->where('customer_ledger_entries.customer_id', $filters['customer_id']);
        }

        // The listing. The `HAVING` drops settled customers in the database, so a
        // business with ten thousand dormant accounts costs the same single round
        // trip as one with three.
        //
        // The summary is aggregated over the *same* grouped builder as a subquery,
        // so `receivable` / `customer_credit` cover **every** qualifying customer
        // and not merely the rows a listing would show. The two are the same set
        // today and stay the same set if a page size is ever introduced. Inside the
        // subquery `balance` is a real column of that derived table, so referencing
        // it there is ordinary SQL and needs no repetition.
        $outstanding = (clone $balances)->havingRaw($balance.' != 0');

        $summary = DB::query()
            ->fromSub($outstanding, 'outstanding_balances')
            ->selectRaw('COUNT(*) as customer_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as receivable')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) as customer_credit')
            ->selectRaw('COALESCE(SUM(period_movement), 0) as period_movement')
            ->first();

        $receivable = Money::format($summary->receivable);
        $customerCredit = Money::format($summary->customer_credit);

        // The listing, ordered off the same grouped aggregate the summary used.
        $rows = (clone $outstanding)
            // The sign split comes first so money the business is owed is never
            // buried under money it owes; `ABS()` then orders **each** direction by
            // size. A plain `balance DESC` would sort the credits by the raw number,
            // putting a -125 credit above a -750 one — the largest credit last,
            // where the smallest liability reads as the biggest.
            //
            // The aggregate is repeated rather than its alias referenced, for the
            // MySQL reason given above: `balance` is a group-function alias and
            // cannot appear inside another expression.
            ->orderByRaw('CASE WHEN '.$balance.' < 0 THEN 1 ELSE 0 END')
            ->orderByRaw('ABS('.$balance.') DESC')
            // The id tie-break makes the order total: two customers on the same
            // figure can never swap places between two identical requests.
            ->orderBy('customers.id')
            ->get()
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'customer_name' => (string) $row->customer_name,
                // The ledger's own sign convention, preserved verbatim rather than
                // made absolute: a negative balance must still read as negative
                // here, or the row would contradict the ledger it came from.
                'balance' => Money::format($row->balance),
                // Named, not inferred: the client must never have to test a sign to
                // decide whether a row is a debt or a credit.
                'status' => Money::isNegative($row->balance) ? 'customer_credit' : 'receivable',
                'period_movement' => Money::format($row->period_movement),
            ])->values()->all();

        return [
            'period' => ['from' => $range->from(), 'to' => $range->to()],
            'metrics' => [
                // Customers with a non-zero balance — exactly the rows below.
                'customer_count' => (int) $summary->customer_count,
                'receivable' => $receivable,
                // Reported as a magnitude, never as a negative "receivable":
                // credit the business holds is a liability, not negative income.
                'customer_credit' => $customerCredit,
                'net_outstanding' => Money::subtract($receivable, $customerCredit),
                // The dated movement, kept apart from the balance it can never be
                // mistaken for. With no window sent the predicate is `1 = 1`, so
                // this is the whole-history movement.
                'period_movement' => Money::format($summary->period_movement),
                'customers' => $rows,
            ],
        ];
    }

    /**
     * What actually moved in and out of the cash drawers, and when.
     *
     * ## The source of truth is `cash_register_transactions`, and only that
     *
     * Every figure below is aggregated over the persisted register rows. Nothing
     * is re-derived from `sales`, `payments`, `expenses`, `sale_returns` or
     * `customer_ledger_entries`, because those tables record *business* activity
     * while this table records *physical cash*, and the two are not the same
     * population:
     *
     *  - a card / bank / cheque **sale** produces a `Payment` and **no** drawer
     *    movement — {@see PaymentService::recordForSale()} posts a register row
     *    only when `payment_method === cash`;
     *  - a card / bank **refund** is a `Payment` only. `SaleReturnService`
     *    posts a `refund` row for the cash allocation alone, so a return settled
     *    by transfer cannot become a cash-out here just because a `SaleReturn`
     *    exists;
     *  - a bank-paid **expense** is an `Expense` and no drawer movement
     *    ({@see ExpenseService::postCashExpense()} checks the method too);
     *  - a **credit** sale never touches the drawer at all — it is a
     *    `customer_ledger_entries` matter and is absent from this report by
     *    construction, not by exclusion rule.
     *
     * Re-deriving any of those here would report money that never went near the
     * drawer. The register rows are the only place the *movement* is written, so
     * they are the only honest source for this question.
     *
     * ## Direction comes from the type, never from the sign of `amount`
     *
     * `amount` is stored as an **unsigned magnitude** — a `cash_out` of 500 is
     * stored `500.00`, not `-500.00` — which is why
     * {@see CashRegisterTransaction::INFLOW_TYPES} / {@see self::OUTFLOW_TYPES}
     * exist and why `CashRegisterService::withFlowTotals()` sums with
     * `CASE WHEN type IN (…)`. Classification therefore reads the same two
     * constants the drawer balance itself is derived from. Inferring direction
     * from the sign would read every outflow as an inflow, and it would do so
     * consistently, so the net figure would come out *doubled* rather than
     * obviously wrong.
     *
     * The `IN (…)` placeholder lists are **built from the constants**, never
     * written out by hand — a literal `IN (?, ?)` binds two values, so the moment
     * a third type joins one of them the driver runs out of placeholders and the
     * read fails outright. Same reasoning as
     * {@see CashRegisterService::withFlowTotals()}.
     *
     * ## `opening` and `closing` are markers, not movements
     *
     * Both rows record a *balance* at a point in the shift
     * ({@see CashRegisterService::open()} / {@see self::close()}), and neither is
     * in `INFLOW_TYPES` or `OUTFLOW_TYPES`, so the drawer's own arithmetic has
     * already excluded them. Opening a drawer does not bring banknotes *into* the
     * period's cash flow — the float was counted, not collected — and closing it
     * does not remove money, it reconciles what is there.
     *
     * They are still **listed** (they are real rows with a real timestamp and are
     * part of the drawer history) and they are still **counted** by
     * `transaction_count`, which is a count of register rows and not of movements.
     * They contribute nothing to `total_cash_in` / `total_cash_out`, and the
     * `by_type` breakdown reports them as `direction: "non_movement"` so a client
     * can never sum them into a total. No `opening_balance` / `closing_balance`
     * figure is published: those live on `cash_registers` as per-register shift
     * fields, not as period figures, and inventing one from the movement rows
     * would be wrong whenever a register is closed and reopened mid-period.
     *
     * ## Manual vs automatic is read off the schema, not inferred
     *
     * {@see CashRegisterTransaction::MANUAL_TYPES} (`cash_in` / `cash_out`) is
     * exactly what {@see CashRegisterService::recordManual()} writes, and
     * {@see self::AUTOMATIC_TYPES} (`sale` / `expense` / `refund`) is exactly what
     * `postAutomaticTransaction()` whitelists. The remaining two are the
     * open/close markers. `origin` therefore restates an existing boundary; no
     * new value is persisted and none is invented.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function cashRegisterMovements(int $businessId, array $filters): array
    {
        $range = ReportDateRange::fromFilters($filters);

        // One builder, three aggregates. Each is `clone`d *before* any selectRaw
        // mutates it in place, so all three describe exactly the same population
        // — there is no second interpretation of "the movements in scope".
        $scope = $this->scopedCashRegisterMovements($businessId, $filters);

        // 1. The period summary. One grouped aggregate over the whole filtered
        //    set — never over a page of it — so a total can never disagree with
        //    the sum of the rows beside it.
        $inflowPlaceholders = $this->cashTypePlaceholders(CashRegisterTransaction::INFLOW_TYPES);
        $outflowPlaceholders = $this->cashTypePlaceholders(CashRegisterTransaction::OUTFLOW_TYPES);
        $markerPlaceholders = $this->cashTypePlaceholders(CashRegisterTransaction::NON_MOVEMENT_TYPES);

        $summary = (clone $scope)
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_register_transactions.type IN ('.$inflowPlaceholders.') THEN cash_register_transactions.amount ELSE 0 END), 0) as total_cash_in',
                CashRegisterTransaction::INFLOW_TYPES
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_register_transactions.type IN ('.$outflowPlaceholders.') THEN cash_register_transactions.amount ELSE 0 END), 0) as total_cash_out',
                CashRegisterTransaction::OUTFLOW_TYPES
            )
            // The non-monetary markers are counted but never summed. They are a
            // real count of register rows in the period, and reporting them keeps
            // `transaction_count` reconcilable with the listing; calling one of
            // them a cash *direction* would be a lie, so they get their own figure.
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_register_transactions.type IN ('.$markerPlaceholders.') THEN 1 ELSE 0 END), 0) as non_movement_count',
                CashRegisterTransaction::NON_MOVEMENT_TYPES
            )
            ->first();

        $totalCashIn = Money::format($summary->total_cash_in);
        $totalCashOut = Money::format($summary->total_cash_out);

        return [
            'period' => ['from' => $range->from(), 'to' => $range->to()],
            'metrics' => [
                'total_cash_in' => $totalCashIn,
                'total_cash_out' => $totalCashOut,
                // Named and defined by arithmetic, not inferred from a balance:
                // `total_cash_in - total_cash_out`.
                'net_cash_movement' => Money::subtract($totalCashIn, $totalCashOut),
                // Every register row in the period, markers included. This is a
                // count of *rows*, and the two figures beside it say how many of
                // those rows moved money, so it can never be misread as a movement
                // count it is not.
                'transaction_count' => (int) $summary->transaction_count,
                'cash_movement_count' => (int) $summary->transaction_count - (int) $summary->non_movement_count,
                // The `opening` / `closing` markers: real rows, no cash direction.
                'non_movement_count' => (int) $summary->non_movement_count,
                'by_type' => $this->cashMovementsByType(clone $scope),
                'movements' => $this->cashMovementRows(clone $scope),
            ],
        ];
    }

    /**
     * The period's movements aggregated by canonical transaction type.
     *
     * Grouped in SQL, so the whole taxonomy costs one query however many types
     * exist. The order is deterministic — largest movement first, then the type
     * name — so two identical requests cannot return the rows in whatever order
     * the driver happened to choose, which an implicit row order would leave to
     * chance.
     *
     * The `type` on each row is the stored value verbatim; the `label` is a
     * display convenience over a fixed map, and the `direction` comes from the
     * schema's own constants. None of the three is decided per row.
     */
    private function cashMovementsByType($scope): array
    {
        return (clone $scope)
            ->selectRaw('cash_register_transactions.type')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(cash_register_transactions.amount), 0) as amount')
            ->groupBy('cash_register_transactions.type')
            ->orderByDesc('amount')
            ->orderBy('cash_register_transactions.type')
            ->get()
            ->map(fn (object $row): array => [
                'type' => (string) $row->type,
                'label' => $this->cashMovementLabel((string) $row->type),
                'direction' => $this->cashMovementDirection((string) $row->type),
                'origin' => $this->cashMovementOrigin((string) $row->type),
                'transaction_count' => (int) $row->transaction_count,
                // A magnitude, always: the sign is carried by `direction`, which
                // is the one rule this schema can state honestly.
                'amount' => Money::format($row->amount),
            ])->values()->all();
    }

    /**
     * The period's movement history, newest first.
     *
     * The order matches {@see CashRegisterService::transactions()} exactly —
     * `created_at` descending with `id` descending as the tie-break — so the
     * drawer screen and this report list the same movements in the same
     * sequence. The id tie-break makes the order *total*: two movements written
     * in the same second can never swap places between two identical requests.
     *
     * Every joined field is selected on this one query, so naming a register or
     * an operator costs no query of its own and there is no N+1 anywhere in the
     * listing.
     *
     * Intentionally unpaginated, consistent with the other report listings:
     * `customerOutstandingReport()` and `supplierOutstandingReport()` both return
     * every qualifying row, no report in this application has pagination
     * infrastructure, and none is invented here for a single report. No hidden
     * top-N truncates the history beside a total computed over the *complete*
     * dataset. Recorded as a known limitation for very large ledgers.
     */
    private function cashMovementRows($scope): array
    {
        return (clone $scope)
            ->orderByDesc('cash_register_transactions.created_at')
            ->orderByDesc('cash_register_transactions.id')
            ->get([
                'cash_register_transactions.id',
                'cash_register_transactions.cash_register_id',
                'cash_register_transactions.type',
                'cash_register_transactions.amount',
                'cash_register_transactions.reference_type',
                'cash_register_transactions.reference_id',
                'cash_register_transactions.note',
                'cash_register_transactions.created_at',
                'cash_registers.id as register_id',
                'cash_registers.status as register_status',
                'cash_registers.opened_at as register_opened_at',
                'cash_registers.opening_balance as register_opening_balance',
                'users.id as operator_id',
                'users.name as operator_name',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                // The movement's own timestamp is `created_at`: this schema has no
                // separate `occurred_at`, and inventing one from a joined document
                // date would re-derive the movement from elsewhere.
                'occurred_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'cash_register_id' => (int) $row->cash_register_id,
                'type' => (string) $row->type,
                'label' => $this->cashMovementLabel((string) $row->type),
                'direction' => $this->cashMovementDirection((string) $row->type),
                'origin' => $this->cashMovementOrigin((string) $row->type),
                'amount' => Money::format($row->amount),
                // `reference_type` / `reference_id` are echoed exactly as stored.
                // The morph class is a model name (`Payment`, `Expense`,
                // `SaleReturn`) and no column anywhere holds a human label for the
                // referenced document, so no description is manufactured from it.
                'reference_type' => $row->reference_type === null ? null : (string) $row->reference_type,
                'reference_id' => $row->reference_id === null ? null : (int) $row->reference_id,
                // The note is written by the API or the cashier, and is passed
                // through only when there is one.
                'note' => $row->note === null ? null : (string) $row->note,
                // Suppressed, not omitted, when the operator is foreign or gone:
                // the movement is still this business's, only the person behind it
                // is not disclosed.
                'created_by' => $row->operator_id === null ? null : [
                    'id' => (int) $row->operator_id,
                    'name' => (string) $row->operator_name,
                ],
                'register' => [
                    'id' => (int) $row->register_id,
                    // `cash_registers` has **no** name, label or title column
                    // anywhere in the schema, so the register is identified by its
                    // id and its own stored state. A register name is not invented.
                    'status' => $row->register_status === null ? null : (string) $row->register_status,
                    'opened_at' => $row->register_opened_at === null
                        ? null
                        : Carbon::parse($row->register_opened_at)->toIso8601String(),
                    // The float the drawer was opened with. It is deliberately
                    // **not** added to `total_cash_in`: it is a starting balance,
                    // not a collection, and counting it would make the period's
                    // cash-in disagree with `CashRegisterService::balance()`, which
                    // adds it separately.
                    'opening_balance' => $row->register_opening_balance === null
                        ? null
                        : Money::format($row->register_opening_balance),
                ],
            ])->values()->all();
    }

    /**
     * The cash-register movements in scope, with tenancy guarded on both sides.
     *
     * The single definition of "the movements this report covers" — the summary,
     * the breakdown and the listing are all `clone`s of this builder, so none of
     * the three can drift onto a different population, and a filter applied here
     * binds all three at once.
     *
     * ## Why the register join is an inner join carrying its own tenant guard
     *
     * `cash_register_transactions.cash_register_id` is a plain foreign key with no
     * composite constraint tying it to the row's own `business_id`. A row written
     * by one business can therefore name another business's register, and scoping
     * the ledger side alone would report that foreign register's id and operator
     * against this business's money. Requiring `cash_registers.business_id =
     * $businessId` inside an **inner** join drops such a row entirely — from the
     * summary and the breakdown as well as the listing, since all three share
     * this builder, so a hostile row cannot inflate a total either.
     *
     * ## Why the `users` join is a guarded LEFT join
     *
     * A movement whose `created_by` names a foreign user (or nobody — the column
     * is `nullOnDelete`) is still this business's cash, so the row must survive.
     * The join is therefore left, and carries `users.business_id = $businessId`
     * in its own `ON` clause rather than in the `WHERE`: a row whose operator is
     * out of tenant then matches no user row and its `created_by` comes back
     * null — suppressed rather than attributed to a stranger, and without
     * dropping the money.
     *
     * ## Date semantics
     *
     * The window is applied to `created_at`, the moment the movement was recorded
     * and the only movement timestamp the schema has, through the shared
     * {@see ReportDateRange} half-open rule — inclusive `date_from`, inclusive
     * `date_to` applied as `[from, to + 1 day)`. Unlike Stage 7/8 this report *is*
     * a period activity report, so the window genuinely restricts the population
     * rather than merely travelling beside it.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Query\Builder
     */
    private function scopedCashRegisterMovements(int $businessId, array $filters)
    {
        $query = CashRegisterTransaction::query()
            ->forBusiness($businessId)
            ->toBase()
            ->join('cash_registers', 'cash_registers.id', '=', 'cash_register_transactions.cash_register_id')
            ->where('cash_registers.business_id', $businessId)
            ->leftJoin('users', function ($join) use ($businessId): void {
                $join->on('users.id', '=', 'cash_register_transactions.created_by')
                    ->where('users.business_id', '=', $businessId);
            });

        ReportDateRange::fromFilters($filters)
            ->apply($query, 'cash_register_transactions.created_at');

        if (($filters['cash_register_id'] ?? null) !== null) {
            $query->where('cash_register_transactions.cash_register_id', $filters['cash_register_id']);
        }

        // Validated against `CashRegisterTransaction::TYPES` by
        // `ReportFilterRequest`, so an unknown type is a 422 rather than a filter
        // that silently matches nothing.
        if (($filters['transaction_type'] ?? null) !== null) {
            $query->where('cash_register_transactions.type', $filters['transaction_type']);
        }

        return $query;
    }

    /**
     * One `?` per bound value, for a `CASE WHEN type IN (…)` sum over `$types`.
     *
     * The count is derived from the very array that is bound as the values at the
     * call site, so the placeholder list can never drift away from the bindings.
     * A hand-written `IN (?, ?)` binds two values, and the moment a third type
     * joins `INFLOW_TYPES` or `OUTFLOW_TYPES` — as `refund` did for the outflows —
     * every binding after the mismatch shifts by one and the driver fails the whole
     * read with "column index out of range", or worse on a driver that tolerates
     * it, silently binds `opening` where `closing` was meant. That is the exact
     * failure `CashRegisterService::withFlowTotals()` documents.
     *
     * @param  array<int, string>  $types
     */
    private function cashTypePlaceholders(array $types): string
    {
        // The empty case is guarded so a future constant producing no types cannot
        // render the syntactically invalid `IN ()`.
        return $types === [] ? 'NULL' : implode(', ', array_fill(0, count($types), '?'));
    }

    /**
     * Which way this type moves the drawer.
     *
     * Read off `INFLOW_TYPES` / `OUTFLOW_TYPES` — the same two constants
     * `CashRegisterService::withFlowTotals()` derives the drawer balance from —
     * so this report and the drawer itself can never classify one row
     * differently.
     *
     * `non_movement` is returned for the `opening` / `closing` markers, which are
     * deliberately in neither list. It is a **third** value rather than being
     * folded into either direction, because a client summing `by_type` by
     * direction would otherwise add a shift's opening float to the period's
     * cash-in and contradict `CashRegisterService::balance()`.
     */
    private function cashMovementDirection(string $type): string
    {
        return match (true) {
            in_array($type, CashRegisterTransaction::INFLOW_TYPES, true) => 'in',
            in_array($type, CashRegisterTransaction::OUTFLOW_TYPES, true) => 'out',
            default => 'non_movement',
        };
    }

    /**
     * Who wrote this movement: a human, a document flow, or the drawer itself.
     *
     * A *display* classification only — nothing is persisted and no new value is
     * invented. It restates boundaries the schema already defines:
     * {@see CashRegisterTransaction::MANUAL_TYPES} is exactly what
     * `CashRegisterService::recordManual()` writes,
     * {@see self::AUTOMATIC_TYPES} is exactly what `postAutomaticTransaction()`
     * whitelists, and the remaining two types are the open/close markers.
     */
    private function cashMovementOrigin(string $type): string
    {
        return match (true) {
            in_array($type, CashRegisterTransaction::MANUAL_TYPES, true) => 'manual',
            in_array($type, CashRegisterTransaction::AUTOMATIC_TYPES, true) => 'automatic',
            default => 'non_movement',
        };
    }

    /**
     * A display label for one canonical type.
     *
     * Every key is a member of {@see CashRegisterTransaction::TYPES}. The map is
     * a class constant so the labels cannot drift between the two methods that
     * publish them, and {@see self::cashMovementLabel()} falls back to the raw
     * value for anything the map does not know — a row written by a future
     * migration is then labelled honestly rather than hidden or guessed at.
     *
     * @var array<string, string>
     */
    private const CASH_MOVEMENT_LABELS = [
        CashRegisterTransaction::TYPE_SALE => 'Sale',
        CashRegisterTransaction::TYPE_REFUND => 'Refund',
        CashRegisterTransaction::TYPE_EXPENSE => 'Expense',
        CashRegisterTransaction::TYPE_CASH_IN => 'Cash In',
        CashRegisterTransaction::TYPE_CASH_OUT => 'Cash Out',
        CashRegisterTransaction::TYPE_OPENING => 'Opening Float',
        CashRegisterTransaction::TYPE_CLOSING => 'Closing Count',
    ];

    /** The display label for one stored type, falling back to the raw value. */
    private function cashMovementLabel(string $type): string
    {
        return self::CASH_MOVEMENT_LABELS[$type] ?? $type;
    }

    /**
     * Who owes whom, right now — the supplier payables / credit listing.
     *
     * ## The source of truth, and why it is not a ledger
     *
     * There is **no** `supplier_ledger_entries` table, and this report does not
     * invent one. What it sums is the pair of columns the purchase flow itself
     * maintains, and that is a *complete* answer on this schema rather than an
     * approximation of one:
     *
     *     balance = suppliers.opening_balance + SUM(purchases.due_amount)
     *
     * Stage 7 had to build a ledger because `sales.due_amount` is maintained in
     * **one direction only** — a later payment revises it, a later *return* never
     * does — so a return left the invoice column crediting money the business had
     * already refunded. The supplier side has no such gap. `purchases.due_amount`
     * is recomputed server-side by `PurchaseService::create()` and again by
     * `update()` (`due = grand_total − paid_amount`), and the *only* two things
     * that move it are exactly the two things that rewrite it. There is no
     * supplier return, credit note or debit note anywhere in the schema, so no
     * document can relieve a purchase without `due_amount` following it. The
     * column is therefore a current outstanding figure, not a historical
     * snapshot, which is precisely the property that disqualified the customer
     * `payments.purchase_id` is **deliberately not read**. The column and the
     * `Purchase::payments()` relation exist, but no write path ever fills them —
     * `PaymentService::recordForSale()` hard-codes `'purchase_id' => null` and
     * there is no purchase-payment endpoint — so summing it would add a column
     * that is zero everywhere while implying a second source of truth that does
     * not exist. Recorded as a known limitation, not papered over.
     *
     * `suppliers.opening_balance` is the standing figure the account was opened
     * with and is counted **once**, in the sum above. It is not added a second
     * time anywhere, exactly as Stage 7 established for the customer side.
     *
     * ## Classification is the column's own sign
     *
     * **Positive = the business owes the supplier. Negative = the supplier owes
     * the business.** The same one-rule convention as the customer ledger, read
     * off the arithmetic rather than off a per-type special case.
     *
     * A negative balance is **not currently producible through the API**:
     * `StorePurchaseRequest` and `PurchaseService::create()` both cap `paid_amount`
     * at `grand_total`, so `due_amount` is never below zero, and
     * `opening_balance` is validated `min:0`. The `supplier_credit` figures are
     * therefore structurally `0.00` today. They are computed and published
     * anyway, from the same `CASE WHEN balance < 0` rule that classifies the rows,
     * so the report stays *correct* rather than merely *currently right* — and so
     * a negative `decimal(15,2)` that reaches the database by any other route is
     * reported as credit instead of being silently reported as a payable. No
     * write path is invented to make it occur.
     *
     * ## Current balance is NOT a period figure
     *
     * The same distinction Stage 7 turned on, and for the same reason. A balance
     * is a standing position over the whole history, so it is deliberately never
     * narrowed by `date_from` / `date_to`. Filtering this sum by a window would
     * answer "what did we owe as at the end of this month", which is a different
     * question, and one this report does not claim to answer.
     *
     * `period_movement` is the dated figure and it is published under its own
     * name: **the payables raised in the window**, being
     * `SUM(purchases.due_amount)` over purchases whose own `purchase_date` falls
     * inside it. That is genuinely dated and genuinely windowable.
     *
     * It is *not* a net movement, and the difference is stated rather than
     * smoothed over: settlement is recorded as a revision of the
     * `purchases.due_amount` invoice column rather than as a dated document of
     * its own, so a later edit to an **older** invoice's `paid_amount` revises
     * today's balance but carries no movement of its own inside the window. With
     * no window sent the predicate is `1 = 1`, so the figure is the whole-history
     * one — the honest reading of an unbounded request, not a default period.
     *
     * ## Zero balances are omitted and cannot move a total
     *
     * A settled supplier is not outstanding, so `HAVING … != 0` drops them in the
     * database. They contribute nothing to `payable` or `supplier_credit`, so the
     * summary is identical with or without them — identical to the treatment
     * {@see topCustomers()} already gives the customer side.
     *
     * ## A supplier with no purchase still appears
     *
     * The join is a **LEFT** join from `suppliers`, so an account opened with a
     * non-zero `opening_balance` and no purchase against it yet is a genuine
     * payable and is listed. An inner join would silently report such a supplier
     * as settled, which is the one direction that would understate what the
     * business owes.
     *
     * Purchases with a `NULL` `supplier_id` belong to no supplier and are outside
     * the register entirely — there is no "unassigned" bucket to report them
     * under, so none is fabricated.
     *
     * ## Tenant isolation is stated on both sides of the join
     *
     * `suppliers` is scoped through {@see Supplier::scopeForBusiness()} — which
     * also supplies the `deleted_at IS NULL` soft-delete condition — **and** the
     * joined `purchases` rows carry `purchases.business_id = $businessId` inside
     * the join condition. Both are required. `purchases.supplier_id` is a plain
     * foreign key with no composite constraint tying it to the purchase's own
     * tenant, so a foreign purchase can name a local supplier: the supplier scope
     * alone would then group that purchase and print **another business's money**
     * against this business's supplier. The join-side guard drops such a row
     * entirely, and the summary — aggregated from the same builder — excludes its
     * amount along with it.
     *
     * ## The aggregate is written out rather than referenced by its alias
     *
     * MySQL rejects a group-function alias used inside another expression
     * ("Reference 'balance' not supported") while SQLite accepts it, so the
     * shorthand would pass the whole suite and then fail against the project's
     * real driver. One `$balance` string feeds `HAVING` and `ORDER BY`, so the
     * alias and the clauses cannot drift apart.
     *
     * @param  array<string, mixed>  $filters
     */
    public function supplierOutstandingReport(int $businessId, array $filters): array
    {
        $range = ReportDateRange::fromFilters($filters);
        [$movementPredicate, $movementBindings] = $this->periodMovementWindow($range, 'purchases.purchase_date');

        // The signed balance, named once and reused everywhere it is needed as an
        // *expression* rather than as a `GROUP BY` alias — see the MySQL note above.
        $balance = '(suppliers.opening_balance + COALESCE(SUM(purchases.due_amount), 0))';

        // The per-supplier balances as one grouped aggregate. `purchase_date` is
        // the invoice's own business day, so the movement column is a dated sum of
        // the *same* rows the balance is summed from and the two can never be
        // describing different populations.
        $balances = Supplier::query()
            ->forBusiness($businessId)
            ->toBase()
            ->leftJoin('purchases', function ($join) use ($businessId): void {
                $join->on('purchases.supplier_id', '=', 'suppliers.id')
                    // The independent financial-side tenant guard described above.
                    // It lives in the join condition rather than in a `where` so it
                    // narrows what is summed without turning the LEFT join into an
                    // inner one — which would drop a supplier that has no purchase
                    // at all and wrongly report its opening balance as settled.
                    ->where('purchases.business_id', '=', $businessId);
            })
            ->selectRaw('suppliers.id as supplier_id')
            ->selectRaw('suppliers.name as supplier_name')
            ->selectRaw($balance.' as balance')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN '.$movementPredicate.' THEN purchases.due_amount ELSE 0 END), 0) as period_movement',
                $movementBindings
            )
            // Grouping by every non-aggregate the select list names keeps this
            // valid under `ONLY_FULL_GROUP_BY` without relying on functional
            // dependency, and is identical on SQLite.
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.opening_balance');

        if (($filters['supplier_id'] ?? null) !== null) {
            $balances->where('suppliers.id', $filters['supplier_id']);
        }

        // The listing. The `HAVING` drops settled suppliers in the database, so a
        // business with ten thousand dormant accounts costs the same single round
        // trip as one with three.
        //
        // The summary is aggregated over the *same* grouped builder as a subquery,
        // so `payable` / `supplier_credit` cover **every** qualifying supplier and
        // not merely the rows a listing would show. Inside the subquery `balance`
        // is a real column of that derived table, so referencing it there is
        // ordinary SQL and needs no repetition.
        $outstanding = (clone $balances)->havingRaw($balance.' != 0');

        $summary = DB::query()
            ->fromSub($outstanding, 'outstanding_balances')
            ->selectRaw('COUNT(*) as supplier_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as payable')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) as supplier_credit')
            ->selectRaw('COALESCE(SUM(period_movement), 0) as period_movement')
            ->first();

        // The listing, ordered off the same grouped aggregate the summary used.
        $rows = (clone $outstanding)
            // The sign split comes first so money the business owes is never buried
            // under money it is owed; `ABS()` then orders **each** direction by
            // size. A plain `balance DESC` would sort the credits by the raw number,
            // putting a -125 credit above a -750 one — the largest credit last,
            // where the smallest liability reads as the biggest.
            ->orderByRaw('CASE WHEN '.$balance.' < 0 THEN 1 ELSE 0 END')
            ->orderByRaw('ABS('.$balance.') DESC')
            // The id tie-break makes the order total: two suppliers on the same
            // figure can never swap places between two identical requests.
            ->orderBy('suppliers.id')
            ->get()
            ->map(fn (object $row): array => [
                'supplier_id' => (int) $row->supplier_id,
                'supplier_name' => (string) $row->supplier_name,
                // Signed, preserved verbatim rather than made absolute: a negative
                // balance must still read as negative here, or the row would
                // contradict the arithmetic it came from.
                'balance' => Money::format($row->balance),
                // Named, not inferred: the client must never have to test a sign to
                // decide whether a row is a payable or a credit.
                'status' => Money::isNegative($row->balance) ? 'supplier_credit' : 'payable',
                'period_movement' => Money::format($row->period_movement),
            ])->values()->all();

        // The two totals are formatted through {@see Money} once, here, so the
        // `payable` / `supplier_credit` figures and the `net_outstanding` derived
        // from them cannot disagree about rounding.
        $payable = Money::format($summary->payable);
        $supplierCredit = Money::format($summary->supplier_credit);

        return [
            'period' => ['from' => $range->from(), 'to' => $range->to()],
            'metrics' => [
                // Suppliers with a non-zero balance — exactly the rows below.
                'supplier_count' => (int) $summary->supplier_count,
                'payable' => $payable,
                // Reported as a magnitude, never as a negative "payable": credit
                // held from a supplier is an asset, not negative payables. See the
                // note above on why this is structurally 0.00 through the API today.
                'supplier_credit' => $supplierCredit,
                'net_outstanding' => Money::subtract($payable, $supplierCredit),
                // The dated movement, kept apart from the balance it can never be
                // mistaken for. With no window sent the predicate is `1 = 1`, so
                // this is the whole-history figure.
                'period_movement' => Money::format($summary->period_movement),
                'suppliers' => $rows,
            ],
        ];
    }

    /**
     * The `CASE WHEN …` predicate selecting source rows inside the window.
     *
     * The bounds are {@see ReportDateRange}'s own — inclusive `from`, and `to`
     * pushed to the following midnight so the whole of the closing day is inside
     * the window — which is why this report cannot drift from every other dated
     * report onto a driver-specific boundary. The half-open form is exact on a
     * `DATE` (`purchases.purchase_date`) and on a `DATETIME`
     * (`customer_ledger_entries.created_at`) alike, on both MySQL and SQLite, so
     * the *same* helper serves both outstanding reports rather than each
     * re-deriving the boundary its own source column needs.
     *
     * The bounds are passed as bound values rather than interpolated, so the
     * predicate cannot be reshaped by what a client sent.
     *
     * With neither bound given the window is open on both sides and the predicate
     * is unconditionally true, so the "movement" is the whole history — the
     * honest reading of an unbounded request, not a default window.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function periodMovementWindow(ReportDateRange $range, string $qualifiedDateColumn = 'customer_ledger_entries.created_at'): array
    {
        $clauses = [];
        $bindings = [];

        if ($range->from() !== null) {
            $clauses[] = $qualifiedDateColumn.' >= ?';
            $bindings[] = $range->from();
        }

        if ($range->to() !== null) {
            $clauses[] = $qualifiedDateColumn.' < ?';
            $bindings[] = $range->exclusiveUpperBound();
        }

        if ($clauses === []) {
            return ['1 = 1', []];
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * The sales invoices in scope for the given filters.
     *
     * Shared by `sales()` and `profitLoss()` so the revenue line and the cost of
     * goods are always drawn from *the same* set of invoices. If each built its
     * own query the two could drift apart, and a profit figure computed from
     * mismatched populations would be arithmetically valid and still wrong.
     *
     * ## Every column here is qualified, and that is not decoration
     *
     * This builder is not only read on its own: `top_customers()` joins
     * `customers` onto it, and `customers` has a `status` column of its own
     * alongside its `business_id` and `name`. An unqualified `where('status', …)`
     * is unambiguous while the query touches one table and *ambiguous* the moment
     * it touches two — the report would fail outright, and only on the requests
     * that both filtered by status and carried customers. Qualifying every
     * predicate here is what makes this scope safe to join, and it is exactly
     * equivalent to the unqualified form when the query does stand alone.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Sale>
     */
    private function scopedSales(int $businessId, array $filters): Builder
    {
        $query = Sale::query()->forBusiness($businessId);
        ReportDateRange::fromFilters($filters)->apply($query, 'sales.sale_date');
        if (($filters['customer_id'] ?? null) !== null) {
            $query->where('sales.customer_id', $filters['customer_id']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('sales.status', $filters['status']);
        }
        if (($filters['payment_method'] ?? null) !== null) {
            $query->whereHas('payments', fn (Builder $payments) => $payments->where('payments.payment_method', $filters['payment_method']));
        }

        return $query;
    }

    /**
     * Gross sales grouped by the invoice's own `sale_date`.
     *
     * ## It is the same population, not a second reading of it
     *
     * The builder handed in here **is** {@see scopedSales()}'s builder, cloned
     * by the caller. Tenant, `ReportDateRange` window, `customer_id`, `status`
     * and `payment_method` therefore apply to the series for exactly the same
     * reason and by exactly the same predicate they apply to `total_sales`. No
     * additional scope is written here, because adding one would be the exact
     * bug this stage is not allowed to introduce: a chart whose bars came from
     * a different population than the total printed above it.
     *
     * ## Grouping is done in the database, once
     *
     * One grouped aggregate returns one row *per day that has sales* — never a
     * query per day, and never the invoice rows themselves in PHP. Grouping on
     * `DATE(sales.sale_date)` rather than the bare column is what makes the
     * result identical on both supported drivers: MySQL stores a bare `DATE`
     * while SQLite stores the Eloquent string `'Y-m-d H:i:s'`, and the function
     * collapses both to one `'Y-m-d'` group key. `sales.sale_date` is the same
     * date field `scopedSales()` filters on, so the series and the totals agree
     * on what "the day" means.
     *
     * ## Days without sales are filled in here, not in SQL
     *
     * A calendar axis needs every day in the window, including the days with no
     * invoices. Those are generated in application code from the very same
     * {@see ReportDateRange} bounds — never by issuing a query per missing date,
     * and never by asking the database to build a calendar table. A day with no
     * matching group row is a day with no sales, so its figure is
     * `Money::format(null)` — the string `'0.00'`, in the report's own money
     * convention, not `0` and not `null`.
     *
     * When the window is open on either side (`date_from` or `date_to` absent)
     * there is no calendar to fill between two ends, so only the days that
     * actually carry sales are returned — which is the honest answer for
     * "everything, ever": an unbounded axis is not a chart, it is every day
     * since the business opened.
     *
     * ## Gross, like `total_sales`
     *
     * Returns are not netted into any point and refund payments never appear:
     * this groups `sales.grand_total` over the sales population, so the series
     * sums to exactly the `total_sales` printed beside it.
     *
     * @param  Builder<Sale>  $query  a clone of {@see scopedSales()}.
     * @param  array<string, mixed>  $filters
     * @return array<int, array{date: string, total_sales: string}>
     */
    private function dailySalesSeries(Builder $query, array $filters): array
    {
        $range = ReportDateRange::fromFilters($filters);

        $totals = (clone $query)->toBase()
            ->selectRaw('DATE(sales.sale_date) as sale_day')
            ->selectRaw('COALESCE(SUM(sales.grand_total), 0) as total_sales')
            // Raw, because `groupBy('DATE(...)')` would quote the whole thing as a
            // column identifier and fail on both drivers.
            ->groupByRaw('DATE(sales.sale_date)')
            ->orderBy('sale_day')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->sale_day => Money::format($row->total_sales),
            ])
            ->all();

        // No window at both ends: report the days that exist, in order.
        if ($range->from() === null || $range->to() === null) {
            return array_map(
                fn (string $day, string $total): array => ['date' => $day, 'total_sales' => $total],
                array_keys($totals),
                array_values($totals),
            );
        }

        $series = [];
        $last = Carbon::parse($range->to(), 'UTC');
        // `addDay()` on a plain `Y-m-d` calendar value, so the walk crosses month
        // and year boundaries without timezone arithmetic — the same reason
        // `ReportDateRange` keeps its bounds as naive calendar days.
        for ($day = Carbon::parse($range->from(), 'UTC'); $day->lte($last); $day = $day->copy()->addDay()) {
            $key = $day->toDateString();

            $series[] = [
                'date' => $key,
                'total_sales' => $totals[$key] ?? Money::format(null),
            ];
        }

        return $series;
    }

    /**
     * Gross sales grouped by the calendar month of the invoice's own `sale_date`.
     *
     * ## It is the same population, at month granularity
     *
     * The builder handed in here **is** {@see scopedSales()}'s builder, cloned by
     * the caller — the identical builder {@see dailySalesSeries()} groups. Tenant,
     * `ReportDateRange` window, `customer_id`, `status` and `payment_method`
     * therefore apply here for the same reason and by the same predicates they
     * apply to `total_sales` and to `daily_series`. Writing a second, independent
     * month query is the one bug this stage must not introduce: a monthly chart
     * whose bars came from a different population than the daily chart and the
     * total printed above them would be three figures describing three things.
     *
     * That matters most for the `payment_method` filter, which reaches
     * `payments` — a table with no `business_id` of its own. The tenant is
     * carried by the already-scoped invoice set, so a foreign payment can never
     * widen this series the way it would widen an unscoped join.
     *
     * ## Grouping is done in the database, once
     *
     * One grouped aggregate returns one row *per month that has sales* — never a
     * query per month, and never the invoice rows themselves in PHP. See
     * {@see monthKeyExpression()} for the group key and why it is written to
     * collapse to the same `'YYYY-MM'` on both supported drivers.
     *
     * ## Months without sales are filled in here, not in SQL
     *
     * A month axis has to show a month in which nothing was sold, or the chart
     * silently compresses the period and reports a busy-looking business for a
     * stretch that never traded. Those months are generated in application code
     * from the very same {@see ReportDateRange} bounds, then the grouped rows
     * are merged into them — never one query per missing month, and never a
     * calendar table in the database.
     *
     * **Partial months are included on purpose.** The axis is built from the
     * months the *requested range* touches, not from the months that fall
     * entirely inside it:
     *
     *     from = 2026-01-15, to = 2026-04-10  =>  2026-01, 2026-02, 2026-03, 2026-04
     *
     * A window of `2026-01-15 … 2026-01-20` is a January window and is reported
     * as January. Emitting only months that begin *and* end inside the window
     * would drop January entirely — the range would come back describing a
     * different period than the one the user asked for, and the total beside it
     * would be the only evidence left that anything sold.
     *
     * ## Open-ended windows return only what exists
     *
     * When the window is open on either side there is no calendar to span, so
     * only the months that actually carry sales are returned. Inventing a start
     * month or running a calendar forward to an arbitrary "now" would be
     * fabricating history; the same rule {@see dailySalesSeries()} follows, for
     * the same reason.
     *
     * ## Gross, like `total_sales`
     *
     * Returns are not netted into any month and refund payments never appear:
     * this groups `sales.grand_total` over the sales population, so the series
     * sums to exactly the `total_sales` printed beside it. Monthly *returns* and
     * monthly *net* sales are separate questions and are answered elsewhere (or
     * not at all) — never by subtracting here.
     *
     * @param  Builder<Sale>  $query  a clone of {@see scopedSales()}.
     * @param  array<string, mixed>  $filters
     * @return array<int, array{month: string, total_sales: string}>
     */
    private function monthlySalesSeries(Builder $query, array $filters): array
    {
        $range = ReportDateRange::fromFilters($filters);
        $monthKey = $this->monthKeyExpression();

        $totals = (clone $query)->toBase()
            ->selectRaw($monthKey.' as sale_month')
            ->selectRaw('COALESCE(SUM(sales.grand_total), 0) as total_sales')
            // Raw, because `groupBy('…')` would quote the whole expression as a
            // column identifier and fail on both drivers — the same reason the
            // daily series groups raw.
            ->groupByRaw($monthKey)
            ->orderBy('sale_month')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->sale_month => Money::format($row->total_sales),
            ])
            ->all();

        // No window at both ends: report the months that exist, in order.
        if ($range->from() === null || $range->to() === null) {
            return array_map(
                fn (string $month, string $total): array => ['month' => $month, 'total_sales' => $total],
                array_keys($totals),
                array_values($totals),
            );
        }

        $series = [];
        // Both ends are *months the window touches*, not full months: the first
        // month of `2026-01-15 … 2026-04-10` is January even though the window
        // never sees January 1st, and the last is April even though it stops on
        // the 10th. `startOfMonth()` puts the walk on a calendar month boundary,
        // and `addMonthNoOverflow()` carries it across year ends without
        // timezone arithmetic — the same reason `ReportDateRange` keeps its
        // bounds as naive calendar days.
        $last = Carbon::parse($range->to(), 'UTC')->startOfMonth();

        for ($month = Carbon::parse($range->from(), 'UTC')->startOfMonth(); $month->lte($last); $month = $month->copy()->addMonthNoOverflow()) {
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'total_sales' => $totals[$key] ?? Money::format(null),
            ];
        }

        return $series;
    }

    /**
     * The SQL expression that reduces `sales.sale_date` to a `'YYYY-MM'` group key.
     *
     * **This is the one driver-specific line in the monthly series, and it is
     * driver-specific because the two supported drivers share no month
     * formatter.**
     *
     * MySQL reads `DATE_FORMAT(col, '%Y-%m')`. SQLite has no `DATE_FORMAT` at
     * all; its equivalent is `strftime('%Y-%m', col)`. Writing either one
     * unconditionally breaks the other — and since the suite runs on SQLite, a
     * MySQL-only expression would take the whole suite down rather than fail one
     * assertion. The difference is confined to this method for exactly that
     * reason: the scope, the window, the filters, the fill-in and the money
     * formatting around it are driver-agnostic and shared with the daily series.
     *
     * Both forms collapse a `DATETIME` string and a bare `DATE` to the same seven
     * characters, which is what makes the key agree with the `'Y-m'` the fill-in
     * loop generates in PHP: MySQL stores a bare `DATE` while SQLite stores
     * Eloquent's `'Y-m-d H:i:s'`, and both come back `'2026-01'`. This is the
     * month-level counterpart of the `DATE(sales.sale_date)` the daily series
     * groups by, and `sales.sale_date` is the same field `scopedSales()` filters
     * on, so a point and the total above it cannot disagree about which month an
     * invoice fell in.
     */
    private function monthKeyExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', sales.sale_date)",
            default => "DATE_FORMAT(sales.sale_date, '%Y-%m')",
        };
    }

    /**
     * How many products `top_products` publishes.
     *
     * A dashboard card wants a leaderboard, not a catalogue: the products below
     * this rank are real rows the report simply is not asked to show. The limit
     * is applied by the database *after* grouping and ordering, so a business
     * with 5,000 products still costs one round trip and one sorted aggregate.
     */
    private const TOP_PRODUCTS_LIMIT = 10;

    /**
     * How many categories `top_categories` publishes.
     *
     * The same bounded-card policy as {@see self::TOP_PRODUCTS_LIMIT}, and the
     * same reason: a report card wants the leading rows of the period, not the
     * tenant's whole category tree. A category a business created but never sold
     * is a catalogue row, not a report row, and the cut is applied by the
     * database *after* grouping and ordering — so 500 categories cost the same
     * single round trip as five.
     */
    private const TOP_CATEGORIES_LIMIT = 10;

    /**
     * How many customers `top_customers` publishes.
     *
     * The same bounded-card policy as {@see self::TOP_PRODUCTS_LIMIT} and
     * {@see self::TOP_CATEGORIES_LIMIT}, for the same reason: a "who buys most"
     * card wants the leading rows of the period, not the tenant's whole customer
     * book. A business with 3,000 customers and one purchase each costs the same
     * single round trip as one with three, because the cut is applied by the
     * database *after* grouping and ordering.
     */
    private const TOP_CUSTOMERS_LIMIT = 10;

    /**
     * How many staff `top_users` publishes.
     *
     * The same bounded-card policy as {@see self::TOP_PRODUCTS_LIMIT},
     * {@see self::TOP_CATEGORIES_LIMIT} and {@see self::TOP_CUSTOMERS_LIMIT},
     * for the same reason: a "who sold most" card wants the leading rows of the
     * period, not the tenant's whole staff directory. The cut is applied by the
     * database *after* grouping and ordering, so a business with 500 staff and a
     * year of invoices costs the same single round trip as a single-counter shop
     * with three.
     */
    private const TOP_USERS_LIMIT = 10;

    /**
     * The best sellers of the window — `SUM(sale_items.total)` per product.
     *
     * ## It is the same population, resolved one join further down
     *
     * The builder handed in here **is** {@see scopedSales()}'s builder, cloned by
     * `sales()` — the identical builder {@see dailySalesSeries()} and
     * {@see monthlySalesSeries()} group. Tenant, `ReportDateRange` window,
     * `customer_id`, `status` and `payment_method` therefore apply here by the
     * very same predicates that produce `total_sales`, which is what keeps a
     * product's revenue inside the total printed above it.
     *
     * Tenant scope travels with the *parent invoice*, never with the sale item.
     * `sale_items` carries no `business_id` of its own, so scoping the lines
     * directly is not possible — and scoping them through `products.business_id`
     * alone would be worse, since it would let a cross-tenant line in if the
     * product ids ever collided. Both are therefore checked: the scoped sale-id
     * subquery is the primary boundary, and `products.business_id` is a second,
     * independent guard on the join itself.
     *
     * The second guard is not belt-and-braces. A sale line is written from a
     * validated product id, so a cross-tenant line should be impossible — but
     * the join is the one place a *foreign* product name could surface on a
     * tenant's own invoice, and a name is exactly the kind of cross-tenant leak
     * a report must never produce. The invoice scope alone would still render
     * "Theirs" from another business, so `products.business_id` is pinned to the
     * caller's tenant as well.
     *
     * ## Grouping, ranking and the cut are all in SQL
     *
     * One grouped aggregate returns at most {@see self::TOP_PRODUCTS_LIMIT}
     * rows: the `SUM`s, the `GROUP BY`, the `ORDER BY`, the tie-break and the
     * `LIMIT` all execute server-side. No sale line and no product row reaches
     * PHP, so the query count is one whatever the size of the catalogue or the
     * length of the window — the same property the two series hold, and the
     * reason this cannot become an N+1 the moment a product list is added.
     *
     * Ties break on `sale_items.product_id` ascending, which is deterministic
     * and total: two products can never swap places between two identical
     * requests. Ranking is by revenue and never by quantity — a product sold by
     * the unit is not the product that made the money.
     *
     * ## Gross, like `total_sales`
     *
     * Returns are never subtracted and refund payments never appear: this sums
     * `sale_items.total` over invoices, so the figures are what the sale lines
     * recorded. Net product sales is a different, separately defined question
     * and is not answered here.
     *
     * ## Lines that cannot name a product are excluded, not invented
     *
     * `sale_items.product_id` is nullable — a service line carries no product,
     * and a hard-deleted product sets it to null (`nullOnDelete`). Such a line
     * has no product to be *reported against*, so it is dropped here rather
     * than filed under a placeholder: a fabricated "Unknown" row would rank
     * alongside real products and take a slot in the top N.
     *
     * `products` is joined rather than left-joined, so a line whose product row
     * is unavailable cannot be reported at all. `Product` soft-deletes rather
     * than disappearing (`ProductService::delete()` only stamps `deleted_at`, and
     * `sale_items.product_id` is `nullOnDelete` only for a *hard* delete), so a
     * soft-deleted product's historical revenue drops out of this report even
     * though its line and its money are still on the invoice. That is the
     * schema's existing lifecycle rule and is deliberately not worked around
     * here: `sale_items` stores no product-name snapshot, so the old name cannot
     * be recovered without a migration this stage does not add. A hard-deleted
     * product nulls `product_id` and is dropped by the same rule as a service
     * line.
     *
     * @param  Builder<Sale>  $query  a clone of {@see scopedSales()}.
     * @return array<int, array{product_id: int, product_name: string, quantity: string, total_sales: string}>
     */
    private function topProducts(int $businessId, Builder $query): array
    {
        // A base builder, not the Eloquent one: nesting an Eloquent builder
        // inside a `whereIn` makes Laravel materialise it as an array of ids,
        // which costs a second round trip and loads the whole invoice set into
        // PHP — the exact thing this stage must not do.
        $salesIds = (clone $query)->toBase()->select('sales.id');

        return SaleItem::query()
            ->toBase()
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            // `Product` soft-deletes, and every other read of this same
            // relationship in the project goes through the Eloquent relation —
            // `SaleService` and `SaleReturnService` eager-load
            // `saleItems.product`, which applies the `SoftDeletes` scope and
            // resolves `null` for a deleted product. A base-builder join applies
            // no global scope, so the same rule is stated here explicitly;
            // omitting it would make this report disagree with the sale detail
            // screen about whether a deleted product still exists.
            ->whereNull('products.deleted_at')
            // The second tenant guard, described above: a foreign product name
            // must never reach this tenant's report, even on one of its own
            // invoices.
            ->where('products.business_id', $businessId)
            ->whereIn('sale_items.sale_id', $salesIds)
            ->selectRaw('sale_items.product_id as product_id')
            ->selectRaw('products.name as product_name')
            ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(sale_items.total), 0) as total_sales')
            // Grouping by the id as well as the name keeps this valid under
            // `ONLY_FULL_GROUP_BY` on MySQL without relying on functional
            // dependency, and is identical on SQLite.
            ->groupBy('sale_items.product_id', 'products.name')
            // `total_sales` is the alias above, so this orders on the aggregate
            // rather than re-summing it.
            ->orderByDesc('total_sales')
            ->orderBy('sale_items.product_id')
            ->limit(self::TOP_PRODUCTS_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                // Both figures come straight off the aggregate as decimal
                // strings. Neither is recomputed in PHP, so no float can round
                // a sold quantity or a money total on its way out.
                'quantity' => Quantity::format($row->quantity),
                'total_sales' => Money::format($row->total_sales),
            ])->values()->all();
    }

    /**
     * The best sellers of the window — `SUM(sale_items.total)` per category.
     *
     * ## It is the same population, resolved two joins further down
     *
     * The builder handed in here **is** {@see scopedSales()}'s builder, cloned by
     * `sales()` — the identical builder {@see topProducts()} groups. Tenant,
     * `ReportDateRange` window, `customer_id`, `status` and `payment_method`
     * therefore apply by exactly the predicates that apply to `total_sales`. The
     * category ranking answers "which categories contributed sale items to the
     * sales this report already counted", and it cannot answer that with a
     * differently filtered set.
     *
     * The relationship is the schema's own: `sales -> sale_items -> products ->
     * categories`, through the nullable `products.category_id`. Nothing is
     * invented to make it work and no relationship table is introduced.
     *
     * ## Tenant isolation is stated at every layer, not one
     *
     * The scoped sale-id subquery is the primary boundary, exactly as in
     * {@see topProducts()}: it is `Sale::query()->forBusiness($businessId)`, so
     * the parent invoice is always the caller's own.
     *
     * Two further guards sit on the joins themselves, and they are the ones this
     * stage is about. `top_products` reads a product *name*; this one reads a
     * category *name* **plus** the revenue attributed to it, so the same class
     * of leak has one more hop to travel:
     *
     *  - `products.business_id = ?` — a foreign product id on one of this
     *    tenant's own invoices must not surface a foreign category or a foreign
     *    revenue figure. `sale_items` carries no `business_id` of its own, so the
     *    product join is the only place that can be checked.
     *  - `categories.business_id = ?` — `products.category_id` is a plain
     *    foreign key with no composite constraint tying it to the product's own
     *    business, so a malformed or hand-edited row can point at another
     *    tenant's category. `category_id` alone is therefore never trusted. The
     *    same reasoning covers a soft-deleted category: `Category` soft-deletes,
     *    and every other read of this relationship goes through the Eloquent
     *    relation, so the scope is stated explicitly here for the same reason it
     *    is for products.
     *
     * ## Grouping, ranking and the cut are all in SQL
     *
     * One grouped aggregate returns at most {@see self::TOP_CATEGORIES_LIMIT}
     * rows: the `SUM`s, the `GROUP BY`, the `ORDER BY`, the tie-break and the
     * `LIMIT` all execute server-side. No sale line, product or category row
     * reaches PHP, so the query count is one whatever the size of the catalogue
     * or the length of the window.
     *
     * Ties break on `categories.id` ascending, which is deterministic and
     * total: two categories can never swap places between two identical
     * requests. Ranking is by revenue and never by quantity — a category sold by
     * the unit is not the category that made the money.
     *
     * ## Gross, like `total_sales`
     *
     * Returns are never subtracted and refund payments never appear: this sums
     * `sale_items.total` over invoices, so the figures are what the sale lines
     * recorded. The reversal stays visible where the report already publishes
     * it — `returns_amount` and `net_sales`, beside this field — and net sales
     * per category is a different, separately defined question.
     *
     * ## Lines with no identifiable category are excluded, not invented
     *
     * `categories` is **joined**, not left-joined, and that single decision
     * settles the two cases that cannot name a category:
     *
     *  - a **service line** (`sale_items.product_id` null) never reaches
     *    `products` at all, so it can never reach `categories`;
     *  - an **uncategorised product** (`products.category_id` null) joins
     *    `products` but not `categories`, so it drops out here too.
     *
     * There is no "Uncategorized" bucket: this report is *sales by identifiable
     * category*, and a fabricated row would rank beside real categories and take
     * a slot in the top N. The money is not lost — it is in `total_sales` — it
     * simply has no category to be reported against. The schema stores no
     * reserved row for this, so none is assumed here.
     *
     * ## Lifecycle
     *
     * `Product` and `Category` both soft-delete, and `sale_items` stores no name
     * snapshot for either, so a soft-deleted product drops its revenue out of
     * this ranking, and a soft-deleted category takes its whole historical
     * revenue with it. That is the schema's existing lifecycle rule — the same
     * one {@see topProducts()} already documents and tests — and it is
     * deliberately not worked around here, because recovering a historical
     * category name would require a snapshot column this stage does not add.
     *
     * @param  Builder<Sale>  $query  a clone of {@see scopedSales()}.
     * @return array<int, array{category_id: int, category_name: string, quantity: string, total_sales: string}>
     */
    private function topCategories(int $businessId, Builder $query): array
    {
        // A base builder, not the Eloquent one: nesting an Eloquent builder
        // inside a `whereIn` makes Laravel materialise it as an array of ids,
        // which costs a second round trip and loads the whole invoice set into
        // PHP — the exact thing this stage must not do.
        $salesIds = (clone $query)->toBase()->select('sales.id');

        return SaleItem::query()
            ->toBase()
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            // Both models soft-delete and both are read through their Eloquent
            // relations everywhere else in the project (`saleItems.product`,
            // `product.category`). A base-builder join applies no global scope, so
            // the same rule is stated explicitly; omitting it would let this report
            // disagree with the sale detail screen about whether a removed product
            // or category still exists.
            ->whereNull('products.deleted_at')
            ->whereNull('categories.deleted_at')
            // The two independent tenant guards described above. The invoice scope
            // alone would still let a foreign category's name — and the revenue
            // attributed to it — through, because `sale_items.product_id` and
            // `products.category_id` are not themselves tenant-scoped.
            ->where('products.business_id', $businessId)
            ->where('categories.business_id', $businessId)
            ->whereIn('sale_items.sale_id', $salesIds)
            ->selectRaw('categories.id as category_id')
            ->selectRaw('categories.name as category_name')
            ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(sale_items.total), 0) as total_sales')
            // Grouping by the id as well as the name keeps this valid under
            // `ONLY_FULL_GROUP_BY` on MySQL without relying on functional
            // dependency, and is identical on SQLite.
            ->groupBy('categories.id', 'categories.name')
            // `total_sales` is the alias above, so this orders on the aggregate
            // rather than re-summing it.
            ->orderByDesc('total_sales')
            ->orderBy('categories.id')
            ->limit(self::TOP_CATEGORIES_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'category_id' => (int) $row->category_id,
                'category_name' => (string) $row->category_name,
                // Both figures come straight off the aggregate as decimal strings.
                // Neither is recomputed in PHP, so no float can round a sold
                // quantity or a money total on its way out.
                'quantity' => Quantity::format($row->quantity),
                'total_sales' => Money::format($row->total_sales),
            ])->values()->all();
    }

    /**
     * The customers who bought most — `SUM(sales.grand_total)` per customer.
     *
     * ## It is the same population, resolved one join sideways
     *
     * The builder handed in here **is** {@see scopedSales()}'s builder, cloned by
     * `sales()` — the identical builder the two series, {@see topProducts()} and
     * {@see topCategories()} all read. Tenant, {@see ReportDateRange} window,
     * `customer_id`, `status` and `payment_method` therefore apply here by the
     * very same predicates that produce `total_sales`, and the `customer_id`
     * filter narrows this ranking exactly as it narrows the totals above it. A
     * second, hand-written reading of "the sales population" is precisely the bug
     * this stage must not introduce, so none is written: the one scope is cloned,
     * never restated.
     *
     * ## Gross, like `total_sales`
     *
     * `SUM(sales.grand_total)` over the invoices — the same column `total_sales`,
     * `daily_series` and `monthly_series` sum. Returns are **not** subtracted and
     * refund payments never appear: a return is revenue in its own right, dated on
     * its own document, and is reported on `returns_amount` / `net_sales` instead.
     * Net sales *per customer* is a differently defined question, and answering it
     * here would make this card disagree with the summary printed above it.
     * Nothing below subtracts a return, subtracts a refund, reads the customer
     * ledger, or touches `paid_amount` / `due_amount` — those are computed
     * elsewhere and are unchanged by this method.
     *
     * ## `invoice_count` counts invoices, and only invoices
     *
     * It is `COUNT(sales.id)` — one row per matching sale, because the grouping
     * happens on the `sales` table itself. It cannot drift into counting sale
     * lines or payment rows, because neither table is joined here: an invoice
     * with four lines still counts once, and an invoice settled in three payments
     * still counts once.
     *
     * ## Walk-in sales are excluded, and the total is not changed
     *
     * `sales.customer_id` is nullable, and a null is a walk-in / unassigned sale.
     * The join is an **inner** join on `customers`, so those invoices contribute
     * no row at all — the honest answer, because there is no customer to rank. The
     * schema stores no reserved "Walk-in Customer" row, so none is fabricated:
     * inventing one would print a customer who does not exist and create the
     * appearance that the ranking reconciles with the total.
     *
     * It consequently does **not** reconcile: `sum(top_customers.total_sales)` is
     * expected to be *less than* `total_sales` whenever the period contains
     * walk-in sales. That is not a defect, and it is asserted as behaviour rather
     * than papered over. `total_sales` is deliberately **not** changed to match —
     * the walk-in money is real revenue and belongs in the report's total.
     *
     * ## Tenant isolation is stated on the join, not inherited
     *
     * Scoping `sales.business_id` is necessary but **not sufficient** here, and
     * this is the Stage 3 lesson applied one hop over. `sales.customer_id` is a
     * plain foreign key with no composite constraint tying it to the invoice's
     * own business, so a corrupted or hand-edited row can point a local invoice at
     * another tenant's customer. The parent scope alone would then group that
     * invoice and print **the other business's customer's name** on this tenant's
     * report — a cross-tenant leak, and the most damaging kind, because it is a
     * name rather than a total. The joined customer is therefore independently
     * pinned to `customers.business_id = $businessId`, and the inner join drops
     * any invoice whose customer belongs elsewhere rather than attributing it to
     * nobody in particular.
     *
     * ## Soft-deleted customers
     *
     * `Customer` soft-deletes, and `sales` stores no customer-name snapshot, so a
     * soft-deleted customer's revenue cannot be reported under a name the server
     * can still read. This ranking therefore drops it, exactly as
     * {@see topProducts()} drops a soft-deleted product and
     * {@see topCategories()} drops a soft-deleted category — the schema's own
     * lifecycle rule, not one invented here, and not worked around, because
     * working around it would need a snapshot column this stage does not add. The
     * invoice still counts in `total_sales`; only the ranking excludes it.
     *
     * ## Grouping, ranking and the cut are all in SQL
     *
     * One grouped aggregate returns at most {@see self::TOP_CUSTOMERS_LIMIT} rows:
     * the `SUM`, the `COUNT`, the `GROUP BY`, the `ORDER BY`, the tie-break and
     * the `LIMIT` all execute server-side. No invoice row reaches PHP, so the cost
     * is one round trip whatever the size of the period or the customer book —
     * the same property the other aggregates hold, and the reason this cannot
     * become an N+1 the moment a customer list is added.
     *
     * Ties break on `customers.id` ascending, which is deterministic and total:
     * two customers can never swap places between two identical requests.
     *
     * @param  Builder<Sale>  $query  a clone of {@see scopedSales()}.
     * @return array<int, array{customer_id: int, customer_name: string, invoice_count: int, total_sales: string}>
     */
    private function topCustomers(int $businessId, Builder $query): array
    {
        return (clone $query)->toBase()
            // An *inner* join: a null `customer_id` is a walk-in sale and has no
            // customer to rank, so it matches no row and is simply absent. No
            // left join, and therefore no fabricated bucket to display it under.
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            // The soft-delete rule stated explicitly. `Customer` soft-deletes and
            // is read through its Eloquent relations everywhere else, but a
            // base-builder join applies no global scope — so without this the
            // report would disagree with the customer list screen about whether a
            // removed customer still exists.
            ->whereNull('customers.deleted_at')
            // The independent tenant guard described above. The scoped invoice
            // alone is not enough: `sales.customer_id` is not itself
            // tenant-scoped, so only this predicate keeps a foreign customer's
            // name off this tenant's report.
            ->where('customers.business_id', $businessId)
            ->selectRaw('customers.id as customer_id')
            ->selectRaw('customers.name as customer_name')
            // One row per matching invoice. Grouping happens on `sales`, so a
            // multi-line or multi-payment invoice can only ever be counted once.
            ->selectRaw('COUNT(sales.id) as invoice_count')
            // Gross, the same reading as `total_sales` — the identical column.
            ->selectRaw('COALESCE(SUM(sales.grand_total), 0) as total_sales')
            // Grouping by the id as well as the name keeps this valid under
            // `ONLY_FULL_GROUP_BY` on MySQL without relying on functional
            // dependency, and is identical on SQLite.
            ->groupBy('customers.id', 'customers.name')
            // `total_sales` is the alias above, so this orders on the aggregate
            // rather than re-summing it.
            ->orderByDesc('total_sales')
            ->orderBy('customers.id')
            ->limit(self::TOP_CUSTOMERS_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'customer_id' => (int) $row->customer_id,
                'customer_name' => (string) $row->customer_name,
                // Both integers come straight off the aggregate; the count is
                // never recomputed from lines or payments.
                'invoice_count' => (int) $row->invoice_count,
                // The money total comes off the aggregate as a decimal string and
                // is normalised by the project's own money helper, so no float
                // can round a revenue figure on its way out.
                'total_sales' => Money::format($row->total_sales),
            ])->values()->all();
    }

    /**
     * The staff who sold most — `SUM(sales.grand_total)` per user.
     *
     * ## Which user a sale belongs to
     *
     * The schema has no `sales.user_id`; the sale-to-staff relationship this reads
     * is the one the project already had and already writes:
     * **`sales.cashier_id` -> `users.id`**. `SaleService::create()` sets it to the
     * authenticated seller's id, `Sale::cashier()` is the relation and
     * `User::sales()` is its inverse. It is the user *on the sale itself*, which
     * is what a staff ranking has to mean — not `payments.received_by`, not the
     * cash-register operator, not the caller and not the customer. No column and
     * no association is invented here; this reads the relationship the POS
     * already records.
     *
     * ## It is the same population, joined sideways
     *
     * The builder handed in here **is** {@see scopedSales()}'s builder, cloned by
     * `sales()` — the identical builder the two series, {@see topProducts()},
     * {@see topCategories()} and {@see topCustomers()} all read. Tenant,
     * {@see ReportDateRange} window, `customer_id`, `status` and `payment_method`
     * therefore apply here by the very same predicates that produce
     * `total_sales`. The report filtering is never restated — a second,
     * hand-written reading of "the sales population" is exactly the bug this
     * stage must not introduce.
     *
     * There is deliberately **no user filter** in this stage. Sales Report has
     * none, so none is added: it would need request validation, a column on every
     * report and a contract of its own, none of which the stage asks for.
     *
     * ## Gross, like `total_sales`
     *
     * `SUM(sales.grand_total)` over the invoices — the same column `total_sales`,
     * `daily_series`, `monthly_series`, `top_products`, `top_categories` and
     * `top_customers` all sum, read the same way. Returns are **not** subtracted
     * and refund payments never appear: this is a gross-sales leaderboard, and net
     * sales per staff member is a differently defined question that would make
     * this card disagree with the summary printed above it. Nothing below touches
     * `net_sales`, `paid_amount`, `due_amount`, `refund_amount` or
     * `net_collected_amount`, and nothing reads the customer ledger.
     *
     * ## Unassigned sales are excluded, and the total is not changed
     *
     * `sales.cashier_id` is nullable, and a null is an unassigned sale. The join is
     * an **inner** join on `users`, so those invoices contribute no row at all —
     * the honest answer, because there is no user to rank. No "Unknown User" and
     * no "Walk-in Staff" is invented: the schema stores no such reserved row, and
     * fabricating one would print a person who does not exist and imply the
     * ranking reconciles with the total.
     *
     * It consequently does **not** reconcile: `sum(top_users.total_sales)` is
     * expected to be *less than* `total_sales` whenever the period contains an
     * unassigned sale. That is not a defect. `total_sales` is deliberately **not**
     * changed to compensate — the unattributed money is real revenue and belongs
     * in the report's total.
     *
     * ## Tenant isolation is stated on the join, not inherited
     *
     * Scoping `sales.business_id` is necessary but **not sufficient**, and this is
     * the Stage 3 lesson applied one hop over. `sales.cashier_id` is a plain
     * foreign key with no composite constraint tying it to the invoice's own
     * business, so a corrupted or hand-edited row can point a local invoice at
     * another tenant's staff account. The parent scope alone would then group that
     * invoice and print **the other business's staff name, id and revenue** on
     * this tenant's report — a cross-tenant leak, and the most damaging kind,
     * because it is a person's identity rather than a total. The joined user is
     * therefore independently pinned to `users.business_id = $businessId`, and the
     * inner join drops any invoice whose cashier belongs elsewhere rather than
     * attributing it to nobody in particular.
     *

     * ## Deactivated staff are **retained**, and that is the schema's own rule
     *
     * Unlike `Customer`, `Product` and `Category`, `User` does **not** soft-delete
     * — there is no `users.deleted_at` and the model uses no `SoftDeletes` trait.
     * `UserController` documents why: deleting a staff account would orphan every
     * sale, purchase and cash-register row referencing it, so a member is
     * *deactivated* (`users.status = 'inactive'`) rather than removed.
     * Deactivation is an access decision, not an erasure — the row, the name and
     * the historical invoices all remain, and the ranking reports what was sold,
     * by whom, during the period. So no status filter is applied here: silently
     * dropping an inactive member's period revenue would make the leaderboard
     * disagree with the invoices it is built from, and no existing business rule
     * asks for it.
     *
     * ## Grouping, ranking and the cut are all in SQL
     *
     * One grouped aggregate returns at most {@see self::TOP_USERS_LIMIT} rows: the
     * `SUM`, the `COUNT`, `GROUP BY`, the `ORDER BY`, the tie-break and the `LIMIT`
     * all execute server-side. No invoice row reaches PHP and no per-user lookup
     * is issued, so the cost is one round trip whatever the size of the period or
     * the staff directory.
     *
     * Ties break on `users.id` ascending, which is deterministic and total: two
     * staff members can never swap places between two identical requests.
     *
     * @param  Builder<Sale>  $query  a clone of {@see scopedSales()}.
     * @return array<int, array{user_id: int, user_name: string, invoice_count: int, total_sales: string}>
     */
    private function topUsers(int $businessId, Builder $query): array
    {
        return (clone $query)->toBase()
            // An *inner* join: a null `cashier_id` is an unassigned sale and has no
            // user to rank, so it matches no row and is simply absent. No left
            // join, and therefore no fabricated bucket to display it under.
            ->join('users', 'users.id', '=', 'sales.cashier_id')
            // The independent tenant guard described above. The scoped invoice
            // alone is not enough: `sales.cashier_id` is not itself
            // tenant-scoped, so only this predicate keeps a foreign staff member's
            // id and name off this tenant's report.
            ->where('users.business_id', $businessId)
            // No `deleted_at` predicate, because `users` has no such column: the
            // model does not soft-delete and a staff row is never removed. Stated
            // here so the difference from topCustomers() reads as a decision about
            // this schema rather than an omission.
            ->selectRaw('users.id as user_id')
            ->selectRaw('users.name as user_name')
            // One row per matching invoice. Grouping happens on `sales`, so a
            // multi-line or multi-payment invoice can only ever be counted once.
            ->selectRaw('COUNT(sales.id) as invoice_count')
            // Gross, the same reading as `total_sales` — the identical column.
            ->selectRaw('COALESCE(SUM(sales.grand_total), 0) as total_sales')
            // Grouping by the id as well as the name keeps this valid under
            // `ONLY_FULL_GROUP_BY` on MySQL without relying on functional
            // dependency, and is identical on SQLite.
            ->groupBy('users.id', 'users.name')
            // `total_sales` is the alias above, so this orders on the aggregate
            // rather than re-summing it.
            ->orderByDesc('total_sales')
            ->orderBy('users.id')
            ->limit(self::TOP_USERS_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'user_name' => (string) $row->user_name,
                // Both integers come straight off the aggregate; the count is
                // never recomputed from lines or payments.
                'invoice_count' => (int) $row->invoice_count,
                // The money total comes off the aggregate as a decimal string and
                // is normalised by the project's own money helper, so no float can
                // round a revenue figure on its way out.
                'total_sales' => Money::format($row->total_sales),
            ])->values()->all();
    }

    /**
     * The sale returns in scope for the given filters, and their settlement.
     *
     * Shared by `sales()` and `profitLoss()` for the same reason
     * {@see scopedSales()} is shared: the revenue reversal and the cost reversal
     * must describe the *same* documents, or net revenue and net COGS stop being
     * two halves of one figure.
     *
     * A return is scoped on **its own** `return_date`, not on the date of the
     * sale it reverses. That is what makes a period report answer the question
     * actually asked — "how much came back during these days" — instead of
     * "how much of what was sold in these days has since come back", which would
     * restate last month's return as this month's revenue reduction.
     *
     * Only `status = completed` counts. A pending return has not yet reversed
     * anything: no refund was paid and no ledger entry was written, so including
     * it would subtract revenue that was never taken.
     *
     * `customer_id` narrows the returns as well as the sales, so a
     * customer-filtered report's gross and net describe the same customer. The
     * `status` and `payment_method` filters are properties of an *invoice* and
     * have no meaning on a return, so they are not pushed down here.
     *
     * @param  array<string, mixed>  $filters
     * @return array{returns_count: int, returns_amount: string, refund_count: int, refund_amount: string, refund_breakdown: array<int, array<string, mixed>>}
     */
    private function returnsAggregate(int $businessId, array $filters): array
    {
        $returns = $this->scopedReturns($businessId, $filters);

        $returnRow = (clone $returns)
            ->selectRaw('COUNT(*) as returns_count')
            ->selectRaw('COALESCE(SUM(refund_amount), 0) as returns_amount')
            ->first();

        // The refund payments that settled these returns. Scoped through
        // `sale_return_id` rather than `sale_id`, which is exactly the pointer a
        // refund row carries — a refund is money leaving the business and is
        // never a collection, so it must not be able to reach the inbound sum.
        $refunds = Payment::query()
            ->forBusiness($businessId)
            ->refunds()
            ->whereIn('payments.sale_return_id', (clone $returns)->select('sale_returns.id'));

        $refundRow = (clone $refunds)
            ->selectRaw('COUNT(*) as refund_count')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as refund_amount')
            ->first();

        $breakdown = $refunds->toBase()
            ->selectRaw('payments.payment_method, COUNT(*) as refund_count, COALESCE(SUM(payments.amount), 0) as refund_amount')
            ->groupBy('payments.payment_method')
            ->orderByDesc('refund_amount')
            ->orderBy('payments.payment_method')
            ->get()
            ->map(fn (object $row): array => [
                'payment_method' => $row->payment_method,
                'refund_count' => (int) $row->refund_count,
                'refund_amount' => Money::format($row->refund_amount),
            ])->values()->all();

        return [
            'returns_count' => (int) $returnRow->returns_count,
            'returns_amount' => Money::format($returnRow->returns_amount),
            'refund_count' => (int) $refundRow->refund_count,
            'refund_amount' => Money::format($refundRow->refund_amount),
            'refund_breakdown' => $breakdown,
        ];
    }

    /**
     * The completed sale returns in scope, before any aggregate is applied.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<SaleReturn>
     */
    private function scopedReturns(int $businessId, array $filters): Builder
    {
        $returns = SaleReturn::query()->forBusiness($businessId)
            ->where('status', SaleReturn::STATUS_COMPLETED);

        ReportDateRange::fromFilters($filters)->apply($returns, 'sale_returns.return_date');

        if (($filters['customer_id'] ?? null) !== null) {
            $returns->where('customer_id', $filters['customer_id']);
        }

        return $returns;
    }

    /**
     * Cost of goods, the cost the returns put back, and the lines that cannot be
     * valued at all.
     *
     * One method because the three figures describe one population: the sale lines
     * of this tenant's in-window invoices, plus the lines those invoices'
     * returns pointed at. Computing them separately let the same unpriced line be
     * counted twice — once as an unpriced sale line and again as the unpriced
     * line a return reversed — and `unpriced_lines` is meant to answer "how many
     * rows are holding this figure back", not "how many times was a bad row
     * noticed".
     *
     * **The reversal reads history.** `sale_return_items.sale_item_id` points at
     * the `sale_items` row whose `unit_cost` was snapshotted when that line was
     * sold, scaled by the quantity actually returned. Reading
     * `products.cost_price` instead would value a March return at April's price,
     * which is not a cost the business ever incurred.
     *
     * A return line whose sale line has no recorded cost cannot be valued. It is
     * counted, never treated as free — reversing an unknown cost would put a
     * made-up number into the reversal and *raise* reported profit.
     *
     * Returns of sales from **outside** the window are still reversed: a return is
     * dated on its own document, so its cost reversal belongs to the period the
     * goods came back, not to the period they went out. Those sale lines are not
     * in the revenue figure (the sale happened earlier) but their cost reversal
     * is in this one, which is why the two halves are scoped separately and
     * deliberately not required to cover the same invoices.
     *
     * @param  array<string, mixed>  $filters
     * @return array{cost_of_goods: string, returned_cost_of_goods: string, unpriced_lines: int}
     */
    private function costOfGoodsAggregate(int $businessId, array $filters, Builder $sales): array
    {
        // Base builders all the way down: nesting an Eloquent builder inside a
        // `whereIn` makes Laravel materialise it as an array of ids, which
        // silently costs a second round trip per nesting level.
        $salesIds = (clone $sales)->toBase()->select('sales.id');
        $returnsIds = $this->scopedReturns($businessId, $filters)->toBase()->select('sale_returns.id');

        $soldRow = SaleItem::query()
            ->toBase()
            ->whereIn('sale_items.sale_id', $salesIds)
            ->selectRaw('COALESCE(SUM(sale_items.unit_cost * sale_items.quantity), 0) as cost_of_goods')
            ->first();

        // The unpriced set is the union of two populations, counted once: the
        // unpriced sale lines of this window's invoices, and the sale lines whose
        // cost a return of this window tried to reverse (which may belong to an
        // invoice from an earlier period). `sale_items` carries no `business_id`,
        // so the tenant travels with the invoice id set either way.
        $unpricedRow = SaleItem::query()
            ->toBase()
            ->whereNull('sale_items.unit_cost')
            ->where(function ($query) use ($salesIds, $returnsIds): void {
                $query->whereIn('sale_items.sale_id', $salesIds)
                    ->orWhereIn('sale_items.id', function ($sub) use ($returnsIds): void {
                        $sub->from('sale_return_items')
                            ->select('sale_return_items.sale_item_id')
                            ->whereIn('sale_return_items.sale_return_id', $returnsIds);
                    });
            })
            ->selectRaw('COUNT(*) as unpriced_lines')
            ->first();

        $returnedRow = SaleReturnItem::query()
            ->toBase()
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->whereIn('sale_return_items.sale_return_id', $returnsIds)
            ->selectRaw('COALESCE(SUM(sale_items.unit_cost * sale_return_items.quantity), 0) as returned_cost_of_goods')
            ->first();

        return [
            'cost_of_goods' => Money::format($soldRow->cost_of_goods),
            'returned_cost_of_goods' => Money::format($returnedRow->returned_cost_of_goods),
            'unpriced_lines' => (int) $unpricedRow->unpriced_lines,
        ];
    }

    /**
     * What customers owe the business right now, from the ledger.
     *
     * `sales.due_amount` cannot answer this. It is maintained in only one
     * direction: a later payment against the invoice reduces it, but a later
     * *return* against that invoice never does. So after any customer has taken
     * goods back, summing the column reports money the business already has and
     * the column is not the place to correct it either — rewriting it would
     * falsify the invoice the customer was originally given.
     *
     * `customer_ledger_entries` is the receivable's source of truth: the balance
     * is the sum of its movements, each one dated and each one traceable to the
     * document that caused it.
     *
     * Positive means the customer owes us; negative means we owe them (a credit
     * or advance), which is the ledger's own sign convention rather than an
     * invention here. An absent `customer_id` reports the whole business's
     * receivable.
     */
    private function customerOutstanding(int $businessId, ?int $customerId = null): string
    {
        $entries = CustomerLedgerEntry::query()->forBusiness($businessId);

        if ($customerId !== null) {
            $entries->forCustomer($customerId);
        }

        return Money::format($entries->sum('amount'));
    }

    /**
     * @param  Builder<Sale|Purchase|Expense>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, array $filters, string $column): void
    {
        ReportDateRange::fromFilters($filters)->apply($query, $column);
    }

    /** @param array<string, mixed> $filters */
    private function withPeriod(array $filters, array $metrics): array
    {
        return ['period' => ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null], 'metrics' => $metrics];
    }
}
