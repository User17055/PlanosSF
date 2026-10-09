<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookInbox extends Model
{
    protected $table = 'webhook_inbox';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'provider_created_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
