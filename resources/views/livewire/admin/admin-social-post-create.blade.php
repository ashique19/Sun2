<div>
    <livewire:admin.admin-facebook-token-gate />

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Make Social Post</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Select products, write copy, choose networks, then publish.</p>
        </div>

        <a href="{{ route('admin.products') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Back to Products
        </a>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6">
        <h2 class="font-semibold mb-3">Selected products</h2>

        @if ($selectedProducts->isEmpty())
            <div class="text-sm text-[#8C8474]">No products selected. Return to Products to choose products.</div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach ($selectedProducts as $product)
                    @php
                        $thumb = $product->primaryImagePath();
                        $priced = $supportsPricedImages ? ($product->priced_image_path ?? null) : null;
                    @endphp

                    <div class="rounded-lg border border-[#E7DFCF] p-3">
                        <div class="h-16 w-16 rounded bg-[#FAF6EF] flex items-center justify-center overflow-hidden">
                            @if ($thumb)
                                <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="h-full w-full object-cover">
                            @else
                                <span class="text-[#C9A227] text-xs">No img</span>
                            @endif
                        </div>

                        <div class="mt-2">
                            <div class="text-sm font-medium line-clamp-1">{{ $product->name }}</div>
                            @if ($imageSource === 'priced' && $supportsPricedImages)
                                <div class="text-[11px] mt-1 {{ is_string($priced) && trim($priced) !== '' ? 'text-emerald-700' : 'text-rose-600' }}">
                                    {{ is_string($priced) && trim($priced) !== '' ? 'Priced ready' : 'Missing priced image' }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($phase === 'compose')
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6">
            <h2 class="font-semibold mb-3">Compose</h2>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Post text</label>
                <textarea
                    wire:model.live="body"
                    rows="4"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                    placeholder="Write your post copy…"></textarea>
                @error('body')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <div class="font-medium text-sm mb-2">Post to</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer {{ $postToFacebook ? 'border-[#C9A227] bg-[#FAF6EF]/60' : '' }}">
                        <input type="checkbox" wire:model.live="postToFacebook" class="mt-1 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                        <div>
                            <div class="font-medium text-sm">Facebook</div>
                            <div class="text-xs text-[#8C8474]">Page feed / photos via Meta Graph.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer {{ $postToInstagram ? 'border-[#C9A227] bg-[#FAF6EF]/60' : '' }}">
                        <input type="checkbox" wire:model.live="postToInstagram" class="mt-1 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]">
                        <div>
                            <div class="font-medium text-sm">Instagram</div>
                            <div class="text-xs text-[#8C8474]">Linked IG business account (single image in v1).</div>
                        </div>
                    </label>
                </div>
                @error('postToFacebook')
                    <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <div class="font-medium text-sm mb-2">Image source</div>
                    <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer">
                        <input type="radio" name="imageSource" wire:model.live="imageSource" value="thumb" class="mt-1">
                        <div>
                            <div class="font-medium text-sm">Product thumb</div>
                            <div class="text-xs text-[#8C8474]">Uses the listing/primary gallery image.</div>
                        </div>
                    </label>

                    <label
                        class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer mt-3 {{ ! $supportsPricedImages ? 'opacity-60' : '' }}">
                        <input
                            type="radio"
                            name="imageSource"
                            wire:model.live="imageSource"
                            value="priced"
                            class="mt-1"
                            {{ $supportsPricedImages ? '' : 'disabled' }}>
                        <div>
                            <div class="font-medium text-sm">Images with price</div>
                            <div class="text-xs text-[#8C8474]">
                                {{ $supportsPricedImages ? 'Uses each product’s priced image.' : 'Not configured yet (priced-image fields missing).' }}
                            </div>
                        </div>
                    </label>
                    @error('imageSource')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <div class="font-medium text-sm mb-2">Layout</div>
                    <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer">
                        <input type="radio" name="layout" wire:model.live="layout" value="album" class="mt-1">
                        <div>
                            <div class="font-medium text-sm">Album / carousel</div>
                            <div class="text-xs text-[#8C8474]">One image per product (v1 may publish first image only).</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer mt-3">
                        <input type="radio" name="layout" wire:model.live="layout" value="collage" class="mt-1">
                        <div>
                            <div class="font-medium text-sm">Collage</div>
                            <div class="text-xs text-[#8C8474]">Composes a single collage image via GD.</div>
                        </div>
                    </label>
                    @error('layout')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 justify-end"
                x-data="{
                    busy: false,
                    async runPublish() {
                        if (this.busy) return;
                        this.busy = true;
                        try {
                            await $wire.createPost();
                            if ($wire.phase !== 'publishing') return;
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
                <button type="button"
                    @click="runPublish()"
                    :disabled="busy"
                    class="rounded-full bg-[#C9A227] px-6 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] transition disabled:opacity-60">
                    <span x-show="!busy">Publish</span>
                    <span x-cloak x-show="busy">Preparing…</span>
                </button>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-semibold">Publishing progress</h2>
                    <p class="mt-1 text-xs text-[#8C8474]">
                        @if ($phase === 'publishing')
                            Posting to selected networks…
                        @else
                            Finished. Review each network result below.
                        @endif
                    </p>
                </div>
                @if ($createdPostId)
                    <a href="{{ route('admin.social-posts.show', $createdPostId) }}" wire:navigate
                        class="rounded-full border border-[#E0D6C2] px-4 py-1.5 text-xs font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                        Open post #{{ $createdPostId }}
                    </a>
                @endif
            </div>

            <div class="space-y-3">
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

            @if ($phase === 'done')
                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($createdPostId)
                        <a href="{{ route('admin.social-posts.show', $createdPostId) }}" wire:navigate
                            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                            View details
                        </a>
                    @endif
                    <a href="{{ route('admin.products') }}" wire:navigate
                        class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                        Back to Products
                    </a>
                </div>
            @endif
        </div>
    @endif
</div>
