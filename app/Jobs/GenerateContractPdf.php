<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Services\Contracts\ContractPdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateContractPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public int $contractId) {}

    public function handle(ContractPdfService $pdf): void
    {
        $contract = Contract::find($this->contractId);
        if (! $contract) {
            return;
        }
        try {
            $pdf->generateFinal($contract);
        } catch (\Throwable $e) {
            $pdf->markFailure($contract, $e);
            throw $e;
        }
    }
}
