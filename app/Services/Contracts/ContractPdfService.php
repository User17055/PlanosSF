<?php

namespace App\Services\Contracts;

use App\Enums\DocumentStatus;
use App\Enums\FileReviewStatus;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentStorage;
use App\Services\Subscriptions\ActivationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Geração do PDF a partir dos dados congelados. O renderizador não acessa URLs (seção 24):
 * a imagem da assinatura entra embutida como data URI.
 */
class ContractPdfService
{
    public function __construct(
        protected DocumentStorage $storage,
        protected AuditLogger $audit,
        protected ActivationService $activation,
    ) {}

    public function generateFinal(Contract $contract): ContractFile
    {
        $file = DB::transaction(function () use ($contract) {
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($existing = $contract->currentFinalFile()) {
                return $existing;
            }
            if ($contract->signature_method->value === 'paper') {
                throw new \LogicException('Contrato em papel é validado pela digitalização.');
            }

            $evidence = $contract->evidence()->latest('id')->first();
            $signature = null;
            if ($evidence?->signature_image_key) {
                $signature = 'data:image/png;base64,'.base64_encode($this->storage->get($this->storage->disk(), $evidence->signature_image_key));
            }

            $bytes = $this->render('pdf.contract', [
                'contract' => $contract,
                'data' => $contract->frozen_data,
                'evidence' => $evidence,
                'signature' => $signature,
                'final' => true,
            ]);

            $stored = $this->storage->putContents($bytes, 'contracts/'.$contract->number, 'pdf', 'application/pdf');
            $file = $contract->files()->create($stored + [
                'kind' => Contract::KIND_FINAL_PDF,
                'version' => 1,
                'review_status' => FileReviewStatus::Approved,
                'reviewed_at' => now(),
                'review_reason' => 'Documento gerado pelo sistema a partir dos dados congelados no aceite.',
            ]);

            $contract->forceFill([
                'document_status' => DocumentStatus::Valid,
                'validated_at' => now(),
                'pdf_failed_at' => null,
                'pdf_error' => null,
            ])->save();

            $this->audit->log('contract.pdf_generated', $contract, ['sha256' => $stored['sha256'], 'tamanho' => $stored['size']], null, 'system');

            return $file;
        });

        if ($subscription = $contract->subscription()->first()) {
            $this->activation->tryActivate($subscription, 'Contrato final armazenado');
        }

        return $file;
    }

    public function generatePrint(Contract $contract): ContractFile
    {
        return DB::transaction(function () use ($contract) {
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($existing = $contract->files()->where('kind', Contract::KIND_PRINT_PDF)->first()) {
                return $existing;
            }
            $bytes = $this->render('pdf.contract', [
                'contract' => $contract,
                'data' => $contract->frozen_data,
                'evidence' => null,
                'signature' => null,
                'final' => false,
            ]);
            $stored = $this->storage->putContents($bytes, 'contracts/'.$contract->number, 'pdf', 'application/pdf');

            return $contract->files()->create($stored + [
                'kind' => Contract::KIND_PRINT_PDF,
                'version' => 1,
                'review_status' => FileReviewStatus::Approved,
                'review_reason' => 'Via para impressão e assinatura em papel; não é o documento final.',
            ]);
        });
    }

    public function markFailure(Contract $contract, \Throwable $e): void
    {
        $contract->forceFill([
            'pdf_failed_at' => now(),
            'pdf_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
        $this->audit->log('contract.pdf_failed', $contract, ['erro' => mb_substr($e->getMessage(), 0, 200)], null, 'system');
    }

    protected function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'chroot' => resource_path('views/pdf'),
            ])
            ->output();
    }
}
