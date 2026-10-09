<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\FileReviewStatus;
use App\Enums\SignatureMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    protected $guarded = ['id'];

    public const KIND_FINAL_PDF = 'final_pdf';

    public const KIND_PRINT_PDF = 'print_pdf';

    public const KIND_SIGNED_SCAN = 'signed_scan';

    protected function casts(): array
    {
        return [
            'signature_method' => SignatureMethod::class,
            'document_status' => DocumentStatus::class,
            'frozen_data' => 'array',
            'signed_at' => 'datetime',
            'validated_at' => 'datetime',
            'pdf_failed_at' => 'datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ContractFile::class)->orderByDesc('version');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AcceptanceEvidence::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Documento final que sustenta a assinatura: PDF gerado (fluxos digitais/tablet)
     * ou digitalização aprovada (papel). Outros anexos nunca satisfazem a exigência.
     */
    public function currentFinalFile(): ?ContractFile
    {
        $kind = $this->signature_method === SignatureMethod::Paper ? self::KIND_SIGNED_SCAN : self::KIND_FINAL_PDF;

        return $this->files()->where('kind', $kind)->whereNull('superseded_by_id')->first();
    }

    public function hasValidFinalDocument(): bool
    {
        if ($this->document_status !== DocumentStatus::Valid) {
            return false;
        }
        $file = $this->currentFinalFile();

        return $file !== null && $file->review_status === FileReviewStatus::Approved;
    }

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            if ($c->getOriginal('signed_at') && $c->isDirty(['body_text', 'frozen_data', 'proposal_id', 'number'])) {
                throw new \LogicException('Contrato assinado não pode ser editado; gere aditivo ou novo aceite.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Contratos não podem ser excluídos.'));
    }
}
