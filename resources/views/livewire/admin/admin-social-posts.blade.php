<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Social Posts</h1>
            <p class="mt-1 text-sm text-[#8C8474]">View, edit, delete, and control which posts appear on the homepage.</p>
        </div>
        <a href="{{ route('admin.products') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Create from Products
        </a>
    </div>

    <div class="mb-4">
        <input type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by text or post ID…"
            class="w-full max-w-md rounded-xl border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
            aria-label="Search social posts">
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium w-24"></th>
                        <th class="px-4 py-3 font-medium">Post</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Homepage</th>
                        <th class="px-4 py-3 font-medium">Products</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($posts as $post)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="social-post-{{ $post->id }}">
                            <td class="px-4 py-3">
                                @if ($thumb = \App\Support\StorefrontAssets::url($post->thumbnail_path ?: $post->collage_path))
                                    <img src="{{ $thumb }}" alt="" class="h-14 w-14 rounded object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                                @else
                                    <div class="flex h-14 w-14 items-center justify-center rounded border border-[#E7DFCF] bg-[#FAF6EF] text-[#C9A227]">&#9670;</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 min-w-[14rem]">
                                <div class="font-medium line-clamp-2">{{ \Illuminate\Support\Str::limit((string) $post->body, 110) }}</div>
                                <div class="mt-1 text-[11px] text-[#8C8474]">
                                    #{{ $post->id }}
                                    · {{ ucfirst((string) $post->layout) }}
                                    · {{ $post->created_at?->diffForHumans() }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'inline-flex rounded-full px-2.5 py-1 text-xs font-medium',
                                    'bg-emerald-50 text-emerald-700' => $post->status === 'published',
                                    'bg-rose-50 text-rose-700' => $post->status === 'failed',
                                    'bg-[#FAF6EF] text-[#8C8474]' => $post->status !== 'published' && $post->status !== 'failed',
                                ])>
                                    {{ ucfirst((string) $post->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button type="button"
                                    wire:click="toggleShowOnHomepage({{ $post->id }})"
                                    class="text-xs rounded-full px-2.5 py-1 {{ $post->show_on_homepage ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $post->show_on_homepage ? 'On homepage' : 'Hidden' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-[#6B6459]">{{ $post->products_count }}</td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.social-posts.show', $post) }}" wire:navigate class="text-[#C9A227] hover:underline">View</a>
                                <a href="{{ route('admin.social-posts.edit', $post) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                <a href="{{ route('social-post.show', $post) }}" target="_blank" rel="noopener" class="text-[#6B6459] hover:underline">Site</a>
                                <button type="button"
                                    wire:click="delete({{ $post->id }})"
                                    wire:confirm="Delete this social post? This cannot be undone."
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-[#8C8474]">
                                No social posts yet. Select products and use Make Post.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $posts->links() }}
    </div>
</div>
