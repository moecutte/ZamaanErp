<?php

namespace App\Services;

use App\Enums\WastageReason;
use App\Models\Batch;
use App\Models\ProductForm;
use App\Models\ProductProcessing;
use App\Models\ProductProcessingOutput;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Converts base-form stock into sellable forms with weighed output and waste.
 *
 * Inventory math:
 *   ProcessingOut(source, sum(processed)) + WastageOut(source, waste) = source weight
 *   ProcessingIn(output batch, weighed qty) per output form
 */
class ProcessProductFormService
{
    public const WEIGHT_TOLERANCE = 0.001;

    public function __construct(
        private readonly StockService $stockService,
    ) {}

    /**
     * @param  array<int, array{product_form_id: int, quantity: float|int|string}>  $outputs
     */
    public function process(
        Batch $sourceBatch,
        float $sourceQuantity,
        float $wasteQuantity,
        array $outputs,
        ?string $notes = null,
        ?int $createdBy = null,
    ): ProductProcessing {
        if ($sourceQuantity <= 0) {
            throw new \InvalidArgumentException('Source weight must be greater than zero.');
        }

        if ($wasteQuantity < 0) {
            throw new \InvalidArgumentException('Waste weight cannot be negative.');
        }

        if ($outputs === []) {
            throw new \InvalidArgumentException('At least one processed output form is required.');
        }

        $createdBy ??= Auth::id();

        return DB::transaction(function () use ($sourceBatch, $sourceQuantity, $wasteQuantity, $outputs, $notes, $createdBy) {
            $source = Batch::query()
                ->whereKey($sourceBatch->id)
                ->with(['product', 'productForm'])
                ->lockForUpdate()
                ->firstOrFail();

            $sourceForm = $source->productForm;
            if ($sourceForm === null || ! $sourceForm->is_base) {
                throw new \RuntimeException(
                    'Processing source must be a base-form batch (e.g. Whole).'
                );
            }

            $normalizedOutputs = $this->normalizeOutputs($source->product_id, $outputs);
            $processedTotal = round(array_sum(array_column($normalizedOutputs, 'quantity')), 3);
            $accounted = round($processedTotal + $wasteQuantity, 3);
            $delta = abs(round($sourceQuantity - $accounted, 3));

            if ($delta > self::WEIGHT_TOLERANCE) {
                throw new \InvalidArgumentException(
                    "Weighed amounts do not balance. Source {$sourceQuantity}, "
                    . "processed {$processedTotal}, waste {$wasteQuantity} "
                    . '(difference ' . round($sourceQuantity - $accounted, 3) . ').'
                );
            }

            if ($processedTotal <= 0) {
                throw new \InvalidArgumentException('Total processed weight must be greater than zero.');
            }

            $processing = ProductProcessing::create([
                'product_id' => $source->product_id,
                'source_batch_id' => $source->id,
                'source_quantity' => $sourceQuantity,
                'waste_quantity' => $wasteQuantity,
                'notes' => $notes,
                'processed_at' => now(),
                'created_by' => $createdBy,
            ]);

            // Deduct usable (processed) weight from source
            $this->stockService->recordProcessingOut(
                batch: $source,
                quantity: $processedTotal,
                reference: $processing,
                createdBy: $createdBy,
            );

            // Deduct weighed waste from source
            if ($wasteQuantity > 0) {
                $this->stockService->recordWastage(
                    batch: $source->fresh(),
                    quantity: $wasteQuantity,
                    reason: WastageReason::Processing->label() . ($notes ? ": {$notes}" : ''),
                    reference: $processing,
                    createdBy: $createdBy,
                );
            }

            foreach ($normalizedOutputs as $output) {
                $outputBatch = Batch::create([
                    'product_id' => $source->product_id,
                    'product_form_id' => $output['product_form_id'],
                    'supplier_id' => $source->supplier_id,
                    'catch_date' => $source->catch_date,
                    'production_date' => $source->production_date ?? now()->toDateString(),
                    'expiry_date' => $source->expiry_date,
                    'quantity_received' => $output['quantity'],
                    'quantity_available' => 0,
                    'storage_location' => $source->storage_location,
                    'unit_cost' => $source->unit_cost,
                ]);

                $this->stockService->recordProcessingIn(
                    batch: $outputBatch,
                    quantity: $output['quantity'],
                    reference: $processing,
                    createdBy: $createdBy,
                );

                ProductProcessingOutput::create([
                    'product_processing_id' => $processing->id,
                    'product_form_id' => $output['product_form_id'],
                    'quantity' => $output['quantity'],
                    'output_batch_id' => $outputBatch->id,
                ]);
            }

            return $processing->fresh(['outputs.productForm', 'outputs.outputBatch', 'sourceBatch']);
        });
    }

    /**
     * @param  array<int, array{product_form_id: int, quantity: float|int|string}>  $outputs
     * @return list<array{product_form_id: int, quantity: float}>
     */
    private function normalizeOutputs(int $productId, array $outputs): array
    {
        $normalized = [];
        $seenForms = [];

        foreach ($outputs as $index => $output) {
            $formId = (int) ($output['product_form_id'] ?? 0);
            $qty = round((float) ($output['quantity'] ?? 0), 3);

            if ($formId <= 0) {
                throw new \InvalidArgumentException("Output #{$index}: product form is required.");
            }

            if ($qty <= 0) {
                throw new \InvalidArgumentException("Output #{$index}: processed weight must be greater than zero.");
            }

            if (isset($seenForms[$formId])) {
                throw new \InvalidArgumentException('Each output form may only appear once per processing.');
            }
            $seenForms[$formId] = true;

            $form = ProductForm::query()
                ->whereKey($formId)
                ->where('product_id', $productId)
                ->first();

            if ($form === null) {
                throw new \InvalidArgumentException("Output #{$index}: form does not belong to this product.");
            }

            if ($form->is_base) {
                throw new \InvalidArgumentException(
                    "Output #{$index}: cannot process into the base form \"{$form->name}\"."
                );
            }

            if (! $form->is_active) {
                throw new \InvalidArgumentException("Output #{$index}: form \"{$form->name}\" is inactive.");
            }

            $normalized[] = [
                'product_form_id' => $form->id,
                'quantity' => $qty,
            ];
        }

        return $normalized;
    }
}
