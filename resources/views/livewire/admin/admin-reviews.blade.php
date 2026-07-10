<div>
    <h1 class="font-serif text-3xl font-semibold mb-6">Product Reviews</h1>

    <div class="mb-6">
        <select wire:model.live="status" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <div class="space-y-4">
        @forelse ($reviews as $review)
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div>
                        <a href="{{ route('admin.products.edit', $review->product_id) }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">
                            {{ $review->product?->name }}
                        </a>
                        <p class="text-sm text-[#8C8474]">{{ $review->user?->name }} &middot; {{ $review->created_at?->format('d M Y') }}</p>
                    </div>
                    <div class="flex items-center gap-1 text-[#C9A227]">
                        @for ($i = 1; $i <= 5; $i++)
                            <span>{{ $i <= $review->rating ? '★' : '☆' }}</span>
                        @endfor
                    </div>
                </div>
                @if ($review->title)
                    <p class="font-medium text-sm mb-1">{{ $review->title }}</p>
                @endif
                <p class="text-sm text-[#6B6459]">{{ $review->body }}</p>
                @if ($review->status === 'pending')
                    <div class="mt-4 flex gap-2">
                        <button type="button" wire:click="approve({{ $review->id }})"
                            class="rounded-full bg-emerald-600 px-4 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                            Approve
                        </button>
                        <button type="button" wire:click="reject({{ $review->id }})"
                            class="rounded-full border border-rose-300 px-4 py-1.5 text-xs text-rose-700 hover:bg-rose-50">
                            Reject
                        </button>
                    </div>
                @else
                    <p class="mt-3 text-xs capitalize text-[#8C8474]">Status: {{ $review->status }}</p>
                @endif
            </div>
        @empty
            <p class="text-[#8C8474]">No reviews in this filter.</p>
        @endforelse
    </div>

    @if ($reviews->hasPages())
        <div class="mt-6">{{ $reviews->links() }}</div>
    @endif
</div>
