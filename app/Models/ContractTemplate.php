<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $t) {
            if ($t->getOriginal('locked_at') && $t->isDirty(['body', 'name', 'version'])) {
                throw new \LogicException('Modelo de contrato já utilizado por versão publicada: crie uma nova versão.');
            }
        });
        static::deleting(function (self $t) {
            if ($t->getOriginal('locked_at')) {
                throw new \LogicException('Modelo de contrato utilizado não pode ser excluído.');
            }
        });
    }
}
