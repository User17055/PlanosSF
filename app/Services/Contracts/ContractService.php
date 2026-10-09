<?php

namespace App\Services\Contracts;

use App\Enums\DocumentStatus;
use App\Enums\FileReviewStatus;
use App\Enums\ProposalStatus;
use App\Enums\SignatureMethod;
use App\Jobs\GenerateContractPdf;
use App\Models\AcceptanceEvidence;
use App\Models\ContactVerification;
use App\Models\Contract;
use App\Models\ContractFile;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PetEligibility;
use App\Services\Documents\DocumentStorage;
use App\Services\Proposals\CustomerFormService;
use App\Services\Subscriptions\ActivationService;
use App\Services\Subscriptions\SubscriptionRecorder;
use App\Support\Numbers;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Formalização: aceite digital, assinatura no tablet e papel digitalizado (RF-04, seções 5 a 8).
 * Nenhuma contratação fica válida sem o contrato final anexado à assinatura.
 */
class ContractService
{
    public function __construct(
        protected AuditLogger $audit,
        protected CustomerFormService $forms,
        protected ContractRenderer $renderer,
        protected PetEligibility $eligibility,
        protected SubscriptionRecorder $subscriptions,
        protected DocumentStorage $storage,
        protected ContractPdfService $pdf,
        protected ActivationService $activation,
    ) {}

    /**
     * Aceite digital do titular (site e link). Idempotente: aceitar de novo devolve o contrato existente.
     *
     * @param  array{ip:?string, user_agent:?string, session_id:?string, affirmative_action:string}  $ctx
     */
    public function acceptDigital(Proposal $proposal, array $ctx, ?ContactVerification $verification): Contract
    {
        if (config('planos.otp.required') && ! $verification) {
            throw ValidationException::withMessages(['code' => 'Confirme o código enviado ao seu e-mail antes de concluir.']);
        }

        return $this->formalize($proposal, SignatureMethod::Digital, function (Contract $contract) use ($ctx, $verification) {
            AcceptanceEvidence::create([
                'contract_id' => $contract->id,
                'method' => 'digital',
                'terms_version' => $this->termsVersion($contract),
                'affirmative_action' => $ctx['affirmative_action'],
                'session_hash' => $ctx['session_id'] ? hash('sha256', $ctx['session_id']) : null,
                'ip_address' => $ctx['ip'],
                'user_agent' => mb_substr((string) $ctx['user_agent'], 0, 500),
                'contact_verification_id' => $verification?->id,
                'verification_result' => $verification ? 'verified' : 'not_required',
                'accepted_at' => now(),
            ]);
        });
    }

    /**
     * Assinatura manuscrita no tablet: a imagem é incorporada ao PDF final. Uma imagem solta não equivale ao contrato.
     *
     * @param  array{ip:?string, user_agent:?string, session_id:?string}  $ctx
     */
    public function signTablet(Proposal $proposal, string $signaturePngBase64, User $collector, array $ctx): Contract
    {
        $png = $this->decodeSignature($signaturePngBase64);

        return $this->formalize($proposal, SignatureMethod::Tablet, function (Contract $contract) use ($png, $collector, $ctx) {
            $stored = $this->storage->putContents($png, 'contracts/'.$contract->number.'/signature', 'png', 'image/png');
            AcceptanceEvidence::create([
                'contract_id' => $contract->id,
                'method' => 'tablet',
                'terms_version' => $this->termsVersion($contract),
                'affirmative_action' => 'Titular conferiu os dados e o contrato na tela e assinou no tablet.',
                'session_hash' => $ctx['session_id'] ? hash('sha256', $ctx['session_id']) : null,
                'ip_address' => $ctx['ip'],
                'user_agent' => mb_substr((string) $ctx['user_agent'], 0, 500),
                'verification_result' => 'in_person',
                'signature_image_key' => $stored['storage_key'],
                'signature_image_sha256' => $stored['sha256'],
                'collected_by' => $collector->id,
                'accepted_at' => now(),
            ]);
        });
    }

    /**
     * Papel: gera o contrato para impressão com número de proposta e versão. A assinatura cria a
     * pendência documental até a digitalização ser conferida.
     */
    public function preparePaper(Proposal $proposal, User $user): Contract
    {
        return $this->formalize($proposal, SignatureMethod::Paper, null, $user);
    }

    public function uploadScan(Contract $contract, UploadedFile $file, User $user, string $signedOn, ?string $replacementReason = null): ContractFile
    {
        if ($contract->signature_method !== SignatureMethod::Paper) {
            throw ValidationException::withMessages(['file' => 'Este contrato não é do tipo papel.']);
        }
        if ($contract->document_status === DocumentStatus::Valid) {
            throw ValidationException::withMessages(['file' => 'Contrato já validado. A substituição exige registro de correção.']);
        }

        return DB::transaction(function () use ($contract, $file, $user, $signedOn, $replacementReason) {
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $current = $contract->currentFinalFile();
            if ($current && $current->review_status === FileReviewStatus::Pending && blank($replacementReason)) {
                throw ValidationException::withMessages(['replacement_reason' => 'Informe o motivo da substituição da digitalização pendente.']);
            }

            $stored = $this->storage->putUpload($file, 'contracts/'.$contract->number.'/scans');
            $version = (int) $contract->files()->where('kind', Contract::KIND_SIGNED_SCAN)->max('version') + 1;

            $new = $contract->files()->create($stored + [
                'kind' => Contract::KIND_SIGNED_SCAN,
                'version' => $version,
                'review_status' => FileReviewStatus::Pending,
                'uploaded_by' => $user->id,
                'replacement_reason' => $replacementReason,
            ]);

            if ($current) {
                $current->forceFill([
                    'superseded_by_id' => $new->id,
                    'review_status' => $current->review_status === FileReviewStatus::Pending ? FileReviewStatus::Superseded : $current->review_status,
                ])->save();
            }

            $contract->forceFill(['document_status' => DocumentStatus::AwaitingReview])->save();

            $this->audit->log('contract.scan_uploaded', $contract, [
                'arquivo_versao' => $version, 'data_assinatura' => $signedOn, 'sha256' => $stored['sha256'],
            ], $replacementReason);

            // A data de assinatura informada é guardada como evidência do papel.
            AcceptanceEvidence::create([
                'contract_id' => $contract->id,
                'method' => 'paper',
                'terms_version' => $this->termsVersion($contract),
                'affirmative_action' => 'Contrato impresso assinado pelo titular em '.$signedOn.' e digitalizado (versão '.$version.').',
                'verification_result' => 'pending_review',
                'collected_by' => $user->id,
                'accepted_at' => CarbonImmutable::parse($signedOn, config('planos.business_timezone'))->startOfDay(),
            ]);

            return $new;
        });
    }

    /** Conferência de integridade, legibilidade e identificação (seção 7.2). */
    public function reviewScan(ContractFile $file, User $reviewer, bool $approve, ?string $reason): Contract
    {
        if (! $approve && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da rejeição.']);
        }

        $contract = DB::transaction(function () use ($file, $reviewer, $approve, $reason) {
            $file = ContractFile::whereKey($file->id)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($file->contract_id)->lockForUpdate()->firstOrFail();

            if ($file->review_status !== FileReviewStatus::Pending || $file->superseded_by_id) {
                throw ValidationException::withMessages(['file' => 'Esta digitalização não está aguardando conferência.']);
            }

            $file->forceFill([
                'review_status' => $approve ? FileReviewStatus::Approved : FileReviewStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ])->save();

            if ($approve) {
                $signedEvidence = $contract->evidence()->where('method', 'paper')->latest('id')->first();
                $contract->forceFill([
                    'document_status' => DocumentStatus::Valid,
                    'validated_at' => now(),
                    'validated_by' => $reviewer->id,
                    'signed_at' => $signedEvidence?->accepted_at ?? now(),
                    'rejection_reason' => null,
                ])->save();
                $proposal = $contract->proposal;
                $proposal->forceFill([
                    'status' => ProposalStatus::AwaitingPayment,
                    'accepted_at' => $proposal->accepted_at ?? $contract->signed_at,
                ])->save();
            } else {
                $contract->forceFill(['document_status' => DocumentStatus::Rejected, 'rejection_reason' => $reason])->save();
            }

            $this->audit->log($approve ? 'contract.scan_approved' : 'contract.scan_rejected', $contract, [
                'arquivo_versao' => $file->version, 'enviado_por' => $file->uploaded_by, 'conferido_por' => $reviewer->id,
            ], $reason);

            return $contract;
        });

        if ($approve && $contract->subscription) {
            $this->activation->tryActivate($contract->subscription, 'Documento validado');
        }

        return $contract;
    }

    /**
     * Fluxo comum de formalização dentro de uma transação, com bloqueio da proposta.
     */
    protected function formalize(Proposal $proposal, SignatureMethod $method, ?callable $withEvidence, ?User $actor = null): Contract
    {
        $contract = DB::transaction(function () use ($proposal, $method, $withEvidence) {
            $proposal = Proposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            // Clique repetido: devolve o contrato já existente, sem criar outra assinatura.
            if ($existing = Contract::where('proposal_id', $proposal->id)->first()) {
                return $existing;
            }
            if ($proposal->signature_method !== $method) {
                throw ValidationException::withMessages(['proposal' => 'Forma de assinatura diferente da definida na proposta.']);
            }
            if (! $proposal->isOpen() || $proposal->status !== ProposalStatus::AwaitingAcceptance) {
                throw ValidationException::withMessages(['proposal' => 'Proposta expirada, revogada ou aguardando preenchimento. Solicite uma nova proposta.']);
            }
            if (! $proposal->planVersion->isOfferable()) {
                throw ValidationException::withMessages(['proposal' => 'As condições comerciais mudaram. É necessária uma nova proposta para revisão.']);
            }
            if (($missing = $this->forms->missing($proposal)) !== []) {
                throw ValidationException::withMessages(['proposal' => 'Cadastro incompleto: '.implode(', ', $missing).'.']);
            }

            [$customer, $pet] = $this->forms->materialize($proposal);
            if ($reasons = $this->eligibility->reasons($proposal->planVersion, $pet)) {
                throw ValidationException::withMessages(['proposal' => $reasons]);
            }

            $number = Numbers::generate('CTR', 'contracts');
            $frozen = $this->renderer->freeze($proposal, $customer, $pet, $number);
            $body = $this->renderer->renderBody((string) ($proposal->conditions_snapshot['contract_template']['body'] ?? ''), $frozen);

            $isPaper = $method === SignatureMethod::Paper;
            $contract = Contract::create([
                'proposal_id' => $proposal->id,
                'number' => $number,
                'signature_method' => $method,
                'document_status' => $isPaper ? DocumentStatus::AwaitingSignature : DocumentStatus::Pending,
                'body_text' => $body,
                'frozen_data' => $frozen,
                'signed_at' => $isPaper ? null : now(),
            ]);

            if ($withEvidence) {
                $withEvidence($contract);
            }

            $proposal->forceFill([
                'customer_id' => $customer->id,
                'pet_id' => $pet->id,
                'status' => $isPaper ? ProposalStatus::AwaitingDocument : ProposalStatus::AwaitingPayment,
                'accepted_at' => $isPaper ? null : now(),
                'token_expires_at' => $proposal->token_hash ? now()->addDays(30) : null,
            ])->save();

            $this->subscriptions->createFromProposal($proposal, $contract, $customer, $pet);

            $this->audit->log('contract.created', $contract, [
                'proposta' => $proposal->number, 'assinatura' => $method->value,
            ], null, $method === SignatureMethod::Digital ? 'customer' : 'user');

            return $contract;
        });

        // PDF gerado após o commit; falha mantém a pendência documental e permite reprocessamento.
        if ($method === SignatureMethod::Paper) {
            if (! $contract->files()->where('kind', Contract::KIND_PRINT_PDF)->exists()) {
                $this->pdf->generatePrint($contract);
            }
        } elseif (! $contract->currentFinalFile()) {
            GenerateContractPdf::dispatch($contract->id)->onQueue('documents');
        }

        return $contract->fresh();
    }

    protected function termsVersion(Contract $contract): string
    {
        $t = $contract->frozen_data['conditions']['contract_template'] ?? [];

        return ($t['name'] ?? 'modelo').' v'.($t['version'] ?? '?');
    }

    protected function decodeSignature(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw ValidationException::withMessages(['signature' => 'Assinatura inválida.']);
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) < 500 || strlen($png) > 2_000_000 || ! str_starts_with($png, "\x89PNG")) {
            throw ValidationException::withMessages(['signature' => 'Assinatura vazia ou inválida. Assine no quadro antes de concluir.']);
        }
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($png);
            if (! $img) {
                throw ValidationException::withMessages(['signature' => 'Assinatura inválida.']);
            }
            $inked = 0;
            $w = imagesx($img);
            $h = imagesy($img);
            for ($x = 0; $x < $w; $x += 3) {
                for ($y = 0; $y < $h; $y += 3) {
                    $alpha = (imagecolorat($img, $x, $y) >> 24) & 0x7F;
                    if ($alpha < 100) {
                        $inked++;
                    }
                }
            }
            imagedestroy($img);
            if ($inked < 30) {
                throw ValidationException::withMessages(['signature' => 'Assinatura vazia. Assine no quadro antes de concluir.']);
            }
        }

        return $png;
    }
}
