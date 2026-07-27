<div>
    <livewire:admin.admin-facebook-token-gate />

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Social Post #{{ $post->id }}</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Compose → choose networks → publish with live status.</p>
        </div>

        <a href="{{ route('admin.products') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Back
        </a>
    </div>

    @if ($message)
        <div class="mb-4 rounded-xl border border-[#EFE7D6] bg-white p-4 text-sm text-[#6B6459]">
            {{ $message }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="lg:col-span-2 rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#FAF6EF] text-[#6B6459]">
                    {{ ucfirst((string) $post->image_source) }}
                </span>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#FAF6EF] text-[#6B6459]">
                    {{ ucfirst((string) $post->layout) }}
                </span>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-[#FAF6EF] text-[#6B6459]">
                    Status: {{ $post->status }}
                </span>
            </div>

            <div class="whitespace-pre-wrap text-sm text-[#1E1E1E] leading-relaxed">
                {{ $post->body }}
            </div>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="font-semibold mb-2">Media</div>
            @if ($post->layout === 'collage' && $post->collage_path)
                <img src="{{ \App\Support\StorefrontAssets::url($post->collage_path) }}" alt="" class="w-full rounded-lg border border-[#E7DFCF]">
            @elseif ($post->thumbnail_path)
                <img src="{{ \App\Support\StorefrontAssets::url($post->thumbnail_path) }}" alt="" class="w-full rounded-lg border border-[#E7DFCF]">
            @else
                <div class="text-sm text-[#8C8474]">No thumbnail.</div>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6"
        x-data="{
            busy: false,
            async runRepublish() {
                if (this.busy) return;
                this.busy = true;
                try {
                    await $wire.startRepublish();
                    if ($wire.republishPhase !== 'publishing') return;
                    const channels = Object.keys($wire.channelProgress || {});
                    for (const channel of channels) {
                        await $wire.markChannelPosting(channel);
                        await $wire.publishSelectedChannel(channel);
                    }
                } finally {
                    this.busy = false;
                }
            }
        }">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div>
                <h2 class="font-semibold">Re-publish</h2>
                <p class="mt-1 text-xs text-[#8C8474]">Choose networks, then watch each attempt succeed or fail.</p>
            </div>
            <button type="button"
                @click="runRepublish()"
                :disabled="busy || $wire.republishPhase === 'publishing'"
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] transition disabled:opacity-60">
                <span x-show="!busy && $wire.republishPhase !== 'publishing'">Re-publish</span>
                <span x-cloak x-show="busy || $wire.republishPhase === 'publishing'">Posting…</span>
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
            <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer {{ $postToFacebook ? 'border-[#C9A227] bg-[#FAF6EF]/60' : '' }}">
                <input type="checkbox" wire:model.live="postToFacebook" class="mt-1 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]"
                    @disabled($republishPhase === 'publishing')>
                <div>
                    <div class="font-medium text-sm">Facebook</div>
                    <div class="text-xs text-[#8C8474]">Page feed / photos</div>
                </div>
            </label>
            <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer {{ $postToInstagram ? 'border-[#C9A227] bg-[#FAF6EF]/60' : '' }}">
                <input type="checkbox" wire:model.live="postToInstagram" class="mt-1 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]"
                    @disabled($republishPhase === 'publishing')>
                <div>
                    <div class="font-medium text-sm">Instagram</div>
                    <div class="text-xs text-[#8C8474]">Linked IG business account</div>
                </div>
            </label>
        </div>

        @if ($channelProgress !== [])
            <div class="space-y-3 border-t border-[#E7DFCF] pt-4">
                @foreach ($channelProgress as $channel => $row)
                    @php
                        $status = $row['status'] ?? 'waiting';
                        $badge = match ($status) {
                            'posting' => 'bg-amber-50 text-amber-800 border-amber-200',
                            'success' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                            'failed' => 'bg-rose-50 text-rose-800 border-rose-200',
                            default => 'bg-[#FAF6EF] text-[#6B6459] border-[#E7DFCF]',
                        };
                        $label = match ($status) {
                            'posting' => 'Posting…',
                            'success' => 'Success',
                            'failed' => 'Failed',
                            default => 'Waiting…',
                        };
                    @endphp
                    <div class="rounded-lg border border-[#E7DFCF] p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm font-medium">{{ $row['label'] ?? ucfirst($channel) }}</div>
                            <div class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-semibold {{ $badge }}">
                                @if ($status === 'posting')
                                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500"></span>
                                @endif
                                {{ $label }}
                            </div>
                        </div>
                        @if (($row['url'] ?? null) && $status === 'success')
                            <a href="{{ $row['url'] }}" target="_blank" rel="noreferrer"
                                class="mt-2 inline-flex text-xs font-medium text-[#C9A227] hover:underline">
                                View post
                            </a>
                        @endif
                        @if (($row['error'] ?? null) && $status === 'failed')
                            <p class="mt-2 text-xs text-rose-700 break-words">{{ $row['error'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="lg:col-span-2 rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="font-semibold mb-3">Products</div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                @foreach ($post->products as $product)
                    @php $thumb = $product->primaryImagePath(); @endphp
                    <a href="{{ route('product.show', $product) }}" wire:navigate
                        class="rounded-lg border border-[#E7DFCF] p-3 hover:bg-[#FAF6EF]/50 transition">
                        <div class="h-16 w-16 rounded bg-[#FAF6EF] flex items-center justify-center overflow-hidden">
                            @if ($thumb)
                                <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="h-full w-full object-cover">
                            @else
                                <span class="text-[#C9A227] text-xs">No img</span>
                            @endif
                        </div>
                        <div class="mt-2 text-sm font-medium line-clamp-1">{{ $product->name }}</div>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <div class="font-semibold mb-3">Publishing attempts</div>

            @forelse ($post->publications as $pub)
                @php
                    $badge = match ((string) $pub->status) {
                        'success' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                        'failed' => 'bg-rose-50 text-rose-800 border-rose-200',
                        'pending' => 'bg-amber-50 text-amber-800 border-amber-200',
                        default => 'bg-white text-[#6B6459] border-[#E7DFCF]',
                    };
                @endphp
                <div class="mb-3 p-3 border border-[#E7DFCF] rounded-lg bg-[#FAF6EF]/30">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-medium capitalize">{{ $pub->channel }}</div>
                        <div class="text-xs px-2 py-1 rounded-full border font-semibold {{ $badge }}">
                            {{ $pub->status === 'success' ? 'Success' : ($pub->status === 'failed' ? 'Failed' : ucfirst((string) $pub->status)) }}
                        </div>
                    </div>
                    @if ($pub->published_at)
                        <div class="text-xs text-[#8C8474] mt-1">At {{ $pub->published_at->diffForHumans() }}</div>
                    @endif

                    @if ($pub->external_url)
                        <a href="{{ $pub->external_url }}" target="_blank" rel="noreferrer"
                            class="text-xs mt-2 inline-flex font-medium text-[#C9A227] hover:underline">
                            View on {{ ucfirst((string) $pub->channel) }}
                        </a>
                    @endif

                    @if ($pub->error)
                        <div class="text-xs text-rose-600 mt-2 break-words">{{ $pub->error }}</div>
                    @endif
                </div>
            @empty
                <div class="text-sm text-[#8C8474]">No publish attempts yet.</div>
            @endforelse
        </div>
    </div>
</div>
