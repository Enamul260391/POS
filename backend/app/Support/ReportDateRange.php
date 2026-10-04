<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Report\ReportService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * The one inclusive-calendar-day window every Reports endpoint filters by.
 *
 * ## The rule this class exists to make unbreakable
 *
 * `date_from` and `date_to` are **calendar days**, and both ends belong to the
 * window:
 *
 *     from = 2026-10-01, to = 2026-10-01  =>  2026-10-01 00:00:00 … 2026-10-01 23:59:59.999999
 *
 * ## Why a plain `<= 'Y-m-d'` is not that
 *
 * Comparing a column straight against the raw string is the obvious
 * implementation and it is wrong twice over:
 *
 *  1. **Datetime columns truncate.** `<= '2026-10-01'` excludes every row
 *     stamped later on the 1st, because `'2026-10-01 14:30:00'` sorts after
 *     `'2026-10-01'`. A same-day afternoon sale silently vanishes from the day's
 *     own report.
 *  2. **It depends on how the driver stores the column.** On SQLite a `date`
 *     column is written as the Eloquent string `'Y-m-d H:i:s'`, so the very same
 *     predicate excludes rows that MySQL — which stores a bare `DATE` — would
 *     include. A boundary rule that changes meaning with the driver is not a
 *     boundary rule.
 *
 * ## The convention used instead
 *
 * A **half-open interval**, which is exact for `DATE` and `DATETIME` columns
 * alike and needs no sub-second precision from the driver:
 *
 *     column >= '{from}'
 *     column <  '{to} + 1 day'
 *
 * `<` rather than `<= '… 23:59:59.999999'` is deliberate: a `DATETIME` column
 * declared without fractional seconds has no `.999999` to compare against, and
 * spelling it out would make the upper bound depend on the column's precision.
 * Pushing the bound to the next midnight includes exactly `23:59:59.999999` and
 * everything before it, on any column, on any driver.
 *
 * The bounds are left as bare `Y-m-d` strings rather than being expanded to
 * `'{from} 00:00:00'`, and that is the other half of why the rule holds. The same
 * column may be stored as a bare `'2026-10-01'` (MySQL `DATE`), as
 * `'2026-10-01 00:00:00'` (SQLite, via Eloquent's date cast) or with a time of
 * day (`DATETIME`), and bare-day bounds are the only form that orders correctly
 * against all three:
 *
 *     '2026-10-01 00:00:00' >= '2026-10-01'   true    start of day is in
 *     '2026-10-01 23:59:59' <  '2026-10-02'   true    end of day is in
 *     '2026-10-02 00:00:00' <  '2026-10-02'   false   the next day is out
 *
 * Expanding the lower bound to `'… 00:00:00'` would fail the first line against a
 * bare-date value, silently dropping the whole first day of every period.
 *
 * Both bounds are optional: an absent `date_from` leaves the window open on the
 * left and an absent `date_to` leaves it open on the right, which is what the
 * unfiltered report has always meant.
 *
 * ## Timezones
 *
 * The bounds are built as naive calendar strings rather than as instants in a
 * zone, because every column Reports filters on (`sales.sale_date`,
 * `purchases.purchase_date`, `expenses.expense_date`) is a plain `DATE` that
 * carries no offset of its own. Converting them would invent an offset the data
 * does not have. Only the Dashboard composes a window from "today", and it does
 * so in the business timezone before handing the day in — see
 * {@see ReportService::dashboard()}.
 */
final class ReportDateRange
{
    /**
     * @param  string|null  $from  Inclusive `Y-m-d` lower bound, or null.
     * @param  string|null  $to  Inclusive `Y-m-d` upper bound, or null.
     */
    private function __construct(
        private readonly ?string $from,
        private readonly ?string $to,
    ) {}

    /**
     * Build the window from an already-validated `date_from` / `date_to` pair.
     *
     * Both values are `Y-m-d` by the time they arrive — `ReportFilterRequest`
     * enforces `date_format:Y-m-d` — so anything else is ignored rather than
     * parsed on a guess.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        return new self(
            self::normalise($filters['date_from'] ?? null),
            self::normalise($filters['date_to'] ?? null),
        );
    }

    /**
     * The inclusive lower bound as sent, echoed back in `period.from`.
     */
    public function from(): ?string
    {
        return $this->from;
    }

    /**
     * The inclusive upper bound as sent, echoed back in `period.to`.
     */
    public function to(): ?string
    {
        return $this->to;
    }

    /**
     * Constrain a query to this window on one column.
     *
     * Both the Eloquent and the base query builder are accepted, because the
     * reports reach some of their aggregates through a join and a base builder
     * while the rest go through models — and a date policy that only one of them
     * can use would end up being bypassed.
     *
     * The bounds are passed as bound values rather than interpolated, so the
     * predicate cannot be reshaped by what the client sent.
     */
    public function apply(EloquentBuilder|QueryBuilder $query, string $column): void
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->where($column, '<', $this->exclusiveUpperBound());
        }
    }

    /**
     * The first day *after* `date_to`, as a bare `Y-m-d`.
     *
     * Exposed for the one caller that needs the boundary itself rather than the
     * predicate, so the exclusive bound is never re-derived by hand.
     */
    public function exclusiveUpperBound(): string
    {
        if ($this->to === null) {
            // Only reachable from `apply()`'s own guard; a null upper bound
            // yields no clause at all, so this value is never compared.
            return '';
        }

        return Carbon::parse($this->to, 'UTC')->addDay()->format('Y-m-d');
    }

    /**
     * A bound is used only when it is exactly `Y-m-d`.
     *
     * Anything else — including `null`, an empty string or a client sending a
     * datetime — is treated as "no bound given", which leaves that side of the
     * window open. This is what stops an unvalidated value from ever reaching a
     * comparison.
     */
    private static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
