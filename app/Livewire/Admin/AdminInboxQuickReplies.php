<?php

namespace App\Livewire\Admin;

use App\Models\InboxQuickReplyExample;
use App\Services\Channels\InboxQuickReplyMatcher;
use App\Services\Channels\InboxQuickReplyService;
use App\Support\AdminAccess;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Inbox Quick Replies')]
#[Layout('components.layouts.admin')]
class AdminInboxQuickReplies extends Component
{
    /** @var list<array{id?: string, label: string, body: string}> */
    public array $replies = [];

    /** @var array<string, string> */
    public array $newTags = [];

    /** @var array<string, string> */
    public array $tagEdits = [];

    public ?string $statusMessage = null;

    public ?string $error = null;

    public function mount(InboxQuickReplyService $quickReplies): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->replies = $quickReplies->all();
        if ($this->replies === []) {
            $this->replies = [['id' => '', 'label' => '', 'body' => '']];
        }
        $this->syncTagState();
    }

    public function addRow(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->replies[] = ['id' => '', 'label' => '', 'body' => ''];
    }

    public function removeRow(int $index): void
    {
        AdminAccess::ensureStaffAdmin();
        unset($this->replies[$index]);
        $this->replies = array_values($this->replies);
        if ($this->replies === []) {
            $this->replies = [['id' => '', 'label' => '', 'body' => '']];
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

    public function save(InboxQuickReplyService $quickReplies, InboxQuickReplyMatcher $matcher): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;

        $normalized = $quickReplies->normalize($this->replies);
        if ($normalized === []) {
            $this->error = 'Add at least one quick reply with both a label and body.';

            return;
        }

        $this->replies = $quickReplies->save($normalized);
        $matcher->pruneExamples(array_column($this->replies, 'id'));
        $this->syncTagState();
        $this->statusMessage = 'Quick replies saved.';
    }

    public function resetDefaults(InboxQuickReplyService $quickReplies, InboxQuickReplyMatcher $matcher): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->replies = $quickReplies->resetToDefaults();
        if ($this->replies === []) {
            $this->replies = [['id' => '', 'label' => '', 'body' => '']];
        }
        $matcher->pruneExamples(array_column($this->replies, 'id'));
        $this->syncTagState();
        $this->statusMessage = 'Restored default quick replies from config.';
    }

    public function addTag(string $quickReplyId, InboxQuickReplyService $quickReplies, InboxQuickReplyMatcher $matcher): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->statusMessage = null;

        if ($quickReplies->find($quickReplyId) === null) {
            $this->error = 'Save the quick reply before adding tags.';

            return;
        }

        $text = trim((string) ($this->newTags[$quickReplyId] ?? ''));
        $example = $matcher->addExample($quickReplyId, $text, auth()->id());
        if ($example === null) {
            $this->error = 'Enter the customer wording to tag against this reply.';

            return;
        }

        $this->newTags[$quickReplyId] = '';
        $this->syncTagState();
        $this->statusMessage = 'Reply tag saved.';
    }

    public function updateTag(int $exampleId, InboxQuickReplyService $quickReplies, InboxQuickReplyMatcher $matcher): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->statusMessage = null;

        $example = InboxQuickReplyExample::query()->find($exampleId);
        if (! $example || $quickReplies->find($example->quick_reply_id) === null) {
            $this->error = 'That reply tag was not found.';

            return;
        }

        $text = trim((string) ($this->tagEdits[(string) $exampleId] ?? ''));
        if (! $matcher->updateExample($example, $text)) {
            $this->error = 'Enter unique customer wording for this tag.';

            return;
        }

        $this->syncTagState();
        $this->statusMessage = 'Reply tag updated.';
    }

    public function deleteTag(int $exampleId, InboxQuickReplyMatcher $matcher): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->statusMessage = null;

        $example = InboxQuickReplyExample::query()->find($exampleId);
        if (! $example) {
            return;
        }

        $matcher->deleteExample($example);
        $this->syncTagState();
        $this->statusMessage = 'Reply tag removed.';
    }

    public function render()
    {
        return view('livewire.admin.admin-inbox-quick-replies', [
            'examplesByReply' => $this->examplesByReply(),
        ]);
    }

    private function syncTagState(): void
    {
        $examples = $this->examplesByReply()->flatten();
        $edits = [];
        foreach ($examples as $example) {
            if (! $example instanceof InboxQuickReplyExample) {
                continue;
            }
            $edits[(string) $example->id] = (string) $example->example_text;
        }
        $this->tagEdits = $edits;

        $newTags = [];
        foreach ($this->replies as $reply) {
            $id = trim((string) ($reply['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $newTags[$id] = (string) ($this->newTags[$id] ?? '');
        }
        $this->newTags = $newTags;
    }

    /**
     * @return Collection<string, Collection<int, InboxQuickReplyExample>>
     */
    private function examplesByReply(): Collection
    {
        $ids = array_values(array_filter(
            array_map(fn (array $reply): string => trim((string) ($reply['id'] ?? '')), $this->replies),
            fn (string $id): bool => $id !== '',
        ));

        if ($ids === []) {
            return collect();
        }

        return InboxQuickReplyExample::query()
            ->whereIn('quick_reply_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('quick_reply_id');
    }
}
