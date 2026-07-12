<?php

declare(strict_types=1);

/**
 * Fallback only — skipped entirely when ext-fileinfo is enabled.
 *
 * Laravel Storage, Livewire uploads, and league/mime-type-detection need the
 * finfo class. On hosts that disable fileinfo, this stub lets those libraries
 * construct successfully and fall back to extension-based MIME maps.
 */
if (extension_loaded('fileinfo')) {
    return;
}

// Already installed by a previous require (e.g. Composer files + index.php).
if (class_exists('finfo', false)) {
    return;
}

if (! defined('FILEINFO_NONE')) {
    define('FILEINFO_NONE', 0);
}

if (! defined('FILEINFO_MIME_TYPE')) {
    define('FILEINFO_MIME_TYPE', 16);
}

if (! defined('FILEINFO_MIME_ENCODING')) {
    define('FILEINFO_MIME_ENCODING', 1024);
}

if (! defined('FILEINFO_MIME')) {
    define('FILEINFO_MIME', 1040);
}

if (! defined('FILEINFO_RAW')) {
    define('FILEINFO_RAW', 256);
}

class finfo
{
    public function __construct(int $flags = FILEINFO_NONE, ?string $magic_database = null) {}

    public function file(string $filename, int $flags = FILEINFO_NONE, $context = null): string|false
    {
        return false;
    }

    public function buffer(string $string, int $flags = FILEINFO_NONE, $context = null): string|false
    {
        return false;
    }

    public function set_flags(int $flags): bool
    {
        return true;
    }
}

if (! function_exists('finfo_open')) {
    function finfo_open(int $flags = FILEINFO_NONE, ?string $magic_database = null): finfo|false
    {
        return new finfo($flags, $magic_database);
    }
}

if (! function_exists('finfo_close')) {
    function finfo_close(finfo $finfo): bool
    {
        return true;
    }
}

if (! function_exists('finfo_file')) {
    function finfo_file(finfo $finfo, string $filename, int $flags = FILEINFO_NONE, $context = null): string|false
    {
        return $finfo->file($filename, $flags, $context);
    }
}

if (! function_exists('finfo_buffer')) {
    function finfo_buffer(finfo $finfo, string $string, int $flags = FILEINFO_NONE, $context = null): string|false
    {
        return $finfo->buffer($string, $flags, $context);
    }
}

if (! function_exists('finfo_set_flags')) {
    function finfo_set_flags(finfo $finfo, int $flags): bool
    {
        return $finfo->set_flags($flags);
    }
}
