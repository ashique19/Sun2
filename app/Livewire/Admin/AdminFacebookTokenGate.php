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

    public bool $showUpdateForm = false;

    /**
     * @var array{
     *     valid: bool,
     *     configured: bool,
     *     message: string,
     *     page_id: ?string,
     *     page_name: ?string,
     *     checked_at: string
     * }|null
     */
    public ?array $status = null;

    public function mount(FacebookPageTokenService $tokens): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->status = $tokens->status();
        $this->showUpdateForm = ! (bool) ($this->status['valid'] ?? false);
    }

    #[On('facebook-token-recheck')]
    public function recheck(FacebookPageTokenService $tokens): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->feedback = null;
        $this->status = $tokens->status(fresh: true);
        if (! ($this->status['valid'] ?? false)) {
            $this->showUpdateForm = true;
        }
    }

    public function toggleUpdateForm(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->showUpdateForm = ! $this->showUpdateForm;
        $this->feedback = null;
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
            $this->showUpdateForm = false;
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
