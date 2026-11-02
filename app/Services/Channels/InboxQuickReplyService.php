<?php

namespace App\Services\Channels;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class InboxQuickReplyService
{
    public const SETTING_KEY = 'channels.inbox.quick_replies';

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    public function all(): array
    {
        $stored = $this->decodeStored();
        if ($stored !== null) {
            return $stored;
        }

        return $this->normalize(config('channels.inbox.quick_replies', []));
    }

    /**
     * @param  list<array{id?: mixed, label?: mixed, body?: mixed}|mixed>  $rows
     * @return list<array{id: string, label: string, body: string}>
     */
    public function save(array $rows): array
    {
        $normalized = $this->normalize($rows);
        Setting::putValue(self::SETTING_KEY, json_encode($normalized, JSON_UNESCAPED_UNICODE), 'channels');

        return $normalized;
    }

    public function resetToDefaults(): array
    {
        Setting::query()->where('key', self::SETTING_KEY)->delete();
        Cache::forget('setting:'.self::SETTING_KEY);

        return $this->all();
    }

    /**
     * @return array{id: string, label: string, body: string}|null
     */
    public function find(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        foreach ($this->all() as $reply) {
            if ($reply['id'] === $id) {
                return $reply;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, label: string, body: string}>
     */
    public function normalize(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($row['body'] ?? '')));
            if ($label === '' || $body === '') {
                continue;
            }

            $label = mb_substr($label, 0, 40);
            $body = mb_substr($body, 0, 2000);
            $id = trim((string) ($row['id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9_-]{4,40}$/', $id) !== 1) {
                $id = 'qr_'.substr(hash('sha256', $label."\0".$body), 0, 16);
            }
            if (isset($seen[$id])) {
                $id = 'qr_'.substr(hash('sha256', $id."\0".$label."\0".$body.chr(count($out))), 0, 16);
            }
            $seen[$id] = true;

            $out[] = [
                'id' => $id,
                'label' => $label,
                'body' => $body,
            ];
        }

        return array_values($out);
    }

    /**
     * @return list<array{id: string, label: string, body: string}>|null
     */
    private function decodeStored(): ?array
    {
        $raw = Setting::getValue(self::SETTING_KEY);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->normalize($decoded);
    }
}
