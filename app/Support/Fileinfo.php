<?php

namespace App\Support;

/**
 * Prefer PHP fileinfo when the host enables it; fall back to extension-based
 * checks when fileinfo is unavailable (common on locked-down shared hosting).
 */
class Fileinfo
{
    public static function available(): bool
    {
        return extension_loaded('fileinfo') && class_exists(\finfo::class, false);
    }

    /**
     * Rules for validating an already-stored Livewire temporary upload
     * (filename includes an extension).
     *
     * @return list<string>
     */
    public static function storedImageRules(int $maxKilobytes = 10240, bool $required = true): array
    {
        $max = 'max:'.$maxKilobytes;
        $presence = $required ? 'required' : 'nullable';

        if (self::available()) {
            return [$presence, 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', $max];
        }

        return [$presence, 'file', 'extensions:jpeg,jpg,png,webp,gif', $max];
    }

    /**
     * Rules for `newImages.*` style arrays (no required/nullable on the item).
     *
     * @return list<string>
     */
    public static function storedImageItemRules(int $maxKilobytes = 5120, bool $allowGif = false): array
    {
        $max = 'max:'.$maxKilobytes;
        $ext = $allowGif ? 'jpeg,jpg,png,webp,gif' : 'jpeg,jpg,png,webp';

        if (self::available()) {
            return ['image', 'mimes:'.$ext, $max];
        }

        return ['file', 'extensions:'.$ext, $max];
    }

    /**
     * Rules for Livewire's global upload endpoint (PHP tmp files have no extension).
     *
     * @return list<string>
     */
    public static function temporaryUploadRules(int $maxKilobytes = 12288): array
    {
        $max = 'max:'.$maxKilobytes;

        if (self::available()) {
            return ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', $max];
        }

        // fileinfo missing: only size/presence — mime sniffing would fail on extensionless tmp paths.
        return ['required', 'file', $max];
    }
}
