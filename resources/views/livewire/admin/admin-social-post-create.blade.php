<div>
    <livewire:admin.admin-facebook-token-gate />

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Make Facebook Post</h1>
            <p class="mt-1 text-xs text-[#8C8474]">Reorder products, pick images, preview the Facebook post, then publish.</p>
        </div>

        <a href="{{ route('admin.products') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Back to Products
        </a>
    </div>

    @if ($phase === 'compose')
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
            <div class="space-y-6">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <h2 class="font-semibold">Products</h2>
                        <p class="text-xs text-[#8C8474]">Drag to change post image order</p>
                    </div>

                    @if ($productRows->isEmpty())
                        <div class="text-sm text-[#8C8474]">No products selected. Return to Products to choose products.</div>
                    @else
                        <div
                            class="space-y-3"
                            x-data="{
                                dragId: null,
                                order: @js($selectedProducts->pluck('id')->values()->all()),
                                onDragStart(id) { this.dragId = id },
                                onDrop(targetId) {
                                    if (this.dragId === null || this.dragId === targetId) return;
                                    const from = this.order.indexOf(this.dragId);
                                    const to = this.order.indexOf(targetId);
                                    if (from < 0 || to < 0) return;
                                    const next = [...this.order];
                                    next.splice(from, 1);
                                    next.splice(to, 0, this.dragId);
                                    this.order = next;
                                    this.dragId = null;
                                    $wire.reorderProducts(next);
                                }
                            }"
                            x-effect="order = @js($selectedProducts->pluck('id')->values()->all())">
                            @foreach ($productRows as $row)
                                @php
                                    $product = $row['product'];
                                    $options = $row['options'];
                                    $selected = $row['selected'];
                                    $selectedUrl = $row['selected_url'];
                                @endphp
                                <div
                                    wire:key="social-product-{{ $product->id }}"
                                    class="rounded-lg border border-[#E7DFCF] p-3 bg-[#FAF6EF]/40"
                                    draggable="true"
                                    @dragstart="onDragStart({{ $product->id }})"
                                    @dragover.prevent
                                    @drop.prevent="onDrop({{ $product->id }})">
                                    <div class="flex gap-3">
                                        <div class="flex flex-col items-center gap-2 shrink-0">
                                            <button type="button" class="cursor-grab text-[#8C8474] text-lg leading-none px-1" title="Drag to reorder" aria-label="Drag to reorder">
                                                ⋮⋮
                                            </button>
                                            <div class="h-16 w-16 rounded bg-white border border-[#E7DFCF] overflow-hidden flex items-center justify-center">
                                                @if ($selectedUrl)
                                                    <img src="{{ $selectedUrl }}" alt="" class="h-full w-full object-cover pointer-events-none">
                                                @else
                                                    <span class="text-[#C9A227] text-xs">No img</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="text-sm font-medium line-clamp-2">{{ $product->name }}</div>
                                                <button type="button"
                                                    wire:click="removeProduct({{ $product->id }})"
                                                    class="text-xs text-[#8C8474] hover:text-rose-600 shrink-0">
                                                    Remove
                                                </button>
                                            </div>

                                            @if ($options === [])
                                                <p class="mt-2 text-xs text-rose-600">No images available for this product.</p>
                                            @else
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    @foreach ($options as $option)
                                                        <button type="button"
                                                            wire:click="selectProductImage({{ $product->id }}, {{ json_encode($option['path']) }})"
                                                            class="relative h-12 w-12 rounded border overflow-hidden {{ $selected === $option['path'] ? 'border-[#C9A227] ring-2 ring-[#C9A227]/40' : 'border-[#E7DFCF]' }}"
                                                            title="{{ $option['label'] }}">
                                                            @if ($option['url'])
                                                                <img src="{{ $option['url'] }}" alt="" class="h-full w-full object-cover">
                                                            @endif
                                                            @if ($option['kind'] === 'priced')
                                                                <span class="absolute inset-x-0 bottom-0 bg-black/60 text-[9px] text-white text-center leading-4">৳</span>
                                                            @endif
                                                        </button>
                                                    @endforeach
                                                </div>
                                                <p class="mt-1 text-[11px] text-[#8C8474]">Tap an image to use it in the Facebook post.</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @error('products')
                        <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                    <h2 class="font-semibold mb-3">Compose</h2>

                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">Custom text</label>
                        <textarea
                            wire:model.live="body"
                            rows="5"
                            class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                            placeholder="Write your Facebook post copy…"></textarea>
                        <p class="mt-1 text-xs text-[#8C8474]">
                            Album posts use this as the post message. Each product photo caption is
                            “এই প্রডাক্টের আর ছবি দেখুন এই লিংকে - ” plus its store URL.
                        </p>
                        @error('body')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <div class="font-medium text-sm mb-2">Layout</div>
                        <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer {{ $layout === 'album' ? 'border-[#C9A227] bg-[#FAF6EF]/60' : '' }}">
                            <input type="radio" name="layout" wire:model.live="layout" value="album" class="mt-1">
                            <div>
                                <div class="font-medium text-sm">Album / multi-photo</div>
                                <div class="text-xs text-[#8C8474]">Each selected product image posts as a photo with a Bangla caption and store URL.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border border-[#E7DFCF] p-3 cursor-pointer mt-3 {{ $layout === 'collage' ? 'border-[#C9A227] bg-[#FAF6EF]/60' : '' }}">
                            <input type="radio" name="layout" wire:model.live="layout" value="collage" class="mt-1">
                            <div>
                                <div class="font-medium text-sm">Collage</div>
                                <div class="text-xs text-[#8C8474]">Composes one collage image from the selected product photos.</div>
                            </div>
                        </label>
                        @error('layout')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="xl:sticky xl:top-4 self-start">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 class="font-semibold">Facebook preview</h2>
                        <span class="text-[11px] text-[#8C8474]">Approximate look before posting</span>
                    </div>

                    <div class="rounded-lg border border-[#CCD0D5] bg-white overflow-hidden shadow-sm">
                        <div class="p-3 flex items-center gap-2.5">
                            <div class="h-10 w-10 rounded-full bg-[#1877F2] text-white flex items-center justify-center text-sm font-bold shrink-0">
                                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($facebookPageName, 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-[#050505] truncate">{{ $facebookPageName }}</div>
                                <div class="text-[12px] text-[#65676B]">Just now · Public</div>
                            </div>
                        </div>

                        <div class="px-3 pb-3 text-[15px] text-[#050505] whitespace-pre-wrap leading-snug min-h-[1.5rem]">
                            @if (trim($body) !== '')
                                {{ $body }}
                            @else
                                <span class="text-[#65676B]">Your post text will appear here…</span>
                            @endif
                        </div>

                        @if ($previewImages === [])
                            <div class="border-t border-[#E4E6EB] bg-[#F0F2F5] aspect-[1.91/1] flex items-center justify-center text-sm text-[#65676B]">
                                Select products with images to preview
                            </div>
                        @elseif ($layout === 'collage')
                            <div class="border-t border-[#E4E6EB] grid gap-0.5 bg-[#E4E6EB]
                                {{ count($previewImages) === 1 ? 'grid-cols-1' : (count($previewImages) === 2 ? 'grid-cols-2' : 'grid-cols-2') }}">
                                @foreach (array_slice($previewImages, 0, 4) as $index => $image)
                                    <div class="relative {{ count($previewImages) === 1 ? 'aspect-[1.91/1]' : 'aspect-square' }} {{ count($previewImages) === 3 && $index === 0 ? 'col-span-2 aspect-[2/1]' : '' }} bg-[#F0F2F5]">
                                        <img src="{{ $image['url'] }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                                        @if ($index === 3 && count($previewImages) > 4)
                                            <div class="absolute inset-0 bg-black/50 flex items-center justify-center text-white text-2xl font-semibold">
                                                +{{ count($previewImages) - 4 }}
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <div class="px-3 py-2 text-[11px] text-[#65676B] border-t border-[#E4E6EB]">
                                Collage layout will be composed into a single image when you publish.
                            </div>
                        @else
                            <div class="border-t border-[#E4E6EB] divide-y divide-[#E4E6EB]">
                                @foreach ($previewImages as $image)
                                    <div class="bg-[#F0F2F5]">
                                        <div class="relative aspect-square sm:aspect-[4/3]">
                                            <img src="{{ $image['url'] }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                                        </div>
                                        <div class="bg-white px-3 py-2 text-[12px] text-[#050505] break-all">
                                            {{ $image['image_caption'] }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="border-t border-[#E4E6EB] px-3 py-2 flex items-center justify-around text-[13px] text-[#65676B] font-semibold">
                            <span>Like</span>
                            <span>Comment</span>
                            <span>Share</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 justify-end"
                    x-data="{
                        busy: false,
                        async runPublish() {
                            if (this.busy) return;
                            this.busy = true;
                            try {
                                await $wire.createPost();
                            } finally {
                                this.busy = false;
                            }
                        }
                    }">
                    <button type="button"
                        @click="runPublish()"
                        :disabled="busy"
                        class="rounded-full bg-[#C9A227] px-6 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] transition disabled:opacity-60">
                        <span x-show="!busy">Publish to Facebook</span>
                        <span x-cloak x-show="busy">Preparing…</span>
                    </button>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6"
            wire:key="social-publish-progress-{{ $createdPostId }}"
            x-data="{
                started: false,
                async publishWaitingChannels() {
                    if (this.started) return;
                    this.started = true;
                    if ($wire.phase !== 'publishing') return;
                    const channels = Object.keys($wire.channelProgress || {});
                    for (const channel of channels) {
                        const status = $wire.channelProgress[channel]?.status;
                        if (status !== 'waiting' && status !== 'posting') continue;
                        await $wire.markChannelPosting(channel);
                        await $wire.publishSelectedChannel(channel);
                    }
                }
            }"
            x-init="publishWaitingChannels()">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-semibold">Publishing progress</h2>
                    <p class="mt-1 text-xs text-[#8C8474]">
                        @if ($phase === 'publishing')
                            Posting to Facebook…
                        @else
                            Finished. Review the result below.
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
                            View details / re-post
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
