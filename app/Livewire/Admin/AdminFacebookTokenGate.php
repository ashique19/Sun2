<?php

namespace App\Livewire\Admin;

use App\Services\Facebook\FacebookPageTokenService;
use App\Support\AdminAccess;
use Livewire\Attributes\On;
use Livewire\Component;

class AdminFacebookTokenGate extends Component
{
    public string $tokenInput = '';

    public ?string $feedback = null;

    public bool $feedbackOk = false;

    public bool $showReplace = false;

    /**
     * @var array{
     *     valid: bool,
     *     configured: bool,
     *     message: string,
     *     page_id: ?string,
     *     page_name: ?string,
     *     checked_at: string,
     *     expires_at: ?string,
     *     never_expires: ?bool,
     *     expires_label: ?string
     * }|null
     */
    public ?array $status = null;

    public function mount(FacebookPageTokenService $tokens): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->status = $tokens->status();
    }

    #[On('facebook-token-recheck')]
    public function recheck(FacebookPageTokenService $tokens): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->feedback = null;
        $this->status = $tokens->status(fresh: true);
    }

    public function saveToken(FacebookPageTokenService $tokens): void
    {
        AdminAccess::ensureStaffAdmin();

        $result = $tokens->saveToken($this->tokenInput);
        $this->status = $result['status'];
        $this->feedback = $result['message'];
        $this->feedbackOk = $result['ok'];

        if ($result['ok']) {
            $this->tokenInput = '';
            $this->showReplace = false;
        }
    }

    public function render(FacebookPageTokenService $tokens)
    {
        return view('livewire.admin.admin-facebook-token-gate', [
            'generateTokenUrl' => $tokens->generateTokenUrl(),
            'systemUserUrl' => $tokens->businessSystemUserUrl(),
        ]);
    }
}
