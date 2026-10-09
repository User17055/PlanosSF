<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Services\Access\FranchiseContext;
use App\Support\Mask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    protected ?string $correlationId = null;

    public function __construct(protected FranchiseContext $context) {}

    public function correlationId(): string
    {
        return $this->correlationId ??= (request()?->header('X-Correlation-Id') ?: (string) Str::uuid());
    }

    public function useCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function log(string $action, ?Model $subject = null, ?array $changes = null, ?string $reason = null, string $actorType = 'user'): AuditLog
    {
        $user = auth()->user();

        return AuditLog::create([
            'user_id' => $user instanceof \App\Models\User ? $user->id : null,
            'franchise_id' => $this->context->has() ? $this->context->id() : null,
            'actor_type' => $user instanceof \App\Models\User ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes ? Mask::payload($changes) : null,
            'reason' => $reason,
            'correlation_id' => $this->correlationId(),
            'ip_address' => request()?->ip(),
        ]);
    }

    /** Registra o diff de um modelo antes de salvar. */
    public function diff(Model $model): array
    {
        $diff = [];
        foreach ($model->getDirty() as $key => $new) {
            if (in_array($key, ['updated_at', 'password', 'remember_token'], true)) {
                continue;
            }
            $old = $model->getOriginal($key);
            $diff[$key] = ['de' => $old instanceof \BackedEnum ? $old->value : $old, 'para' => $new instanceof \BackedEnum ? $new->value : $new];
        }

        return $diff;
    }
}
