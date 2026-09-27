<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegister\CashRegisterTransactionRequest;
use App\Http\Requests\CashRegister\CloseCashRegisterRequest;
use App\Http\Requests\CashRegister\IndexCashRegisterTransactionRequest;
use App\Http\Requests\CashRegister\OpenCashRegisterRequest;
use App\Http\Resources\CashRegisterResource;
use App\Http\Resources\CashRegisterTransactionResource;
use App\Http\Traits\ApiResponse;
use App\Services\CashRegister\CashRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function __construct(private readonly CashRegisterService $registers) {}

    public function open(OpenCashRegisterRequest $request): JsonResponse
    {
        $register = $this->registers->open($this->businessId($request), $this->userId($request), $request->validated());
        return $this->success(new CashRegisterResource($register), 'Cash register opened successfully', 201);
    }

    public function current(Request $request): JsonResponse
    {
        $register = $this->registers->current($this->businessId($request), $this->userId($request));
        return $this->success(new CashRegisterResource($register), 'Current cash register retrieved successfully');
    }

    public function show(Request $request, string $cashRegister): JsonResponse
    {
        $register = $this->registers->find($this->businessId($request), (int) $cashRegister);
        return $this->success(new CashRegisterResource($register), 'Cash register retrieved successfully');
    }

    public function transactions(IndexCashRegisterTransactionRequest $request, string $cashRegister): JsonResponse
    {
        $paginator = $this->registers->transactions($this->businessId($request), $this->userId($request), (int) $cashRegister, $request->filters());
        return $this->paginated($paginator, 'Cash register transactions retrieved successfully', CashRegisterTransactionResource::class);
    }

    public function storeTransaction(CashRegisterTransactionRequest $request, string $cashRegister): JsonResponse
    {
        $transaction = $this->registers->recordManual($this->businessId($request), $this->userId($request), (int) $cashRegister, $request->validated());
        return $this->success(new CashRegisterTransactionResource($transaction), 'Cash transaction recorded successfully', 201);
    }

    public function close(CloseCashRegisterRequest $request, string $cashRegister): JsonResponse
    {
        $register = $this->registers->close($this->businessId($request), $this->userId($request), (int) $cashRegister, $request->validated());
        return $this->success(new CashRegisterResource($register), 'Cash register closed successfully');
    }

    public function summary(Request $request, string $cashRegister): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'business_id' => $this->businessId($request),
        ];
        return $this->success($this->registers->summary($this->businessId($request), $this->userId($request), (int) $cashRegister, $filters), 'Cash register summary retrieved successfully');
    }

    private function businessId(Request $request): int { return (int) $request->user()?->business_id; }
    private function userId(Request $request): int { return (int) $request->user()?->getKey(); }
}
