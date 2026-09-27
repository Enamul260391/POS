<?php

declare(strict_types=1);

namespace App\Services\Sale;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for customer sales.
 *
 * Mirrors PurchaseService conventions: tenant-scoped resolution,
 * server-side totals (Money bcmath), ledger stock-out through
 * InventoryService inside one DB transaction, Option-B editing
 * (items/totals final) and guarded delete.
 */
final class SaleService
{
    public const SORTABLE_COLUMNS = [
        'sale_date', 'invoice_number', 'subtotal', 'discount', 'tax',
        'grand_total', 'paid_amount', 'due_amount', 'payment_status',
        'status', 'created_at', 'id',
    ];

    public const DEFAULT_SORT_COLUMN = 'created_at';

    public const DEFAULT_SORT_DIRECTION = 'desc';

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public const FINALIZED_MESSAGE = 'Sale items and totals cannot be modified after creation.';

    public const DELETE_MESSAGE = 'Sale cannot be deleted because it has inventory, payment or return history.';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
    ) {}

    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = Sale::query()->forBusiness($businessId)->with(['customer', 'saleItems']);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('invoice_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhereHas('customer', static function (Builder $c) use ($term): void {
                        $c->where('name', 'like', $term);
                    });
            });
        }

        if (($filters['customer_id'] ?? null) !== null) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['payment_status'] ?? null) !== null) {
            $query->where('payment_status', $filters['payment_status']);
        }
        if (($filters['date_from'] ?? null) !== null) {
            $query->whereDate('sale_date', '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $query->whereDate('sale_date', '<=', $filters['date_to']);
        }

        return $this->applyOrdering($query, $filters)->paginate($this->perPage($filters));
    }

    public function find(int $businessId, int $saleId): Sale
    {
        return Sale::query()
            ->forBusiness($businessId)
            ->with([
                'customer',
                'payments' => static fn ($q) => $q->orderBy('id'),
                'saleItems.product' => static function ($product) use ($businessId): void {
                    $product->withCurrentStock($businessId);
                },
            ])
            ->whereKey($saleId)
            ->firstOrFail();
    }

    public function create(int $businessId, ?int $userId, array $data): Sale
    {
        return DB::transaction(function () use ($businessId, $userId, $data): Sale {
            $saleDate = Carbon::parse($data['sale_date'])->toDateString();
            $lines = $this->resolvedLines($businessId, $data['items']);

            $subtotal = '0.00';
            foreach ($lines as $line) {
                $subtotal = Money::add($subtotal, $line['total']);
            }
            $discount = Money::format($data['discount'] ?? 0);
            if (Money::greaterThan($discount, $subtotal)) {
                throw new BusinessRuleException('Sale discount exceeds the subtotal.');
            }
            $tax = Money::format($data['tax'] ?? 0);
            $grandTotal = Money::add(Money::subtract($subtotal, $discount), $tax);
            $paidAmount = Money::format($data['paid_amount'] ?? 0);
            if (Money::greaterThan($paidAmount, $grandTotal)) {
                throw new BusinessRuleException('Paid amount exceeds the grand total.');
            }
            $dueAmount = Money::subtract($grandTotal, $paidAmount);

            $need = [];
            $byProduct = [];
            foreach ($lines as $line) {
                $pid = $line['product']->getKey();
                $need[$pid] = isset($need[$pid]) ? Quantity::add($need[$pid], $line['quantity']) : $line['quantity'];
                $byProduct[$pid] ??= $line['product'];
            }

            // Phase 27: one grouped ledger aggregate for the whole basket
            // instead of one SUM per product, and the per-product lookup is
            // now a map read instead of a rescan of every line.
            $trackedIds = array_values(array_filter(
                array_keys($byProduct),
                static fn (int $pid): bool => $byProduct[$pid]->tracksStock(),
            ));
            $balances = $this->inventory->currentStockForProducts($businessId, $trackedIds);

            foreach ($trackedIds as $pid) {
                $product = $byProduct[$pid];
                $onHand = $balances[$pid];
                if (Quantity::isNegative(Quantity::subtract($onHand, $need[$pid]))) {
                    throw new BusinessRuleException(sprintf('Insufficient stock for %s: only %s on hand.', $product->name, $onHand));
                }
            }

            $sale = Sale::create([
                'business_id' => $businessId,
                'customer_id' => $data['customer_id'] ?? null,
                'invoice_number' => $data['invoice_number'] ?? null,
                'sale_date' => $saleDate,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_status' => $this->paymentStatus($grandTotal, $paidAmount),
                'status' => Sale::STATUS_COMPLETED,
                'notes' => $data['notes'] ?? null,
                'cashier_id' => $userId,
            ]);

            foreach ($lines as $line) {
                $sale->saleItems()->create([
                    'product_id' => $line['product']->getKey(),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount' => $line['discount'],
                    'tax' => $line['tax'],
                    'total' => $line['total'],
                ]);
            }

            foreach ($lines as $line) {
                if (! $line['product']->tracksStock()) {
                    continue;
                }
                $this->inventory->record(
                    $line['product'],
                    $userId,
                    StockMovement::TYPE_SALE,
                    Quantity::negate($line['quantity']),
                    'Sale #'.$sale->getKey(),
                    Sale::class,
                    $sale->getKey(),
                );
            }

            if (! Money::isZero($paidAmount)) {
                $payment = $sale->payments()->create([
                    'business_id' => $businessId,
                    'payment_method' => $data['payment_method'] ?? Payment::PAYMENT_METHOD_CASH,
                    'amount' => $paidAmount,
                    'reference' => $data['payment_reference'] ?? null,
                    'paid_at' => now(),
                    'received_by' => $userId,
                    'notes' => null,
                ]);

                if ($payment->payment_method === Payment::PAYMENT_METHOD_CASH && $userId !== null) {
                    $this->cashRegisters->postAutomaticTransaction(
                        $businessId,
                        $userId,
                        CashRegisterTransaction::TYPE_SALE,
                        $paidAmount,
                        Payment::class,
                        (int) $payment->getKey(),
                    );
                }
            }

            return $this->find($businessId, $sale->getKey());
        });
    }

    public function update(Sale $sale, array $data): Sale
    {
        $this->assertEditablePayload($sale, $data);

        return DB::transaction(function () use ($sale, $data): Sale {
            $attributes = [];
            if (array_key_exists('invoice_number', $data)) {
                $attributes['invoice_number'] = $data['invoice_number'];
            }
            if (array_key_exists('sale_date', $data)) {
                $attributes['sale_date'] = Carbon::parse($data['sale_date'])->toDateString();
            }
            if (array_key_exists('notes', $data)) {
                $attributes['notes'] = $data['notes'];
            }
            if ($attributes !== []) {
                $sale->fill($attributes)->save();
            }

            return $this->find($sale->business_id, $sale->getKey());
        });
    }

    public function delete(Sale $sale): void
    {
        if ($sale->saleItems()->exists()
            || $sale->payments()->exists()
            || $sale->saleReturns()->exists()
            || StockMovement::query()->where('reference_type', Sale::class)->where('reference_id', $sale->getKey())->exists()) {
            throw new BusinessRuleException(self::DELETE_MESSAGE);
        }

        $sale->delete();
    }

    private function paymentStatus(string $grand, string $paid): string
    {
        if (Money::isZero($paid)) {
            return Sale::PAYMENT_STATUS_PENDING;
        }

        return Money::equals($paid, $grand) ? Sale::PAYMENT_STATUS_PAID : Sale::PAYMENT_STATUS_PARTIAL;
    }

    private function assertEditablePayload(Sale $sale, array $data): void
    {
        foreach (['customer_id', 'cashier_id', 'business_id', 'paid_amount', 'payment_status', 'status', 'due_amount'] as $guarded) {
            if (array_key_exists($guarded, $data)) {
                throw new BusinessRuleException(self::FINALIZED_MESSAGE);
            }
        }

        foreach (['subtotal', 'discount', 'tax', 'grand_total'] as $key) {
            if (array_key_exists($key, $data) && ! Money::equals((string) $data[$key], (string) $sale->getAttribute($key))) {
                throw new BusinessRuleException(self::FINALIZED_MESSAGE);
            }
        }

        if (array_key_exists('items', $data)) {
            $stored = $sale->saleItems()->orderBy('id')->get(['product_id', 'quantity', 'unit_price', 'discount', 'tax'])->toArray();
            $incoming = $data['items'];
            $matches = count($stored) === count($incoming);
            if ($matches) {
                foreach ($stored as $i => $row) {
                    $in = $incoming[$i];
                    if ((int) $row['product_id'] !== (int) $in['product_id']
                        || Quantity::format($row['quantity']) !== Quantity::format($in['quantity'] ?? 0)
                        || ! Money::equals($row['unit_price'], Money::format($in['unit_price'] ?? 0))
                        || ! Money::equals($row['discount'], Money::format($in['discount'] ?? 0))
                        || ! Money::equals($row['tax'], Money::format($in['tax'] ?? 0))) {
                        $matches = false;
                        break;
                    }
                }
            }
            if (! $matches) {
                throw new BusinessRuleException(self::FINALIZED_MESSAGE);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{product: Product, quantity: string, unit_price: string, discount: string, tax: string, total: string}>
     */
    private function resolvedLines(int $businessId, array $items): array
    {
        $productIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['product_id'],
            $items,
        )));

        $products = Product::query()
            ->forBusiness($businessId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            $product = $products->get((int) $item['product_id']);
            if ($product === null) {
                throw new BusinessRuleException('One or more products are invalid.');
            }

            $quantity = Quantity::format($item['quantity']);
            $unitPrice = Money::format($item['unit_price'] ?? $product->selling_price);
            $lineGross = Money::multiply($quantity, $unitPrice);
            $discount = Money::format($item['discount'] ?? 0);
            if (Money::greaterThan($discount, $lineGross)) {
                throw new BusinessRuleException(sprintf('Item discount for %s exceeds its line total.', $product->name));
            }
            $tax = Money::format($item['tax'] ?? 0);

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax' => $tax,
                'total' => Money::add(Money::subtract($lineGross, $discount), $tax),
            ];
        }

        return $lines;
    }

    private function applyOrdering(Builder $query, array $filters): Builder
    {
        $column = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = self::DEFAULT_SORT_COLUMN;
        }
        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderBy('id');
    }

    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
