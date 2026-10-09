<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAttachment extends Model
{
    public const KINDS = [
        'id_document' => 'Documento de identificação',
        'payment_receipt' => 'Comprovante financeiro',
        'other' => 'Outro',
    ];

    protected $guarded = ['id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
