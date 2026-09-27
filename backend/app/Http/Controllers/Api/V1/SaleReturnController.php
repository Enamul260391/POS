<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleReturn\IndexSaleReturnRequest;
use App\Http\Requests\SaleReturn\StoreSaleReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Services\SaleReturn\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleReturnController extends Controller
{
    public function __construct(
        private readonly SaleReturnService $returns,
    ) {}

    public function index(IndexSaleReturnRequest $request): JsonResponse
    {
        $paginator = $this->returns->paginate($this->businessId($request), $request->filters());

        return $this->paginated($paginator, 'Sale returns retrieved successfully', SaleReturnResource::class);
    }

    public function store(StoreSaleReturnRequest $request): JsonResponse
    {
        $ret = $this->returns->create($this->businessId($request), $request->user()?->getKey(), $request->validated());

        return $this->success(new SaleReturnResource($ret), 'Sale return created successfully', 201);
    }

    public function show(Request $request, string $saleReturn): JsonResponse
    {
        $found = $this->returns->find($this->businessId($request), (int) $saleReturn);

        return $this->success(new SaleReturnResource($found), 'Sale return retrieved successfully');
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()?->business_id;
    }
}
