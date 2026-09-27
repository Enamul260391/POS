<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Sale\IndexSaleRequest;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Requests\Sale\UpdateSaleRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SaleResource;
use App\Services\Payment\PaymentService;
use App\Services\Sale\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly PaymentService $payments,
    ) {}

    public function index(IndexSaleRequest $request): JsonResponse
    {
        $paginator = $this->sales->paginate($this->businessId($request), $request->filters());

        return $this->paginated($paginator, 'Sales retrieved successfully', SaleResource::class);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($this->businessId($request), $request->user()?->getKey(), $request->validated());

        return $this->success(new SaleResource($sale), 'Sale created successfully', 201);
    }

    public function show(Request $request, string $sale): JsonResponse
    {
        $found = $this->sales->find($this->businessId($request), (int) $sale);

        return $this->success(new SaleResource($found), 'Sale retrieved successfully');
    }

    public function update(UpdateSaleRequest $request, string $sale): JsonResponse
    {
        $found = $this->sales->find($this->businessId($request), (int) $sale);
        $updated = $this->sales->update($found, $request->validated());

        return $this->success(new SaleResource($updated), 'Sale updated successfully');
    }

    public function destroy(Request $request, string $sale): JsonResponse
    {
        $found = $this->sales->find($this->businessId($request), (int) $sale);
        $this->sales->delete($found);

        return $this->success(null, 'Sale deleted successfully');
    }

    public function pay(StorePaymentRequest $request, string $sale): JsonResponse
    {
        $found = $this->sales->find($this->businessId($request), (int) $sale);
        $payment = $this->payments->recordForSale($this->businessId($request), $request->user()?->getKey(), $found, $request->validated());

        return $this->success(new PaymentResource($payment), 'Payment recorded successfully', 201);
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
