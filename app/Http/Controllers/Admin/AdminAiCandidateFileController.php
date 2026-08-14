<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class AdminAiCandidateFileController
{
    /**
     * Same-origin stream for Gemini session candidates (kept off the Livewire snapshot).
     */
    public function __invoke(string $candidate): Response
    {
        AdminAccess::ensureStaffAdmin();

        abort_unless($this->isValidCandidateId($candidate), 404);

        $userId = Auth::id();
        abort_if($userId === null, 404);

        $metaPath = $this->directory((int) $userId).DIRECTORY_SEPARATOR.$candidate.'.json';
        $binPath = $this->directory((int) $userId).DIRECTORY_SEPARATOR.$candidate.'.bin';

        abort_unless(File::isFile($metaPath) && File::isFile($binPath), 404);

        /** @var array{mime?: string}|null $meta */
        $meta = json_decode((string) File::get($metaPath), true);
        $mime = is_array($meta) ? (string) ($meta['mime'] ?? 'image/jpeg') : 'image/jpeg';
        $binary = File::get($binPath);

        abort_if($binary === false || $binary === '', 404);

        return response($binary, 200, [
            'Content-Type' => $mime !== '' ? $mime : 'image/jpeg',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isValidCandidateId(string $candidate): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', $candidate)
            && ! str_contains($candidate, '..')
            && ! str_contains($candidate, '/')
            && ! str_contains($candidate, '\\');
    }

    private function directory(int $userId): string
    {
        return storage_path('app/private/ai-candidates/'.$userId);
    }
}
