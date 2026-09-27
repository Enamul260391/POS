<?php

declare(strict_types=1);

namespace App\Services\SaleReturn;

use App\Exceptions\BusinessRuleException;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SaleReturnService
{
    public const SORTABLE_COLUMNS = [
        'return_date', 'return_number', 'subtotal', 'refund_amount', 'status', 'created_at', 'id',
    ];

    public const DEFAULT_SORT_COLUMN = 'created_at';
    public const DEFAULT_SORT_DIRECTION = 'desc';
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function paginate(int $businessId, array $filters): LengthAwarePaginator
    {
        $query = SaleReturn::query()->forBusiness($businessId)->with(['sale', 'saleReturnItems']);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function (Builder $n) use ($term): void {
                $n->where('return_number', 'like', $term)->orWhere('reason', 'like', $term);
            });
        }
        if (($filters['sale_id'] ?? null) !== null) {
            $query->where('sale_id', (int) $filters['sale_id']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        $col = (string) ($filters['sort'] ?? self::DEFAULT_SORT_COLUMN);
        if (! in_array($col, self::SORTABLE_COLUMNS, true)) {
            $col = self::DEFAULT_SORT_COLUMN;
        }
        $dir = strtolower((string) ($filters['direction'] ?? self::DEFAULT_SORT_DIRECTION)) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($col, $dir)->orderBy('id')->paginate(max(1, min((int) ($filters['per_page'] ?? 20), 100)));
    }

    public function find(int $businessId, int $id): SaleReturn
    {
        return SaleReturn::query()
            ->forBusiness($businessId)
            ->with(['sale', 'customer', 'saleReturnItems.product', 'saleReturnItems.saleItem'])
            ->whereKey($id)
            ->firstOrFail();
    }

    public function create(int $businessId, ?int $userId, array $data): SaleReturn
    {
        return DB::transaction(function () use ($businessId, $userId, $data): SaleReturn {
            $sale = Sale::query()->forBusiness($businessId)->whereKey((int) $data['sale_id'])->lockForUpdate()->firstOrFail();
            // Phase 27: `product` is eager loaded here. The stock-in loop below
            // reads `$saleItem->product` for every returned line, so without
            // this each line issued its own `select * from products` query.
            $items = $sale->saleItems()->with('product')->orderBy('id')->get();
            $byId = $items->keyBy('id');
            $returned = [];
            foreach ($sale->saleReturns()->with('saleReturnItems')->get() as $ret) {
                foreach ($ret->saleReturnItems as $ri) {
                    $sid = (int) $ri->sale_item_id;
                    $returned[$sid] = isset($returned[$sid]) ? Quantity::add($returned[$sid], $ri->quantity) : Quantity::format($ri->quantity);
                }
            }
            $seen = [];
            $lines = [];
            foreach ($data['items'] as $row) {
                $sid = (int) $row['sale_item_id'];
                if (isset($seen[$sid])) {
                    throw new BusinessRuleException('Duplicate sale item in return.');
                }
                $seen[$sid] = true;
                $saleItem = $byId->get($sid);
                if ($saleItem === null) {
                    throw new BusinessRuleException('One or more sale items are invalid.');
                }
                $qty = Quantity::format($row['quantity']);
                $soldQty = Quantity::format($saleItem->quantity);
                $remain = Quantity::subtract($soldQty, Quantity::format($returned[$sid] ?? 0));
                if (! Quantity::isPositive($remain) || Quantity::isNegative(Quantity::subtract($remain, $qty))) {
                    throw new BusinessRuleException('Return quantity exceeds remaining quantity.');
                }
                // Pro-rata share via bcmath (never floats): returned / sold, 10-digit scale.
                $share = bcdiv($qty, $soldQty, 10);
                $lines[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $qty,
                    'unit_price' => Money::format($saleItem->unit_price),
                    'total' => Money::multiply(Money::format($saleItem->total), $share),
                ];
            }
            $subtotal = '0.00';
            foreach ($lines as $line) {
                $subtotal = Money::add($subtotal, $line['total']);
            }
            $ret = SaleReturn::create([
                'business_id' => $businessId,
                'sale_id' => $sale->getKey(),
                'customer_id' => $sale->customer_id,
                'return_number' => $data['return_number'] ?? null,
                'return_date' => isset($data['return_date']) ? Carbon::parse($data['return_date'])->toDateString() : now()->toDateString(),
                'reason' => $data['reason'] ?? null,
                'subtotal' => $subtotal,
                'tax' => '0.00',
                'refund_amount' => $subtotal,
                'status' => SaleReturn::STATUS_COMPLETED,
                'created_by' => $userId,
            ]);
            foreach ($lines as $line) {
                $ret->saleReturnItems()->create([
                    'sale_item_id' => $line['sale_item']->getKey(),
                    'product_id' => $line['sale_item']->product_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total' => $line['total'],
                ]);
                $product = $line['sale_item']->product;
                if ($product !== null && $product->tracksStock()) {
                    $this->inventory->record($product, $userId, StockMovement::TYPE_SALE_RETURN, Quantity::format($line['quantity']), 'Sale return #'.$ret->getKey(), SaleReturn::class, $ret->getKey());
                }
            }

            return $this->find($businessId, $ret->getKey());
        });
    }
}
