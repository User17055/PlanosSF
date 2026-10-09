<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationStatus;
use App\Models\CancellationRequest;
use App\Models\Customer;
use App\Models\ExternalMapping;
use App\Models\IntegrationJob;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingException;
use App\Services\Billing\BillingGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Execução das intenções da outbox com tentativas e intervalo crescente. Indisponibilidade de um
 * parceiro não bloqueia os outros; erro de validação não é repetido indefinidamente.
 */
class IntegrationRunner
{
    public function __construct(protected BillingGateway $gateway, protected AuditLogger $audit) {}

    public function dispatchDue(int $limit = 50): int
    {
        $ids = IntegrationJob::where('mode', IntegrationJob::MODE_AUTOMATIC)
            ->whereIn('status', [IntegrationStatus::Pending->value, IntegrationStatus::Error->value])
            ->where('next_attempt_at', '<=', now())
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $this->run(IntegrationJob::find($id));
        }

        return $ids->count();
    }

    public function run(IntegrationJob $job): void
    {
        $claimed = DB::transaction(function () use ($job) {
            $j = IntegrationJob::whereKey($job->id)->lockForUpdate()->first();
            if (! $j || ! in_array($j->status, [IntegrationStatus::Pending, IntegrationStatus::Error], true)) {
                return false;
            }
            $j->forceFill(['status' => IntegrationStatus::Processing, 'attempts' => $j->attempts + 1])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }
        $job->refresh();

        try {
            $reference = $this->execute($job);
            $job->forceFill(['status' => IntegrationStatus::Synced, 'completed_at' => now(), 'external_reference' => $reference, 'last_error' => null, 'error_kind' => null])->save();
        } catch (BillingException $e) {
            $final = ! $e->isRetryable() || $job->attempts >= $job->max_attempts;
            $job->forceFill([
                'status' => $final ? IntegrationStatus::NeedsIntervention : IntegrationStatus::Error,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'error_kind' => $e->kind,
                'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** $job->attempts))),
            ])->save();
            if ($e->kind === BillingException::KIND_AUTH) {
                $this->audit->log('integration.auth_failure', $job, ['destino' => $job->destination], $e->getMessage(), 'system');
            }
            $this->afterFailure($job, $final);
        }
    }

    protected function execute(IntegrationJob $job): ?string
    {
        return match ($job->destination.'.'.$job->operation) {
            'vindi.cancel_subscription' => $this->cancelAtProvider($job),
            default => throw new BillingException('Operação sem conector automático: requer intervenção.', BillingException::KIND_VALIDATION),
        };
    }

    protected function cancelAtProvider(IntegrationJob $job): ?string
    {
        $sub = Subscription::findOrFail($job->subject_id);
        $external = ExternalMapping::externalId($this->gateway->name(), $sub);
        if ($external) {
            $this->gateway->cancelSubscription($external);
        }
        if ($id = $job->payload['cancellation_request_id'] ?? null) {
            CancellationRequest::whereKey($id)->update(['provider_status' => 'confirmed', 'provider_error' => null]);
        }

        return $external;
    }

    protected function afterFailure(IntegrationJob $job, bool $final): void
    {
        if ($job->operation === 'cancel_subscription' && ($id = $job->payload['cancellation_request_id'] ?? null)) {
            CancellationRequest::whereKey($id)->update([
                'provider_status' => $final ? 'failed' : 'retrying',
                'provider_error' => $job->last_error,
            ]);
        }
    }

    /** Reprocessar: tenta concluir a operação original mantendo sua identidade. */
    public function retry(IntegrationJob $job, User $user): void
    {
        if ($job->mode !== IntegrationJob::MODE_AUTOMATIC) {
            throw ValidationException::withMessages(['job' => 'Tarefa manual: conclua registrando a confirmação.']);
        }
        $job->forceFill(['status' => IntegrationStatus::Pending, 'next_attempt_at' => now(), 'max_attempts' => max($job->max_attempts, $job->attempts + 3)])->save();
        $this->audit->log('integration.reprocess_requested', $job);
        $this->run($job);
    }

    /** Integração manual auditada: o responsável confirma o que fez no sistema parceiro. */
    public function completeManual(IntegrationJob $job, User $user, string $note, ?string $externalReference): void
    {
        if ($job->status === IntegrationStatus::Synced) {
            throw ValidationException::withMessages(['job' => 'Tarefa já concluída.']);
        }
        $job->forceFill([
            'status' => IntegrationStatus::Synced,
            'completed_by' => $user->id,
            'completed_at' => now(),
            'completion_note' => $note,
            'external_reference' => $externalReference,
        ])->save();

        if ($externalReference && in_array($job->operation, ['create_or_link_account', 'link_planista'], true)) {
            $model = $job->subject_type === 'Customer' ? Customer::find($job->subject_id) : Subscription::find($job->subject_id);
            if ($model) {
                $scope = $job->franchise_id ? 'franchise-'.$job->franchise_id : 'default';
                ExternalMapping::link($job->destination, $model, $externalReference, $job->destination === 'simplesvet' ? $scope : 'default');
            }
        }
        $this->audit->log('integration.manual_completed', $job, ['referencia' => $externalReference], $note);
    }
}
