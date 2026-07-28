<?php

namespace App\Livewire\Admin;

use App\Services\Channels\InboxQuickReplyService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Inbox Quick Replies')]
#[Layout('components.layouts.admin')]
class AdminInboxQuickReplies extends Component
{
    /** @var list<array{label: string, body: string}> */
    public array $replies = [];

    public ?string $statusMessage = null;

    public ?string $error = null;

    public function mount(InboxQuickReplyService $quickReplies): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->replies = $quickReplies->all();
        if ($this->replies === []) {
            $this->replies = [['label' => '', 'body' => '']];
        }
    }

    public function addRow(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->replies[] = ['label' => '', 'body' => ''];
    }

    public function removeRow(int $index): void
    {
        AdminAccess::ensureStaffAdmin();
        unset($this->replies[$index]);
        $this->replies = array_values($this->replies);
        if ($this->replies === []) {
            $this->replies = [['label' => '', 'body' => '']];
        }
    }

    public function moveUp(int $index): void
    {
        AdminAccess::ensureStaffAdmin();
        if ($index <= 0 || ! isset($this->replies[$index], $this->replies[$index - 1])) {
            return;
        }

        [$this->replies[$index - 1], $this->replies[$index]] = [$this->replies[$index], $this->replies[$index - 1]];
    }

    public function moveDown(int $index): void
    {
        AdminAccess::ensureStaffAdmin();
        if (! isset($this->replies[$index], $this->replies[$index + 1])) {
            return;
        }

        [$this->replies[$index + 1], $this->replies[$index]] = [$this->replies[$index], $this->replies[$index + 1]];
    }

    public function save(InboxQuickReplyService $quickReplies): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;

        $normalized = $quickReplies->normalize($this->replies);
        if ($normalized === []) {
            $this->error = 'Add at least one quick reply with both a label and body.';

            return;
        }

        $this->replies = $quickReplies->save($normalized);
        $this->statusMessage = 'Quick replies saved.';
    }

    public function resetDefaults(InboxQuickReplyService $quickReplies): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->replies = $quickReplies->resetToDefaults();
        if ($this->replies === []) {
            $this->replies = [['label' => '', 'body' => '']];
        }
        $this->statusMessage = 'Restored default quick replies from config.';
    }

    public function render()
    {
        return view('livewire.admin.admin-inbox-quick-replies');
    }
}
