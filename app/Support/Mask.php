<?php

namespace App\Support;

/**
 * Mascaramento de dados pessoais para logs técnicos e telas sem permissão de detalhe (seção 16).
 */
final class Mask
{
    public static function document(?string $digits): string
    {
        if (! $digits) {
            return '—';
        }

        return strlen($digits) === 11
            ? '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**'
            : str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    public static function email(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '—';
        }
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 1).str_repeat('*', max(2, mb_strlen($user) - 1)).'@'.$domain;
    }

    public static function phone(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return $d === '' ? '—' : str_repeat('*', max(0, strlen($d) - 4)).substr($d, -4);
    }

    /** @param array<string, mixed> $data */
    public static function payload(array $data): array
    {
        $sensitive = ['document', 'cpf', 'registry_code', 'email', 'phone', 'token', 'password', 'card', 'cvv', 'code'];
        array_walk_recursive($data, function (&$value, $key) use ($sensitive) {
            foreach ($sensitive as $needle) {
                if (is_string($key) && str_contains(strtolower($key), $needle) && is_scalar($value)) {
                    $value = '[mascarado]';
                }
            }
        });

        return $data;
    }
}
