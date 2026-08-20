<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

class PromotionalSmsService
{
    public function __construct(private SmsSender $sms) {}

    /**
     * @param  Collection<int, User>|iterable<User>  $customers
     * @return array{sent: int, skipped: int, failed: int, errors: list<string>}
     */
    public function sendToCustomers(iterable $customers, string $message, ?string $campaignId = null): array
    {
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        /** @var list<string> $errors */
        $errors = [];

        foreach ($customers as $customer) {
            $phone = trim((string) ($customer->phone ?? ''));

            if ($phone === '') {
                $skipped++;

                continue;
            }

            try {
                $this->sms->sendPromotional($phone, $message, $campaignId);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = ($customer->name ?: 'Customer #'.$customer->id).': '.$e->getMessage();
                }
            }
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
