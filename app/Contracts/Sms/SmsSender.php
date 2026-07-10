<?php

namespace App\Contracts\Sms;

interface SmsSender
{
    public function send(string $phone, string $message): void;
}
