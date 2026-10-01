"use client";

import { useEffect, useState } from "react";
import { Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ExpenseFilters } from "@/features/expenses/components/ExpenseFilters";
import { ExpenseFormDialog } from "@/features/expenses/components/ExpenseFormDialog";
import { ExpenseTable } from "@/features/expenses/components/ExpenseTable";
import { expenseLabel, summariseExpenses } from "@/features/expenses/lib/expenses";
import { formatMoney } from "@/features/pos/lib/format";
import { messageOfApiError } from "@/lib/api/validation";
import {
  selectExpenseCategories,
  selectExpenses,
  useExpenseStore,
} from "@/stores/useExpenseStore";
import type { Expense } from "@/types/expense";

/** How long the search box stays quiet before the API is asked. */
const SEARCH_DEBOUNCE_MS = 300;

/**
 * `/expenses` screen — composition only.
 *
 * Owns the search term, the category filter, the current page and which dialog
 * (if any) is mounted; the ledger itself lives in the expense store, which
 * hydrates from `GET /expenses`.
 *
 * The in-browser `filterExpenses` pass is gone: both the search term and the
 * category now go to the backend as query parameters and are filtered in SQL,
 * so the rows on screen are exactly the rows the server matched. Typing is
 * debounced, and because the store only ever replaces its list with the most
 * recently completed request, a slow response can never overwrite a newer one.
 *
 * Deleting is a real `DELETE /expenses/{id}` behind a confirmation step. The
 * backend refuses to delete an expense that has affected a cash register, and
 * that refusal is surfaced verbatim.
 */
export function ExpensesScreen() {
  const expenses = useExpenseStore(selectExpenses);
  const categories = useExpenseStore(selectExpenseCategories);
  const isLoading = useExpenseStore((s) => s.isLoading);
  const error = useExpenseStore((s) => s.error);
  const pagination = useExpenseStore((s) => s.pagination);
  const load = useExpenseStore((s) => s.load);
  const loadCategories = useExpenseStore((s) => s.loadCategories);
  const deleteExpense = useExpenseStore((s) => s.deleteExpense);

  const [query, setQuery] = useState("");
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Expense | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Expense | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  // The category lookup is independent of the ledger page, so it runs once on
  // mount rather than on every search.
  useEffect(() => {
    void loadCategories();
  }, [loadCategories]);

  // Debounced search. This is also the initial load, so the ledger hydrates as
  // soon as the mount settles. A new term or category restarts at page 1,
  // because the requested page of the previous result set no longer means
  // anything.
  useEffect(() => {
    const handle = setTimeout(() => {
      void load({ search: query.trim() || undefined, categoryId }, 1);
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(handle);
  }, [query, categoryId, load]);

  const summary = summariseExpenses(expenses);
  const canGoBack = pagination.current_page > 1;
  const canGoForward = pagination.current_page < pagination.last_page;

  function goToPage(next: number) {
    void load({ search: query.trim() || undefined, categoryId }, next);
  }

  async function confirmDelete() {
    if (deleteTarget === null || isDeleting) {
      return;
    }
    setIsDeleting(true);
    setDeleteError(null);
    try {
      await deleteExpense(deleteTarget.id);
      setDeleteTarget(null);
    } catch (failure) {
      setDeleteError(
        messageOfApiError(failure, "Could not delete the expense."),
      );
    } finally {
      setIsDeleting(false);
    }
  }


  return (
    <section aria-label="Expenses" className="flex min-w-0 flex-1 flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground" aria-live="polite">
          {summary.count} expenses · {formatMoney(summary.total)} total
          {summary.cashTotal > 0 &&
            ` · ${formatMoney(summary.cashTotal)} in cash`}
        </p>
        <Button type="button" onClick={() => setCreateOpen(true)}>
          <Plus aria-hidden="true" />
          Add expense
        </Button>
      </div>

      <ExpenseFilters
        query={query}
        onQueryChange={setQuery}
        categoryId={categoryId}
        onCategoryChange={setCategoryId}
        categories={categories}
        shownCount={expenses.length}
        totalCount={pagination.total}
        isLoading={isLoading}
      />

      {error !== null && (
        <p role="alert" className="text-xs text-destructive">
          {error}
        </p>
      )}

      {deleteError !== null && (
        <p role="alert" className="text-xs text-destructive">
          {deleteError}
        </p>
      )}

      <ExpenseTable
        expenses={expenses}
        onEdit={setEditTarget}
        onDelete={setDeleteTarget}
        isLoading={isLoading}
      />

      {pagination.last_page > 1 && (
        <div className="flex items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canGoBack || isLoading}
            onClick={() => goToPage(pagination.current_page - 1)}
          >
            Previous
          </Button>
          <p className="text-xs text-muted-foreground" aria-live="polite">
            Page {pagination.current_page} of {pagination.last_page}
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canGoForward || isLoading}
            onClick={() => goToPage(pagination.current_page + 1)}
          >
            Next
          </Button>
        </div>
      )}

      {/* Dialogs mount only while open: every open starts from fresh state. */}
      {createOpen && (
        <ExpenseFormDialog
          mode="create"
          onOpenChange={(open) => {
            if (!open) setCreateOpen(false);
          }}
        />
      )}
      {editTarget !== null && (
        <ExpenseFormDialog
          mode="edit"
          expense={editTarget}
          onOpenChange={(open) => {
            if (!open) setEditTarget(null);
          }}
        />
      )}
      {deleteTarget !== null && (
        <div
          role="alertdialog"
          aria-label="Delete expense"
          className="rounded-lg border border-destructive/40 bg-card px-4 py-3"
        >
          <p className="text-sm font-medium">
            Delete {expenseLabel(deleteTarget)}?
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            This removes the expense permanently. An expense that has already
            affected a cash register cannot be deleted.
          </p>
          <div className="mt-3 flex justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={isDeleting}
              onClick={() => setDeleteTarget(null)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={isDeleting}
              onClick={confirmDelete}
            >
              {isDeleting ? "Deleting…" : "Delete expense"}
            </Button>
          </div>
        </div>
      )}
    </section>
  );
}
