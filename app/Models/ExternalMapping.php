<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalMapping extends Model
{
    protected $guarded = ['id'];

    public static function externalId(string $system, Model $local, string $scope = 'default'): ?string
    {
        return static::where('system', $system)->where('account_scope', $scope)
            ->where('local_type', $local->getMorphClass())->where('local_id', $local->getKey())
            ->value('external_id');
    }

    public static function link(string $system, Model $local, string $externalId, string $scope = 'default'): self
    {
        return static::firstOrCreate(
            ['system' => $system, 'account_scope' => $scope, 'local_type' => $local->getMorphClass(), 'local_id' => $local->getKey()],
            ['external_id' => $externalId, 'created_by' => auth()->id()],
        );
    }
}
