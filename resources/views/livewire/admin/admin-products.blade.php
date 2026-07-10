<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h1 class="font-serif text-3xl font-semibold">Products</h1>
        <a href="{{ route('admin.products.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Product
        </a>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6 flex flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, SKU, price…"
            class="flex-1 min-w-[12rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
        <select wire:model.live="category" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All categories</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="published" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All</option>
            <option value="1">Published</option>
            <option value="0">Draft</option>
        </select>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Product</th>
                        <th class="px-4 py-3 font-medium">Category</th>
                        <th class="px-4 py-3 font-medium">Price</th>
                        <th class="px-4 py-3 font-medium">Stock</th>
                        <th class="px-4 py-3 font-medium">Published</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($products as $product)
                        <tr class="hover:bg-[#FAF6EF]/60">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @php $thumb = $product->images->first()?->path @endphp
                                    @if ($thumb)
                                        <img src="{{ \App\Support\StorefrontAssets::url($thumb) }}" alt="" class="w-10 h-10 rounded object-cover bg-[#FAF6EF]">
                                    @endif
                                    <div>
                                        <div class="font-medium line-clamp-1">{{ $product->name }}</div>
                                        <div class="text-xs text-[#8C8474]">{{ $product->sku ?: $product->slug }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ $product->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3">&#2547; {{ number_format($product->price, 0) }}</td>
                            <td class="px-4 py-3">{{ $product->stock_quantity }}</td>
                            <td class="px-4 py-3">
                                <button type="button" wire:click="togglePublished({{ $product->id }})"
                                    class="text-xs rounded-full px-2.5 py-1 {{ $product->is_published ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $product->is_published ? 'Yes' : 'No' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.products.performance', $product) }}" target="_blank"
                                    class="text-[#6B6459] hover:text-[#C9A227] hover:underline">Performance</a>
                                <a href="{{ route('admin.products.edit', $product) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                <button type="button"
                                    wire:click="delete({{ $product->id }})"
                                    wire:confirm="Delete “{{ $product->name }}”? This cannot be undone."
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-[#8C8474]">No products found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div class="px-4 py-3 border-t border-[#E7DFCF]">{{ $products->links() }}</div>
        @endif
    </div>
</div>
