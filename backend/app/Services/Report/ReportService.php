<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\CashRegister\CashRegisterService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReportService
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    public function sales(int $businessId, array $filters): array
    {
        $query = Sale::query()->forBusiness($businessId);
        $this->applyDateRange($query, $filters, 'sale_date');
        if (($filters['customer_id'] ?? null) !== null) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['payment_method'] ?? null) !== null) {
            $query->whereHas('payments', fn (Builder $payments) => $payments->where('payment_method', $filters['payment_method']));
        }
        $row = $query->selectRaw('COUNT(*) as sale_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as subtotal')
            ->selectRaw('COALESCE(SUM(discount), 0) as discount')
            ->selectRaw('COALESCE(SUM(tax), 0) as tax')
            // grand_total is already net of the header discount and inclusive
            // of tax; never apply those values a second time here.
            ->selectRaw('COALESCE(SUM(grand_total), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due_amount')->first();

        return $this->withPeriod(filters: $filters, metrics: [
            'sale_count' => (int) $row->sale_count,
            'total_sales' => Money::format($row->total_sales),
            'subtotal' => Money::format($row->subtotal),
            'discount' => Money::format($row->discount),
            'tax' => Money::format($row->tax),
            'net_sales' => Money::format($row->net_sales),
            'paid_amount' => Money::format($row->paid_amount),
            'due_amount' => Money::format($row->due_amount),
        ]);
    }

    public function purchases(int $businessId, array $filters): array
    {
        $query = Purchase::query()->forBusiness($businessId);
        $this->applyDateRange($query, $filters, 'purchase_date');
        if (($filters['supplier_id'] ?? null) !== null) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        $itemQuantity = PurchaseItem::query()->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('purchase_items.purchase_id', 'purchases.id');
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

    public function expenses(int $businessId, array $filters): array
    {
        $query = Expense::query()->forBusiness($businessId);
        $this->applyDateRange($query, $filters, 'expense_date');
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

    public function profitLoss(int $businessId, array $filters): array
    {
        return $this->withPeriod($filters, [
            'available' => false,
            'limitation' => 'Historical cost of goods sold is unavailable because sale_items does not preserve unit cost.',
        ]);
    }

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
            'today_purchase_count' => $purchases['metrics']['purchase_count'],
            'today_purchase_total' => $purchases['metrics']['purchase_total'],
            'today_expense_count' => $expenses['metrics']['expense_count'],
            'today_total_expense' => $expenses['metrics']['total_expense'],
            'current_cash_balance' => $register?->current_balance ?? '0.00',
            'low_stock_count' => $inventory['low_stock_count'],
        ];
    }

    /**
     * @param  Builder<Sale|Purchase|Expense>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateRange(Builder $query, array $filters, string $column): void
    {
        if (($filters['date_from'] ?? null) !== null) {
            $query->where($column, '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $query->where($column, '<=', $filters['date_to']);
        }
    }

    /** @param array<string, mixed> $filters */
    private function withPeriod(array $filters, array $metrics): array
    {
        return ['period' => ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null], 'metrics' => $metrics];
    }
}
