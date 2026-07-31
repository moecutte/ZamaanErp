<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function store(StorePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validated();

        try {
            $payment = $this->invoiceService->applyPayment(
                invoice: $invoice,
                amount: (float) $data['amount'],
                method: PaymentMethod::from($data['payment_method']),
                paidAt: $data['paid_at'] ?? now(),
                recordedBy: $request->user()->id,
            );

            return (new PaymentResource($payment->load('invoice')))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
