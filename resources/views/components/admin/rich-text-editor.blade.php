@props([
    'label',
    'property',
])

<div>
    <label class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <div
        wire:ignore
        class="overflow-hidden rounded-lg border border-[#E0D6C2] bg-white"
        x-data="adminRichTextEditor(@js($property))"
        data-rich-text-editor="{{ $property }}"
    >
        <div class="flex flex-wrap gap-1 border-b border-[#EFE7D6] bg-[#FAF6EF] px-2 py-1.5" role="toolbar" aria-label="{{ $label }} formatting">
            <button type="button" class="rounded px-2 py-1 text-xs font-semibold text-[#1E1E1E] hover:bg-white" @mousedown.prevent="command('bold')" title="Bold">B</button>
            <button type="button" class="rounded px-2 py-1 text-xs italic text-[#1E1E1E] hover:bg-white" @mousedown.prevent="command('italic')" title="Italic">I</button>
            <button type="button" class="rounded px-2 py-1 text-xs underline text-[#1E1E1E] hover:bg-white" @mousedown.prevent="command('underline')" title="Underline">U</button>
            <span class="mx-1 self-stretch border-l border-[#E0D6C2]" aria-hidden="true"></span>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#1E1E1E] hover:bg-white" @mousedown.prevent="formatBlock('h3')" title="Heading">H3</button>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#1E1E1E] hover:bg-white" @mousedown.prevent="formatBlock('h4')" title="Subheading">H4</button>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#1E1E1E] hover:bg-white" @mousedown.prevent="formatBlock('p')" title="Paragraph">P</button>
            <span class="mx-1 self-stretch border-l border-[#E0D6C2]" aria-hidden="true"></span>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#1E1E1E] hover:bg-white" @mousedown.prevent="command('insertUnorderedList')" title="Bullet list">• List</button>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#1E1E1E] hover:bg-white" @mousedown.prevent="command('insertOrderedList')" title="Numbered list">1. List</button>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#1E1E1E] hover:bg-white" @mousedown.prevent="link()" title="Link">Link</button>
            <button type="button" class="rounded px-2 py-1 text-xs text-[#6B6459] hover:bg-white" @mousedown.prevent="command('removeFormat')" title="Clear formatting">Clear</button>
        </div>
        <div
            x-ref="editor"
            class="product-description min-h-[10rem] px-4 py-3 text-sm text-[#6B6459] outline-none focus:bg-[#FFFEFB]"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            aria-label="{{ $label }}"
            @input="pushToWire()"
            @blur="pushToWire()"
        ></div>
    </div>
    @error($property)
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
