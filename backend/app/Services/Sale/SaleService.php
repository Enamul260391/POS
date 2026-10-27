<?php

declare(strict_types=1);

namespace App\Services\Sale;

use App\Exceptions\BusinessRuleException;
use App\Models\CashRegisterTransaction;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\CashRegister\CashRegisterService;
use App\Services\Customer\CustomerLedgerService;
use App\Services\Inventory\InventoryService;
use App\Services\InventoryBatch\InventoryBatchService;
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

    public const INSUFFICIENT_BATCH_MESSAGE = 'please add another batch of this product';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InventoryBatchService $batches,
        private readonly CashRegisterService $cashRegisters,
        private readonly InvoiceNumberService $invoiceNumbers,
        private readonly CustomerLedgerService $ledger,
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
                'saleItems.batch',
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
                $onHand = $balances[$pid] ?? '0';
                // Rule 2: zero ledger stock blocks the cart addition.
                if (Quantity::isZero($onHand) || Quantity::isNegative(Quantity::subtract($onHand, $need[$pid]))) {
                    throw new BusinessRuleException(sprintf('Insufficient stock for %s: only %s on hand.', $product->name, $onHand));
                }
            }

            // The backend owns the invoice number. `InvoiceNumberService`
            // allocates it from this business' locked sequence row inside the
            // transaction that is about to write the sale, so the number and
            // the document it identifies commit or roll back together and two
            // concurrent checkouts can never be handed the same one. Any
            // `invoice_number` the client sent is deliberately not read.
            $invoiceNumber = $this->invoiceNumbers->allocate($businessId);

            $sale = Sale::create([
                'business_id' => $businessId,
                'customer_id' => $data['customer_id'] ?? null,
                'invoice_number' => $invoiceNumber,
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
                $tracked = $line['product']->tracksStock();

                // STEP 4 batch-aware persistence. Allocation already happened in
                // resolvedLines (inside this transaction); here each split
                // writes its sale_item row with the authoritative price and
                // its own batch cost snapshot. Discount/tax are split
                // pro-rata so totals stay exact and are never duplicated.
                $splits = $line['splits'] ?? null;
                if ($tracked && is_array($splits) && $splits !== [] && ($line['allocated'] ?? false)) {
                    $splitMoney = $this->splitLineMoney($line, $splits);

                    foreach ($splits as $index => $split) {
                        $batchId = (int) $split['batch']->getKey();
                        $money = $splitMoney[$index];

                        $sale->saleItems()->create([
                            'product_id' => $line['product']->getKey(),
                            'quantity' => $split['quantity'],
                            'unit_price' => $line['unit_price'],
                            'unit_cost' => Money::format($split['batch']->cost_price),
                            'discount' => $money['discount'],
                            'tax' => $money['tax'],
                            'total' => $money['total'],
                            'batch_id' => $batchId,
                        ]);

                        $this->inventory->record(
                            $line['product'],
                            $userId,
                            StockMovement::TYPE_SALE,
                            Quantity::negate($split['quantity']),
                            'Sale #'.$sale->getKey(),
                            Sale::class,
                            $sale->getKey(),
                            $batchId,
                        );
                    }

                    continue;
                }

                // The units leave the batch they were sold from before they
                // leave the ledger, so the batch allocation and the ledger can
                // never disagree: both happen inside the one transaction that
                // writes the sale, and both roll back with it.
                // STEP 4: single-batch STEP 4 lines already allocated in
                // resolvedLines — reuse that batch and its cost snapshot
                // instead of allocating twice.
                $batchId = null;
                $unitCost = $line['unit_cost'];
                if ($tracked && $line['batch_id'] !== null) {
                    if (($line['allocated'] ?? false) && is_array($line['splits'] ?? null) && count($line['splits'] ?? []) === 1) {
                        $batch = $line['splits'][0]['batch'];
                        $batchId = (int) $batch->getKey();
                        $unitCost = Money::format($batch->cost_price);
                    } else {
                        // Legacy explicit batch_id path (pre-STEP-4 clients):
                        // allocate the named batch and authorise its price so
                        // totals, receipts and profit stay consistent.
                        $batch = $this->batches
                            ->allocate($businessId, (int) $line['product']->getKey(), (int) $line['batch_id'], $line['quantity']);
                        $batchId = (int) $batch->getKey();
                        $unitCost = Money::format($batch->cost_price);
                    }
                }

                $sale->saleItems()->create([
                    'product_id' => $line['product']->getKey(),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $unitCost,
                    'discount' => $line['discount'],
                    'tax' => $line['tax'],
                    'total' => $line['total'],
                    'batch_id' => $batchId,
                ]);

                if (! $tracked) {
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
                    $batchId,
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

            // Only the unpaid remainder becomes a receivable. A sale settled in
            // full at the counter creates nothing here: nothing was ever owed, so
            // an entry would be a receivable invented and immediately cancelled.
            //
            // `customer_id` is nullable in this schema, so a sale with a due but
            // no customer is a real possibility. That case keeps its `due_amount`
            // exactly as before and simply has no account to post to — there is
            // no ledger to record a balance against, so inventing one would be
            // worse than recording nothing.
            if ($sale->customer_id !== null && ! Money::isZero($dueAmount)) {
                $customer = Customer::query()->forBusiness($businessId)->whereKey($sale->customer_id)->first();
                if ($customer !== null) {
                    $this->ledger->recordSaleDue($customer, $dueAmount, (int) $sale->getKey(), $userId);
                }
            }

            return $this->find($businessId, $sale->getKey());
        });
    }

    public function update(Sale $sale, array $data): Sale
    {
        $this->assertEditablePayload($sale, $data);

        return DB::transaction(function () use ($sale, $data): Sale {
            // `invoice_number` is never written here: it is allocated once, at
            // creation, and `UpdateSaleRequest` rejects the key outright. It is
            // listed as guarded below so no future caller can sneak it in.
            $attributes = [];
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
        foreach (['customer_id', 'cashier_id', 'business_id', 'paid_amount', 'payment_status', 'status', 'due_amount', 'invoice_number'] as $guarded) {
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
     * @return array<int, array{product: Product, quantity: string, unit_price: string, discount: string, tax: string, total: string, batch_id: int|null, splits: array<int, array{batch: InventoryBatch, quantity: string}>|null}>
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
            $unitPrice = Money::format($item['unit_price'] ?? '0');
            $lineGross = Money::multiply($quantity, $unitPrice);
            $discount = Money::format($item['discount'] ?? 0);
            if (Money::greaterThan($discount, $lineGross)) {
                throw new BusinessRuleException(sprintf('Item discount for %s exceeds its line total.', $product->name));
            }
            $tax = Money::format($item['tax'] ?? 0);

            $batchId = $item['batch_id'] ?? null;
            $splits = null;

            // STEP 4 batch-aware line resolution. Non-tracked products never
            // touch batches. Tracked products follow the finalized rules:
            // no history -> legacy ledger-only (batch_id null); history but
            // all depleted -> reject (never fall back to legacy); otherwise
            // validate the selected price and allocate FIFO within it.
            if ($product->tracksStock()) {
                $resolved = $this->resolveBatchLine(
                    $businessId,
                    $product,
                    $quantity,
                    $unitPrice,
                    $batchId !== null ? (int) $batchId : null,
                );
                $unitPrice = $resolved['unit_price'];
                $batchId = $resolved['batch_id'];
                $splits = $resolved['splits'];
                $allocated = $resolved['allocated'] ?? false;
                $lineGross = Money::multiply($quantity, $unitPrice);
                if (Money::greaterThan($discount, $lineGross)) {
                    throw new BusinessRuleException(sprintf('Item discount for %s exceeds its line total.', $product->name));
                }
            }

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // Snapshot of the product's cost at the moment of the sale, so a
                // historical profit report reads the cost that was actually
                // incurred rather than whatever the product costs today.
                // Batch splits override this per physical batch at persist time.
                // For products without batch history, the latest purchase/batch
                // cost is used; otherwise null (no cost recorded yet).
                'unit_cost' => Money::format($this->latestBatchCost($product)),
                'discount' => $discount,
                'tax' => $tax,
                'total' => Money::add(Money::subtract($lineGross, $discount), $tax),
                'batch_id' => $batchId,
                'splits' => $splits,
                'allocated' => $allocated,
            ];
        }

        return $lines;
    }

    /**
     * Return the latest batch cost for a product, or null if none exists.
     *
     * Pricing now lives on inventory batches and purchase items, not on the
     * product itself. When a sale references a product with no batch history,
     * fall back to the most recent batch cost so the sale line still carries
     * a cost snapshot.
     *
     * @return string|null
     */
    private function latestBatchCost(Product $product): ?string
    {
        $batch = InventoryBatch::query()
            ->forBusiness((int) $product->business_id)
            ->where('product_id', $product->getKey())
            ->orderByDesc('id')
            ->first();

        return $batch?->cost_price;
    }

    /**
     * Resolve one tracked-product line to its STEP 4 batch allocation.
     *
     * Rules:
     *  - no batch history -> legacy ledger-only, batch_id null (price untouched);
     *  - history exists but nothing eligible -> 409 depleted (never legacy);
     *  - exactly one eligible batch -> auto-select its price;
     *  - several batches, one distinct price -> auto-select that price;
     *  - several distinct prices -> require an explicit selection whose price
     *    is one of the eligible prices; the client-sent unit_price must equal it.
     *
     * The selected price is authoritative: unit_price is rewritten to it and
     * allocation happens FIFO within that price only.
     *
     * ## What an explicit `batch_id` means
     *
     * The POS price selector does not let the cashier pick a physical lot: it
     * lets them pick a **selling price**, and `price-options` answers with one
     * option per price, each carrying that price's *baseline* batch (the
     * oldest eligible batch at it). A client that echoes that `batch_id` back is
     * therefore expressing "this price", not "this lot" — so the units are
     * allocated FIFO across every eligible batch at the confirmed price, the
     * same way the automatic single-price path already behaves. A `batch_id`
     * that is *not* the baseline of its price names a specific lot, and that
     * lot is then spent on its own.
     *
     * Either way the price is confirmed by the server: the named batch's own
     * price must equal the `unit_price` the client sent, so a selection can
     * never smuggle in a different price.
     *
     * @return array{unit_price: string, batch_id: int|null, splits: array<int, array{batch: InventoryBatch, quantity: string}>|null}
     */
    private function resolveBatchLine(
        int $businessId,
        Product $product,
        string $quantity,
        string $unitPrice,
        ?int $batchId,
    ): array {
        $productId = (int) $product->getKey();

        if (! $this->batches->hasHistory($businessId, $productId)) {
            return ['unit_price' => $unitPrice, 'batch_id' => null, 'splits' => null];
        }

        $eligible = $this->batches->eligibleForProduct($businessId, $productId);

        if ($eligible->isEmpty()) {
            throw new BusinessRuleException(self::INSUFFICIENT_BATCH_MESSAGE, 409);
        }

        $options = $this->batches->priceOptions($eligible);

        if ($options === []) {
            throw new BusinessRuleException(self::INSUFFICIENT_BATCH_MESSAGE, 409);
        }

        $selectedPrice = null;
        // `true` when the client named the baseline batch of a price (a price
        // choice, allocated FIFO at that price); `false` when it named a
        // specific physical lot, which is then spent on its own.
        $priceSelection = false;

        if ($batchId !== null) {
            // Explicit selection: the client must name an eligible batch
            // whose price matches the unit price they sent. The price is never
            // silently changed to match the batch — not even when only one
            // distinct selling price exists.
            $chosen = $eligible->firstWhere('id', $batchId);
            if ($chosen === null) {
                throw new BusinessRuleException('The selected batch does not belong to your business.', 422);
            }
            if ((int) $chosen->product_id !== $productId) {
                throw new BusinessRuleException('The selected batch does not belong to the product of this sale line.', 422);
            }

            $chosenPrice = Money::format($chosen->selling_price);
            if ($chosenPrice === null || ! Money::equals($unitPrice, $chosenPrice)) {
                throw new BusinessRuleException('The selling price does not match the selected batch price.', 422);
            }

            $selectedPrice = $chosenPrice;
            $priceSelection = $this->isBaselineBatchForPrice($options, $batchId, $selectedPrice);

            if (! $priceSelection) {
                // A named lot has to hold the whole quantity on its own: the
                // caller asked for that lot specifically, so it is never topped
                // up from its siblings. A shortfall surfaces as the basket-level
                // shortfall message — we never fall back to another price to
                // make up the difference. allocate() re-checks under its own
                // lock, so a concurrent depletion still surfaces as a 409 with
                // no sale written.
                if (Quantity::isNegative(Quantity::subtract(
                    Quantity::format($chosen->remaining_quantity),
                    $quantity,
                ))) {
                    throw new BusinessRuleException(self::INSUFFICIENT_BATCH_MESSAGE, 409);
                }
            }
        } elseif (count($options) === 1) {
            $selectedPrice = $options[0]['selling_price'];
            // Even with a single distinct price, the client-sent unit_price must
            // match the server-confirmed selling price. Reject rather than silently
            // replacing the client-confirmed price.
            if (! Money::equals($unitPrice, $selectedPrice)) {
                throw new BusinessRuleException('The selling price does not match the selected batch price.', 422);
            }
        } else {
            throw new BusinessRuleException('The selected batch is required for this product.', 422);
        }

        if ($batchId !== null && ! $priceSelection) {
            // The client named a physical lot: spend from that lot only, never
            // FIFO across the price. allocate() locks the row, checks ownership and
            // sufficient quantity, and decrements remaining_quantity.
            $batch = $this->batches->allocate($businessId, $productId, $batchId, $quantity);
            $splits = [['batch' => $batch, 'quantity' => $quantity]];
        } else {
            // A price choice: FIFO across every eligible batch at the confirmed
            // price, and never a single unit from another price. A shortfall
            // here is the combined shortfall of that price, reported with the
            // basket-level message.
            $allocations = $this->batches->allocateAtPrice($businessId, $productId, $selectedPrice, $quantity);

            $splits = [];
            foreach ($allocations as $allocation) {
                $splits[] = ['batch' => $allocation['batch'], 'quantity' => $allocation['quantity']];
            }
        }

        return [
            'unit_price' => $selectedPrice,
            'batch_id' => (int) $splits[0]['batch']->getKey(),
            // Keep single-element splits so persistence writes the batch's
            // own cost snapshot without re-allocating (allocation already
            // happened inside allocateAtPrice under the sale transaction).
            'splits' => $splits,
            'allocated' => true,
        ];
    }

    /**
     * Whether `$batchId` is the baseline batch the price-options endpoint
     * reports for `$sellingPrice`.
     *
     * `priceOptions()` groups the eligible batches by price and records, for
     * each price, the oldest eligible batch at it — the batch whose id the
     * price-options endpoint hands to the POS selector. A client that echoes
     * that id back has chosen a *price*; any other batch id names a specific
     * lot.
     *
     * @param  array<int, array{selling_price: string, batch_id: int, batch_number: string, available_quantity: string}>  $options
     */
    private function isBaselineBatchForPrice(array $options, int $batchId, string $sellingPrice): bool
    {
        foreach ($options as $option) {
            if (Money::equals((string) $option['selling_price'], $sellingPrice)) {
                return (int) $option['batch_id'] === $batchId;
            }
        }

        return false;
    }

    /**
     * Split discount/tax pro-rata across batch splits (poisha-exact).
     *
     * @param  array{quantity: string, unit_price: string, discount: string, tax: string}  $line
     * @param  array<int, array{batch: InventoryBatch, quantity: string}>  $splits
     * @return array<int, array{discount: string, tax: string, total: string}>
     */
    private function splitLineMoney(array $line, array $splits): array
    {
        $totalQty = (float) $line['quantity'];
        $discountCents = (int) round(((float) $line['discount']) * 100);
        $taxCents = (int) round(((float) $line['tax']) * 100);

        $out = [];
        $usedD = 0;
        $usedT = 0;
        $count = count($splits);

        foreach ($splits as $index => $split) {
            if ($index === $count - 1) {
                $d = $discountCents - $usedD;
                $t = $taxCents - $usedT;
            } else {
                $share = $totalQty > 0 ? ((float) $split['quantity']) / $totalQty : 0;
                $d = (int) round($discountCents * $share);
                $t = (int) round($taxCents * $share);
                $usedD += $d;
                $usedT += $t;
            }

            $discount = number_format($d / 100, 2, '.', '');
            $tax = number_format($t / 100, 2, '.', '');
            $gross = Money::multiply($split['quantity'], $line['unit_price']);

            $out[] = [
                'discount' => $discount,
                'tax' => $tax,
                'total' => Money::add(Money::subtract($gross, $discount), $tax),
            ];
        }

        return $out;
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
