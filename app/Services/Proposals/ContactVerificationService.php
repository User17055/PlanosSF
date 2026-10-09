<?php

namespace App\Services\Proposals;

use App\Mail\VerificationCodeMail;
use App\Models\ContactVerification;
use App\Models\Customer;
use App\Models\Proposal;
use App\Support\Mask;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Código de uso único por e-mail. O código nunca é armazenado em texto aberto (seção 8).
 */
class ContactVerificationService
{
    public function send(string $purpose, string $email, ?Proposal $proposal = null, ?Customer $customer = null): ContactVerification
    {
        $email = strtolower(trim($email));
        $destinationHash = hash('sha256', $email);

        $recent = ContactVerification::where('destination_hash', $destinationHash)
            ->where('created_at', '>', now()->subHour())->count();
        if ($recent >= config('planos.otp.max_per_hour')) {
            throw ValidationException::withMessages(['code' => 'Muitas solicitações de código. Tente novamente mais tarde.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $verification = ContactVerification::create([
            'purpose' => $purpose,
            'proposal_id' => $proposal?->id,
            'customer_id' => $customer?->id,
            'channel' => 'email',
            'destination_masked' => Mask::email($email),
            'destination_hash' => $destinationHash,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('planos.otp.ttl_minutes')),
            'ip_address' => request()?->ip(),
        ]);

        Mail::to($email)->send(new VerificationCodeMail($code, $purpose));

        return $verification;
    }

    public function verify(ContactVerification $verification, string $code): bool
    {
        if ($verification->isVerified()) {
            return true;
        }
        if ($verification->expires_at->isPast() || $verification->attempts >= config('planos.otp.max_attempts')) {
            throw ValidationException::withMessages(['code' => 'Código expirado ou bloqueado. Solicite um novo código.']);
        }

        $verification->increment('attempts');

        if (! Hash::check(preg_replace('/\D/', '', $code), $verification->code_hash)) {
            throw ValidationException::withMessages(['code' => 'Código inválido.']);
        }

        $verification->forceFill(['verified_at' => now()])->save();

        return true;
    }

    /** Verificação válida mais recente para a proposta e o e-mail informados. */
    public function verifiedFor(Proposal $proposal, string $email): ?ContactVerification
    {
        return ContactVerification::where('proposal_id', $proposal->id)
            ->where('destination_hash', hash('sha256', strtolower(trim($email))))
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', now()->subHours(2))
            ->latest('verified_at')
            ->first();
    }
}
