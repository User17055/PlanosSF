<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Armazenamento privado de documentos com nome interno gerado pelo servidor.
 */
class DocumentStorage
{
    public function disk(): string
    {
        return config('planos.documents_disk');
    }

    /** @return array{disk: string, storage_key: string, sha256: string, size: int, mime: string} */
    public function putContents(string $contents, string $directory, string $extension, string $mime): array
    {
        $key = trim($directory, '/').'/'.Str::uuid().'.'.$extension;
        Storage::disk($this->disk())->put($key, $contents);

        if (! Storage::disk($this->disk())->exists($key)) {
            throw new \RuntimeException('Falha ao confirmar o armazenamento do documento.');
        }

        return [
            'disk' => $this->disk(),
            'storage_key' => $key,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
            'mime' => $mime,
        ];
    }

    /** @return array{disk: string, storage_key: string, sha256: string, size: int, mime: string} */
    public function putUpload(UploadedFile $file, string $directory): array
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, config('planos.uploads.contract_mimes'), true)) {
            throw ValidationException::withMessages(['file' => 'Tipo de arquivo não permitido. Envie PDF, JPG ou PNG.']);
        }
        $contents = file_get_contents($file->getRealPath());
        $this->assertNotExecutable($contents);
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            default => 'jpg',
        };

        return $this->putContents($contents, $directory, $ext, $mime);
    }

    public function get(string $disk, string $key): string
    {
        return Storage::disk($disk)->get($key);
    }

    /** Verificação básica contra conteúdo ativo em anexos (seção 16). */
    protected function assertNotExecutable(string $contents): void
    {
        $head = substr($contents, 0, 4096);
        foreach (['<?php', '<script', 'MZ', '#!/'] as $needle) {
            if ($needle === 'MZ' ? str_starts_with($head, 'MZ') : stripos($head, $needle) !== false) {
                throw ValidationException::withMessages(['file' => 'Arquivo recusado pela verificação de segurança.']);
            }
        }
        if (str_starts_with($head, '%PDF') && preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile)\b/', $contents)) {
            throw ValidationException::withMessages(['file' => 'PDF com conteúdo ativo não é aceito.']);
        }
    }
}
