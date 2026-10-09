<?php

namespace App\Services\Proposals;

use App\Enums\FillMode;
use App\Enums\ProposalChannel;
use App\Enums\ProposalStatus;
use App\Enums\SignatureMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Pet;
use App\Models\PlanVersion;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\ConditionsSnapshot;
use App\Services\Catalog\PetEligibility;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProposalService
{
    public function __construct(
        protected AuditLogger $audit,
        protected ConditionsSnapshot $snapshot,
        protected PetEligibility $eligibility,
    ) {}

    /**
     * Cria proposta com versão comercial fixa (POST /proposals).
     *
     * @param  array{plan_version_id:int, channel:string, fill_mode?:string, signature_method?:string, customer_id?:int|null, pet_id?:int|null, desired_start_date?:string|null, supersedes_id?:int|null, revoke_superseded?:bool}  $data
     */
    public function create(array $data, ?User $seller, Franchise $origin): Proposal
    {
        $channel = ProposalChannel::from($data['channel']);
        $fillMode = FillMode::from($data['fill_mode'] ?? FillMode::Seller->value);
        $signature = match ($channel) {
            ProposalChannel::InPerson => SignatureMethod::from($data['signature_method'] ?? ''),
            default => SignatureMethod::Digital,
        };
        if ($channel === ProposalChannel::InPerson && ! in_array($signature, [SignatureMethod::Tablet, SignatureMethod::Paper], true)) {
            throw ValidationException::withMessages(['signature_method' => 'No presencial, escolha tablet ou papel.']);
        }
        if ($channel === ProposalChannel::InPerson) {
            $fillMode = FillMode::Seller;
        }
        if ($channel === ProposalChannel::Site) {
            $fillMode = FillMode::Customer;
        }

        $version = PlanVersion::with('plan', 'benefitRules', 'contractTemplate')->findOrFail($data['plan_version_id']);
        if (! $version->isOfferable()) {
            throw ValidationException::withMessages(['plan_version_id' => 'Esta versão do plano não está disponível para novas vendas.']);
        }
        if (! $origin->is_central && ! $version->availableForFranchise($origin->id)) {
            throw ValidationException::withMessages(['plan_version_id' => 'Plano não disponível para esta unidade.']);
        }

        $customer = isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $pet = isset($data['pet_id']) ? Pet::findOrFail($data['pet_id']) : null;

        if ($pet && (! $customer || $pet->customer_id !== $customer->id)) {
            throw ValidationException::withMessages(['pet_id' => 'O pet informado não pertence a este tutor.']);
        }
        if ($fillMode === FillMode::Seller && (! $customer || ! $pet)) {
            throw ValidationException::withMessages(['pet_id' => 'Quando o vendedor preenche, informe tutor e pet.']);
        }
        if ($pet && ($reasons = $this->eligibility->reasons($version, $pet)) !== []) {
            throw ValidationException::withMessages(['pet_id' => $reasons]);
        }

        $status = $fillMode === FillMode::Customer ? ProposalStatus::AwaitingFill : ProposalStatus::AwaitingAcceptance;

        return DB::transaction(function () use ($data, $seller, $origin, $channel, $fillMode, $signature, $version, $customer, $pet, $status) {
            $snapshot = $this->snapshot->build($version);

            $proposal = Proposal::create([
                'number' => Numbers::generate('PRP', 'proposals'),
                'customer_id' => $customer?->id,
                'pet_id' => $pet?->id,
                'plan_version_id' => $version->id,
                'channel' => $channel,
                'fill_mode' => $fillMode,
                'signature_method' => $signature,
                'status' => $status,
                'seller_id' => $seller?->id,
                'originating_franchise_id' => $origin->id,
                'price_cents' => $snapshot['price_cents'],
                'enrollment_fee_cents' => $snapshot['enrollment_fee_cents'],
                'conditions_snapshot' => $snapshot,
                'desired_start_date' => $data['desired_start_date'] ?? null,
                'expires_at' => now()->addDays(config('planos.proposals.expiry_days')),
                'supersedes_id' => $data['supersedes_id'] ?? null,
                'created_by' => $seller?->id,
            ]);

            if (! empty($data['supersedes_id']) && ! empty($data['revoke_superseded'])) {
                $previous = Proposal::whereKey($data['supersedes_id'])->lockForUpdate()->first();
                if ($previous && ! $previous->hasBeenAccepted() && ! $previous->isFinal()) {
                    $this->revoke($previous, $seller, 'Substituída pela proposta '.$proposal->number);
                }
            }

            $this->audit->log('proposal.created', $proposal, [
                'canal' => $channel->value, 'preenchimento' => $fillMode->value,
                'assinatura' => $signature->value, 'plano' => $snapshot['plan']['code'].' v'.$snapshot['version']['number'],
            ]);

            return $proposal;
        });
    }

    /**
     * Alerta sobre outra contratação ativa ou em andamento para o mesmo pet e plano (seção 6).
     *
     * @return array<int, string>
     */
    public function duplicateWarnings(?int $petId, int $planVersionId, ?int $ignoreProposalId = null): array
    {
        if (! $petId) {
            return [];
        }
        $planId = PlanVersion::whereKey($planVersionId)->value('plan_id');
        $versionIds = PlanVersion::where('plan_id', $planId)->pluck('id');
        $warnings = [];

        $activeSubs = Subscription::where('pet_id', $petId)->whereIn('plan_version_id', $versionIds)
            ->whereNotIn('status', [SubscriptionStatus::Canceled->value, SubscriptionStatus::Ended->value])->count();
        if ($activeSubs > 0) {
            $warnings[] = 'Este pet já possui assinatura deste plano ativa ou em regularização.';
        }

        $openProposals = Proposal::where('pet_id', $petId)->whereIn('plan_version_id', $versionIds)
            ->whereIn('status', [ProposalStatus::AwaitingFill->value, ProposalStatus::AwaitingAcceptance->value, ProposalStatus::Created->value])
            ->where('expires_at', '>', now())
            ->when($ignoreProposalId, fn ($q) => $q->where('id', '!=', $ignoreProposalId))
            ->count();
        if ($openProposals > 0) {
            $warnings[] = 'Existe outra proposta em andamento para este pet e plano.';
        }

        return $warnings;
    }

    /**
     * Gera link com token aleatório, validade configurável e revogação. Um novo link revoga o anterior.
     * Retorna o token em texto: somente o hash fica armazenado.
     */
    public function issueLink(Proposal $proposal, ?User $user, ?string $sentChannel = null): string
    {
        if ($proposal->hasBeenAccepted() || $proposal->isFinal()) {
            throw ValidationException::withMessages(['link' => 'Proposta já concluída ou encerrada; não é possível gerar novo link.']);
        }
        if (! in_array($proposal->channel, [ProposalChannel::Link, ProposalChannel::Site], true)) {
            throw ValidationException::withMessages(['link' => 'Links só existem nos canais site e link individual.']);
        }

        $token = Str::random(48);
        $proposal->forceFill([
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addDays(config('planos.proposals.link_expiry_days'))->min($proposal->expires_at),
            'token_revoked_at' => null,
            'token_revoked_by' => null,
            'link_sent_channel' => $sentChannel,
            'link_sent_at' => $sentChannel ? now() : null,
            'link_sent_by' => $sentChannel ? $user?->id : null,
        ])->save();

        $this->audit->log('proposal.link_issued', $proposal, ['canal_envio' => $sentChannel]);

        return $token;
    }

    public function registerLinkSent(Proposal $proposal, User $user, string $channel): void
    {
        $proposal->forceFill(['link_sent_channel' => $channel, 'link_sent_at' => now(), 'link_sent_by' => $user->id])->save();
        $this->audit->log('proposal.link_sent', $proposal, ['canal' => $channel]);
    }

    public function revokeLink(Proposal $proposal, ?User $user): void
    {
        $proposal->forceFill(['token_revoked_at' => now(), 'token_revoked_by' => $user?->id])->save();
        $this->audit->log('proposal.link_revoked', $proposal);
    }

    public function findByToken(string $token): ?Proposal
    {
        if (strlen($token) < 32) {
            return null;
        }

        return Proposal::where('token_hash', hash('sha256', $token))->first();
    }

    public function revoke(Proposal $proposal, ?User $user, string $reason): void
    {
        if ($proposal->hasBeenAccepted()) {
            throw ValidationException::withMessages(['proposal' => 'Proposta aceita não pode ser revogada; use o cancelamento da assinatura.']);
        }
        $proposal->forceFill([
            'status' => ProposalStatus::Revoked,
            'token_revoked_at' => $proposal->token_revoked_at ?? now(),
            'token_revoked_by' => $proposal->token_revoked_by ?? $user?->id,
            'canceled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();
        $this->audit->log('proposal.revoked', $proposal, null, $reason);
    }

    /** Expira propostas abertas vencidas (rotina agendada). */
    public function expireStale(): int
    {
        $count = 0;
        Proposal::whereIn('status', [ProposalStatus::Created->value, ProposalStatus::AwaitingFill->value, ProposalStatus::AwaitingAcceptance->value])
            ->where('expires_at', '<=', now())
            ->whereNull('accepted_at')
            ->chunkById(200, function ($proposals) use (&$count) {
                foreach ($proposals as $proposal) {
                    $proposal->forceFill(['status' => ProposalStatus::Expired])->save();
                    $this->audit->log('proposal.expired', $proposal, null, null, 'system');
                    $count++;
                }
            });

        return $count;
    }
}
