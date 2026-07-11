<?php

namespace App\Livewire\Admin;

use App\Models\Area;
use App\Models\Category;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Rules\BangladeshMobile;
use App\Services\Admin\AdminOrderService;
use App\Services\Admin\CustomerLookupService;
use App\Services\Admin\OrderPasteParser;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductImageService;
use App\Services\Locations\LocationAliasLearner;
use App\Services\Storefront\AddressLocationGuesser;
use App\Services\Storefront\CheckoutPricing;
use App\Support\PhoneNumber;
use App\Support\StorefrontAssets;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AdminOrderForm extends Component
{
    use WithFileUploads;
    use WithPagination;

    public ?Order $order = null;

    /** Prefill create form from this order id (query ?repeat=). */
    #[Url]
    public ?int $repeat = null;

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public ?int $cityId = null;

    public ?int $areaId = null;

    public string $paymentMethod = 'cod';

    public string $adminNote = '';

    public string $courierNote = '';

    public bool $isExchange = false;

    public ?string $addressLocationHint = null;

    public bool $suppressAddressGuess = false;

    public string $deliveryCharge = '0';

    public string $charge = '0';

    public string $discount = '0';

    public bool $autoDelivery = true;

  /** @var array<int, array{product_id:int,name:string,quantity:int,price:float,purchase_price:float,line_total:float,product_image:?string,stock_quantity:int}> */
    public array $lines = [];

    public string $productSearch = '';

    public string $productCategory = '';

    public string $productStock = '';

    public ?string $message = null;

    public ?string $error = null;

    public int $previousOrderCount = 0;

    /** @var list<array{id:int,order_number:string,status:string,total:float,placed_at:?string,city:?string}> */
    public array $previousOrders = [];

    public ?array $steadfastStats = null;

    public ?string $steadfastStatsError = null;

    public bool $showOrderHistoryModal = false;

    /** @var mixed */
    public $pastedImage = null;

    /** @var list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}> */
    public array $imageMatches = [];

    public bool $showImageMatchModal = false;

    public bool $showCreateProductModal = false;

    public string $newProductName = '';

    public string $newProductPrice = '';

    public int $newProductStock = 0;

    public ?int $newProductCategoryId = null;

    public ?string $imageSearchError = null;

    public ?int $guessedCityId = null;

    public ?int $guessedAreaId = null;

    public function mount(?Order $order = null): void
    {
        if ($order?->exists) {
            $this->order = $order->load('items.product');
            $this->fillFromOrder($this->order);
        } elseif ($this->repeat) {
            $source = Order::query()->with('items.product')->find($this->repeat);

            if ($source) {
                $this->fillFromOrder($source);
                $this->message = 'Prefilled from order #'.$source->order_number.'. Saving will create a new order.';
            }
        }

        if ($this->phone !== '') {
            $this->lookupPhone();
        }
    }

    public function title(): string
    {
        if ($this->order) {
            return 'Edit Order #'.$this->order->order_number;
        }

        if ($this->repeat) {
            return 'Create Order (repeat #'.$this->repeat.')';
        }

        return 'Create Order';
    }

    private function fillFromOrder(Order $source): void
    {
        $this->name = $source->name;
        $this->phone = $source->phone;
        $this->address = $source->address;
        $this->paymentMethod = (string) ($source->payment_method ?? 'cod');
        $this->adminNote = (string) ($source->admin_note ?? '');
        $this->courierNote = (string) ($source->courier_note ?? '');
        $this->isExchange = (bool) $source->is_replacement;
        $this->deliveryCharge = (string) (int) round((float) $source->delivery_charge);
        $this->charge = (string) (int) round((float) ($source->charge ?? 0));
        $this->discount = (string) (int) round((float) $source->discount);
        $this->autoDelivery = false;

        $resolved = app(OrderPasteParser::class)->resolveLocation(
            address: $source->address,
            cityHint: $source->city,
            areaHint: $source->area,
        );
        $this->cityId = $resolved[0];
        $this->areaId = $resolved[1];
        $this->addressLocationHint = $resolved[2];
        $this->suppressAddressGuess = (bool) ($this->cityId || $this->areaId);

        $this->lines = [];

        foreach ($source->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $productId = (int) $item->product_id;
            $this->lines[$productId] = [
                'product_id' => $productId,
                'name' => $item->displayName(),
                'quantity' => (int) $item->quantity,
                'price' => (float) (int) round((float) $item->price),
                'purchase_price' => (float) (int) round((float) $item->purchase_price),
                'line_total' => (float) (int) round((float) $item->line_total),
                'product_image' => $item->product_image,
                'stock_quantity' => (int) ($item->product?->stock_quantity ?? 0),
            ];
        }
    }

    public function updatedPhone(): void
    {
        $this->lookupPhone();
    }

    public function lookupPhone(?CustomerLookupService $lookup = null, ?OrderPasteParser $pasteParser = null): void
    {
        $lookup ??= app(CustomerLookupService::class);
        $pasteParser ??= app(OrderPasteParser::class);

        $rawInput = $this->phone;
        $parsedPaste = null;

        if ($pasteParser->looksLikePasteBlock($rawInput)) {
            $parsedPaste = $pasteParser->parse($rawInput);
            $this->applyParsedPaste($parsedPaste);
        } else {
            $extracted = PhoneNumber::extractFirstBangladeshMobile($rawInput);

            if ($extracted) {
                $this->phone = $extracted;
            }
        }

        if (! PhoneNumber::isValidDisplayMobile($this->phone)) {
            return;
        }

        $result = $lookup->lookup($this->phone, $this->order?->id);

        if ($result['valid'] && $result['phone']) {
            $this->phone = $result['phone'];
        }

        $this->previousOrderCount = $result['order_count'];
        $this->previousOrders = $result['orders']
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'total' => (float) $order->total,
                'placed_at' => $order->placed_at?->format('d M Y'),
                'city' => $order->city,
            ])
            ->all();
        $this->steadfastStats = $result['steadfast'];
        $this->steadfastStatsError = $result['steadfast_error'];

        // Prefer pasted customer fields; fill gaps from last order.
        if ($result['last_order']) {
            $defaults = $lookup->formDefaultsFromOrder($result['last_order']);

            if ($this->name === '') {
                $this->name = $defaults['name'];
            }

            if ($this->address === '') {
                $this->address = $defaults['address'];
            }

            if (! $this->cityId) {
                $this->cityId = $defaults['cityId'];
            }

            if (! $this->areaId) {
                $this->areaId = $defaults['areaId'];
            }

            if (! $this->addressLocationHint && ! empty($defaults['location_hint'])) {
                $this->addressLocationHint = $defaults['location_hint'];
            }
        }

        if ($this->address !== '' && (! $this->cityId || ! $this->areaId)) {
            $this->suppressAddressGuess = false;
            $this->guessLocationFromAddress();
        } elseif ($this->cityId || $this->areaId) {
            $this->suppressAddressGuess = true;
        }

        $this->refreshDeliveryCharge();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function applyParsedPaste(array $parsed): void
    {
        if (! empty($parsed['phone'])) {
            $this->phone = (string) $parsed['phone'];
        }

        if (! empty($parsed['name'])) {
            $this->name = (string) $parsed['name'];
        }

        if (! empty($parsed['address'])) {
            $this->address = (string) $parsed['address'];
            $this->suppressAddressGuess = false;
        }

        if (! empty($parsed['cityId'])) {
            $this->cityId = (int) $parsed['cityId'];
            $this->guessedCityId = $this->cityId;
        }

        if (! empty($parsed['areaId'])) {
            $this->areaId = (int) $parsed['areaId'];
            $this->guessedAreaId = $this->areaId;
        } elseif (! empty($parsed['cityId'])) {
            $this->guessedAreaId = null;
        }

        if (! empty($parsed['location_hint'])) {
            $this->addressLocationHint = (string) $parsed['location_hint'];
            $this->suppressAddressGuess = true;
        }

        if (! empty($parsed['due_amount']) && (float) $this->discount === 0.0) {
            // Keep due amount visible via admin note hint only if empty.
            if (trim($this->adminNote) === '') {
                $this->adminNote = 'Pasted TOTAL DUE: '.number_format((float) $parsed['due_amount'], 0).' Tk';
            }
        }
    }

    public function updatedIsExchange(bool $value): void
    {
        $this->address = $this->applyExchangePrefix($this->address, $value);
        $this->courierNote = $this->applyExchangePrefix($this->courierNote, $value);
    }

    public function updatedAddress(): void
    {
        if ($this->suppressAddressGuess) {
            return;
        }

        $this->guessLocationFromAddress();
    }

    public function updatedPastedImage(): void
    {
        $this->searchByPastedImage();
    }

    public function searchByPastedImage(): void
    {
        $this->imageSearchError = null;
        $this->imageMatches = [];
        $this->showImageMatchModal = false;
        $this->showCreateProductModal = false;

        $this->validate([
            'pastedImage' => ['required', 'image', 'max:10240'],
        ]);

        try {
            $hasher = app(ProductImageHashService::class);
            $hash = $hasher->hashUploadedFile($this->pastedImage);
            $matches = $hasher->findTopMatches(
                $hash,
                ProductImageHashService::TOP_MATCHES,
                ProductImageHashService::MIN_MATCH_PERCENT,
            );

            $best = $matches[0] ?? null;

            if ($best && $best['match_percent'] >= ProductImageHashService::AUTO_MATCH_PERCENT) {
                $this->addProduct((int) $best['product_id']);
                $this->pastedImage = null;
                $this->imageMatches = [];
                $this->message = 'Added “'.$best['name'].'” ('.number_format($best['match_percent'], 1).'% match).';

                return;
            }

            $this->imageMatches = $matches;
            $this->showImageMatchModal = true;
        } catch (\Throwable $e) {
            $this->imageSearchError = $e->getMessage();
            $this->pastedImage = null;
        }
    }

    public function selectImageMatch(int $productId): void
    {
        $this->addProduct($productId);
        $this->closeImageMatchModal();
        $this->message = 'Product added from image match.';
    }

    public function openCreateProductFromImage(): void
    {
        $this->showImageMatchModal = false;
        $this->showCreateProductModal = true;
        $this->newProductName = '';
        $this->newProductPrice = '';
        $this->newProductStock = 1;
        $this->newProductCategoryId = $this->productCategory !== '' ? (int) $this->productCategory : null;
    }

    public function createProductFromPaste(ProductImageService $images): void
    {
        $this->validate([
            'pastedImage' => ['required', 'image', 'max:10240'],
            'newProductName' => ['required', 'string', 'max:190'],
            'newProductPrice' => ['required', 'numeric', 'min:0'],
            'newProductStock' => ['required', 'integer', 'min:0'],
            'newProductCategoryId' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $slugBase = Str::slug($this->newProductName) ?: 'product';
        $slug = $slugBase;
        $i = 1;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $slugBase.'-'.$i;
            $i++;
        }

        $product = Product::query()->create([
            'category_id' => $this->newProductCategoryId,
            'name' => $this->newProductName,
            'slug' => $slug,
            'sku' => strtoupper(Str::random(8)),
            'price' => (float) $this->newProductPrice,
            'purchase_price' => 0,
            'stock_quantity' => $this->newProductStock,
            'is_published' => true,
            'display_order' => 0,
        ]);

        $images->store($product, $this->pastedImage, $this->newProductName);

        $this->addProduct($product->id);
        $this->closeCreateProductModal();
        $this->message = 'Product created and added to the order.';
    }

    public function closeImageMatchModal(): void
    {
        $this->showImageMatchModal = false;
        $this->imageMatches = [];
        $this->pastedImage = null;
        $this->imageSearchError = null;
    }

    public function closeCreateProductModal(): void
    {
        $this->showCreateProductModal = false;
        $this->pastedImage = null;
        $this->newProductName = '';
        $this->newProductPrice = '';
        $this->newProductStock = 0;
        $this->newProductCategoryId = null;
        $this->imageSearchError = null;
    }

    public function openOrderHistoryModal(): void
    {
        if ($this->previousOrderCount > 0) {
            $this->showOrderHistoryModal = true;
        }
    }

    public function closeOrderHistoryModal(): void
    {
        $this->showOrderHistoryModal = false;
    }

    public function updatedCityId(): void
    {
        $this->areaId = null;
        $this->suppressAddressGuess = true;
        $this->addressLocationHint = null;
        $this->refreshDeliveryCharge();
    }

    public function updatedAreaId(): void
    {
        $this->suppressAddressGuess = true;
        $this->addressLocationHint = null;
        $this->refreshDeliveryCharge();
    }

    public function updatedAutoDelivery(bool $value): void
    {
        if ($value) {
            $this->refreshDeliveryCharge();
        }
    }

    public function updatedProductSearch(): void
    {
        $this->resetPage('productPage');
    }

    public function updatedProductCategory(): void
    {
        $this->resetPage('productPage');
    }

    public function updatedProductStock(): void
    {
        $this->resetPage('productPage');
    }

    public function updated($property): void
    {
        if (str_starts_with($property, 'lines.')) {
            $this->recalculateLineTotals();
            $this->refreshDeliveryCharge();
        }
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->findOrFail($productId);

        if (isset($this->lines[$productId])) {
            $this->lines[$productId]['quantity']++;
            $this->lines[$productId]['line_total'] = $this->lines[$productId]['quantity'] * $this->lines[$productId]['price'];
        } else {
            $this->lines[$productId] = [
                'product_id' => $productId,
                'name' => $product->name,
                'quantity' => 1,
                'price' => (float) (int) round((float) $product->price),
                'purchase_price' => (float) (int) round((float) $product->purchase_price),
                'line_total' => (float) (int) round((float) $product->price),
                'product_image' => StorefrontAssets::url($product->primaryImagePath()),
                'stock_quantity' => (int) $product->stock_quantity,
            ];
        }

        $this->refreshDeliveryCharge();
        $this->error = null;
    }

    public function updateLineQuantity(int $productId, int $quantity): void
    {
        if (! isset($this->lines[$productId])) {
            return;
        }

        $quantity = max(1, $quantity);
        $this->lines[$productId]['quantity'] = $quantity;
        $this->lines[$productId]['line_total'] = $quantity * $this->lines[$productId]['price'];
        $this->refreshDeliveryCharge();
    }

    public function removeLine(int $productId): void
    {
        unset($this->lines[$productId]);
        $this->refreshDeliveryCharge();
    }

    public function save(AdminOrderService $orders, LocationAliasLearner $aliasLearner): void
    {
        $this->error = null;
        $this->message = null;

        $validated = $this->validate($this->rules());

        $this->address = $this->applyExchangePrefix($validated['address'], $this->isExchange);
        $this->courierNote = $this->applyExchangePrefix((string) ($validated['courierNote'] ?? ''), $this->isExchange);

        $city = $this->cityId ? City::query()->find($this->cityId) : null;
        $area = $this->areaId ? Area::query()->find($this->areaId) : null;

        $learned = $aliasLearner->learnFromCorrection(
            address: $validated['address'],
            selectedCityId: $this->cityId,
            selectedAreaId: $this->areaId,
            guessedCityId: $this->guessedCityId,
            guessedAreaId: $this->guessedAreaId,
        );

        $orderData = AdminOrderService::orderAttributesFromForm([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'address' => $this->address,
            'payment_method' => $validated['paymentMethod'],
            'admin_note' => $validated['adminNote'] ?? null,
            'courier_note' => $this->courierNote !== '' ? $this->courierNote : null,
            'is_replacement' => $this->isExchange,
            'has_return' => $this->isExchange ? true : ($this->order?->has_return ?? false),
            'email' => $this->order?->email,
            'status' => $this->order?->status ?? 'new',
            'customer_note' => $this->order?->customer_note,
            'subtotal' => $this->subtotal(),
            'delivery_charge' => (float) $this->roundedMoney($this->deliveryCharge),
            'charge' => (float) $this->roundedMoney($this->charge),
            'discount' => (float) $this->roundedMoney($this->discount),
            'city' => $city?->name,
            'area' => $area?->name,
            'placed_at' => $this->order?->placed_at ?? now(),
        ]);

        $lines = array_values(array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'name' => $line['name'],
            'quantity' => (int) $line['quantity'],
            'price' => (float) $this->roundedMoney($line['price']),
            'purchase_price' => (float) $this->roundedMoney($line['purchase_price']),
            'line_total' => (float) $this->roundedMoney($line['line_total']),
            'product_image' => $line['product_image'],
        ], $this->lines));

        try {
            if ($this->order) {
                $order = $orders->update($this->order, $orderData, $lines);
                $this->message = 'Order updated.';
            } else {
                $order = $orders->create($orderData, $lines);
                $this->redirect(route('admin.orders.show', $order), navigate: true);

                return;
            }

            $this->order = $order->load('items.product');

            if ($learned['area'] !== []) {
                $this->message = trim($this->message.' Learned area aliases: '.implode(', ', $learned['area']).'.');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function delete(AdminOrderService $orders): void
    {
        if (! $this->order) {
            return;
        }

        $orders->delete($this->order);
        $this->redirect(route('admin.orders.new'), navigate: true);
    }

    public function subtotal(): float
    {
        return (float) array_reduce(
            $this->lines,
            fn (int $carry, array $line) => $carry + $this->roundedMoney($line['line_total']),
            0,
        );
    }

    public function itemCount(): int
    {
        return array_reduce($this->lines, fn (int $carry, array $line) => $carry + (int) $line['quantity'], 0);
    }

    public function total(): float
    {
        return (float) max(
            0,
            $this->roundedMoney($this->subtotal())
                + $this->roundedMoney($this->deliveryCharge)
                + $this->roundedMoney($this->charge)
                - $this->roundedMoney($this->discount),
        );
    }

    public function updatedDeliveryCharge(mixed $value): void
    {
        $this->deliveryCharge = (string) $this->roundedMoney($value);
    }

    public function updatedCharge(mixed $value): void
    {
        $this->charge = (string) $this->roundedMoney($value);
    }

    public function updatedDiscount(mixed $value): void
    {
        $this->discount = (string) $this->roundedMoney($value);
    }

    public function render()
    {
        $searchActive = strlen(trim($this->productSearch)) >= 2
            || $this->productCategory !== ''
            || $this->productStock !== '';

        $searchResults = collect();

        if ($searchActive) {
            $searchResults = Product::query()
                ->with(['category:id,name', 'images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
                ->when(trim($this->productSearch) !== '', fn ($q) => $q->searchTerm($this->productSearch))
                ->when($this->productCategory !== '', fn ($q) => $q->where('category_id', $this->productCategory))
                ->when($this->productStock === 'in', fn ($q) => $q->where('stock_quantity', '>', 0))
                ->when($this->productStock === 'out', fn ($q) => $q->where('stock_quantity', '<=', 0))
                ->orderBy('display_order')
                ->orderByDesc('id')
                ->paginate(12, ['*'], 'productPage');
        }

        return view('livewire.admin.admin-order-form', [
            'searchResults' => $searchResults,
            'searchActive' => $searchActive,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'cities' => City::query()->active()->orderBy('name')->get(['id', 'name']),
            'areas' => $this->cityId
                ? Area::query()->active()->where('city_id', $this->cityId)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->title($this->title());
    }

    private function guessLocationFromAddress(?AddressLocationGuesser $guesser = null): void
    {
        $guesser ??= app(AddressLocationGuesser::class);
        $guess = $guesser->guess($this->address);

        if (! $guess) {
            $this->addressLocationHint = null;
            $this->guessedCityId = null;
            $this->guessedAreaId = null;

            return;
        }

        $this->cityId = $guess['city_id'];
        $this->areaId = $guess['area_id'];
        $this->guessedCityId = $guess['city_id'];
        $this->guessedAreaId = $guess['area_id'];
        $this->addressLocationHint = 'Detected: '.$guess['label'];
        $this->refreshDeliveryCharge();
    }

    private function refreshDeliveryCharge(): void
    {
        if (! $this->autoDelivery) {
            return;
        }

        $itemCount = $this->itemCount();
        $subtotal = $this->subtotal();

        if ($itemCount <= 0 || $subtotal <= 0) {
            $this->deliveryCharge = '0';

            return;
        }

        $location = $this->areaId
            ? Area::query()->find($this->areaId)
            : ($this->cityId ? City::query()->find($this->cityId) : null);

        $this->deliveryCharge = (string) $this->roundedMoney(
            CheckoutPricing::deliveryCharge($location, $itemCount, $subtotal),
        );
    }

    private function recalculateLineTotals(): void
    {
        foreach ($this->lines as $productId => $line) {
            $this->lines[$productId]['line_total'] = (int) $line['quantity'] * $this->roundedMoney($line['price']);
        }
    }

    private function roundedMoney(mixed $value): int
    {
        return (int) round(max(0, (float) $value));
    }

    private function applyExchangePrefix(string $text, bool $enabled): string
    {
        $prefix = '[EXCHANGE PARCEL]';
        $without = $text;

        if (preg_match('/^\s*'.preg_quote($prefix, '/').'\s*/u', $text, $matches)) {
            $without = substr($text, strlen($matches[0]));
        }

        if (! $enabled) {
            return $without;
        }

        return $without === '' ? $prefix : $prefix.' '.$without;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new BangladeshMobile],
            'address' => ['required', 'string', 'max:500'],
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            'areaId' => ['nullable', 'integer', 'exists:areas,id'],
            'paymentMethod' => ['required', 'string', 'max:32'],
            'adminNote' => ['nullable', 'string', 'max:5000'],
            'courierNote' => ['nullable', 'string', 'max:5000'],
            'isExchange' => ['boolean'],
            'deliveryCharge' => ['required', 'integer', 'min:0'],
            'charge' => ['required', 'integer', 'min:0'],
            'discount' => ['required', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
