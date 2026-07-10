<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="font-serif text-3xl font-semibold">Categories</h1>
        <a href="{{ route('admin.categories.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Category
        </a>
    </div>

    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                <tr>
                    <th class="px-4 py-3 font-medium w-16"></th>
                    <th class="px-4 py-3 font-medium">Name</th>
                    <th class="px-4 py-3 font-medium">Slug</th>
                    <th class="px-4 py-3 font-medium">Products</th>
                    <th class="px-4 py-3 font-medium">Order</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E7DFCF]">
                @forelse ($categories as $category)
                    <tr class="hover:bg-[#FAF6EF]/60" wire:key="category-{{ $category->id }}">
                        <td class="px-4 py-3">
                            @if ($thumb = \App\Support\StorefrontAssets::url($category->thumb_image))
                                <img src="{{ $thumb }}" alt="" class="h-10 w-10 rounded object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                            @else
                                <div class="h-10 w-10 rounded border border-[#E7DFCF] bg-[#FAF6EF]"></div>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-medium">{{ $category->name }}</td>
                        <td class="px-4 py-3 text-[#8C8474]">{{ $category->slug }}</td>
                        <td class="px-4 py-3">{{ $category->products_count }}</td>
                        <td class="px-4 py-3">{{ $category->display_order }}</td>
                        <td class="px-4 py-3">
                            @if ($category->is_active)
                                <span class="text-emerald-700">Active</span>
                            @else
                                <span class="text-[#8C8474]">Hidden</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <a href="{{ route('admin.categories.edit', $category) }}" wire:navigate
                                class="text-[#C9A227] hover:underline">Edit</a>
                            @if ($category->products_count === 0)
                                <button type="button"
                                    wire:click="delete({{ $category->id }})"
                                    wire:confirm="Delete “{{ $category->name }}”? This cannot be undone."
                                    class="text-rose-600 hover:underline">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[#8C8474]">No categories yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
