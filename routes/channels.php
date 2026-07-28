<?php

use App\Support\AdminAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('admin.inbox', function ($user) {
    if (! $user) {
        return false;
    }

    return AdminAccess::isStaffAdmin($user)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
