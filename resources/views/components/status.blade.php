@props(['value'])
@php
    $v = $value instanceof \BackedEnum ? $value->value : (string) $value;
    $label = $value instanceof \BackedEnum && method_exists($value, 'label') ? $value->label() : $v;
    $color = match ($v) {
        'active', 'paid', 'valid', 'approved', 'confirmed', 'consumed', 'synced', 'completed', 'published', 'regular', 'executed' => 'success',
        'awaiting_regularization', 'pending', 'awaiting_payment', 'awaiting_acceptance', 'awaiting_fill', 'awaiting_review',
        'awaiting_signature', 'awaiting_document', 'pending_validation', 'reserved', 'processing', 'draft', 'created', 'cancellation_scheduled',
        'pending_verification', 'partially_paid', 'scheduled', 'requested' => 'warning',
        'suspended', 'rejected', 'error', 'delinquent', 'needs_intervention', 'disputed', 'refunded', 'failed' => 'danger',
        default => 'secondary',
    };
@endphp
<span {{ $attributes->merge(['class' => "badge text-bg-{$color}"]) }}>{{ $label }}</span>
