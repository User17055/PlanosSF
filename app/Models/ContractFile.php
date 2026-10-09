<?php

namespace App\Models;

use App\Enums\FileReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractFile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['review_status' => FileReviewStatus::class, 'reviewed_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected static function booted(): void
    {
        static::updating(function (self $f) {
            if ($f->isDirty(['storage_key', 'sha256', 'size', 'kind', 'version', 'contract_id'])) {
                throw new \LogicException('Arquivo de contrato é imutável; envie uma nova versão.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Arquivos de contrato não podem ser excluídos.'));
    }
}
