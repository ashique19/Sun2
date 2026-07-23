<?php

namespace App\Livewire\Reseller;

use App\Models\Area;
use App\Models\City;
use App\Models\Product;
use App\Rules\BangladeshMobile;
use App\Services\Admin\CustomerLookupService;
use App\Services\Reseller\ResellerOrderService;
use App\Support\PhoneNumber;
use App\Support\ResellerAccess;
use App\Support\StorefrontAssets;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.reseller')]
class ResellerOrderCreate extends Component
{
    use WithPagination;

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public ?int $cityId = null;

    public ?int $areaId = null;

    public string $productSearch = '';

    public string $deliveryCharge = '0';

    public ?string $message = null;

    public ?string $error = null;

    /**
     * @var array<int, array{product_id:int,name:string,quantity:int,price:float,base_price:float,commission_rate:float,purchase_price:float,line_total:float,product_image:?string,stock_quantity:int}>
     */
    public array $lines = [];

    public function mount(): void
    {
        ResellerAccess::ensureReseller();
    }

    public function title(): string
    {
        return 'Create order';
    }

    public function updatedPhone(): void
    {
        $this->prefillFromPhone();
    }

    public function updatedCityId(): void
    {
        $this->areaId = null;
        $this->refreshDeliveryCharge();
    }

    public function updatedAreaId(): void
    {
        if ($this->areaId) {
            $area = Area::query()->find($this->areaId);
            if ($area && (int) $this->cityId !== (int) $area->city_id) {
                $this->cityId = $area->city_id;
            }
        }

        $this->refreshDeliveryCharge();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'lines.')) {
            $this->recalculateLineTotals();
            $this->refreshDeliveryCharge();
        }
    }

    public function updatedProductSearch(): void
    {
        $this->resetPage('productPage');
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->findOrFail($productId);

        if (isset($this->lines[$productId])) {
            $this->lines[$productId]['quantity']++;
            $this->recalculateLineTotals();
        } else {
            $basePrice = (float) (int) round((float) $product->price);
            $this->lines[$productId] = [
                'product_id' => $productId,
                'name' => $product->name,
                'quantity' => 1,
                'price' => $basePrice,
                'base_price' => $basePrice,
                'commission_rate' => (float) $product->commission,
                'purchase_price' => (float) (int) round((float) $product->purchase_price),
                'line_total' => $basePrice,
                'product_image' => StorefrontAssets::url($product->primaryImagePath()),
                'stock_quantity' => (int) $product->stock_quantity,
            ];
        }

        $this->refreshDeliveryCharge();
        $this->error = null;
    }

    public function removeLine(int $productId): void
    {
        unset($this->lines[$productId]);
        $this->refreshDeliveryCharge();
    }

    public function save(ResellerOrderService $service): void
    {
        ResellerAccess::ensureReseller();

        $this->error = null;
        $this->message = null;

        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->address = trim($this->address);

        if ($this->phone !== '') {
            $extracted = PhoneNumber::extractFirstBangladeshMobile($this->phone);
            if ($extracted) {
                $this->phone = $extracted;
            }
        }

        $this->validateForm();

        $city = $this->cityId ? City::query()->find($this->cityId) : null;
        $area = $this->areaId ? Area::query()->find($this->areaId) : null;

        $subtotal = $this->subtotal();
        $deliveryCharge = (float) max(0, (int) round((float) $this->deliveryCharge));

        $orderData = ResellerOrderService::orderAttributesFromForm([
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'subtotal' => $subtotal,
            'delivery_charge' => $deliveryCharge,
            'city' => $city?->name,
            'area' => $area?->name,
            'state' => $city?->division ?? $city?->name,
        ]);

        $lines = array_values(array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'name' => $line['name'],
            'quantity' => (int) $line['quantity'],
            'price' => (float) max($line['base_price'], $this->roundedMoney($line['price'])),
            'base_price' => (float) $line['base_price'],
            'commission_rate' => (float) $line['commission_rate'],
            'purchase_price' => (float) $line['purchase_price'],
            'line_total' => (float) $this->roundedMoney($line['line_total']),
            'product_image' => $line['product_image'],
        ], $this->lines));

        try {
            $order = $service->create($orderData, $lines);
            $this->redirect(route('reseller.orders.show', $order), navigate: true);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function subtotal(): float
    {
        return (float) array_reduce(
            $this->lines,
            fn (int $carry, array $line) => $carry + $this->roundedMoney($line['line_total']),
            0,
        );
    }

    public function total(): float
    {
        return max(0.0, $this->subtotal() + (float) $this->roundedMoney($this->deliveryCharge));
    }

    public function estimatedTotalCommission(): float
    {
        $total = 0.0;
        foreach ($this->lines as $line) {
            $total += ResellerOrderService::estimatedLineCommission(
                (float) $line['price'],
                (float) $line['base_price'],
                (float) $line['commission_rate'],
                (int) $line['quantity'],
            );
        }

        return $total;
    }

    public function render()
    {
        $searchActive = strlen(trim($this->productSearch)) >= 2;

        $searchResults = collect();

        if ($searchActive) {
            $searchResults = Product::query()
                ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
                ->searchTerm($this->productSearch)
                ->where('is_published', true)
                ->orderBy('display_order')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'productPage');
        }

        return view('livewire.reseller.reseller-order-create', [
            'searchResults' => $searchResults,
            'searchActive' => $searchActive,
            'cities' => City::query()->active()->orderBy('name')->get(['id', 'name']),
            'areas' => $this->cityId
                ? Area::query()->active()->where('city_id', $this->cityId)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ])->title($this->title());
    }

    private function prefillFromPhone(?CustomerLookupService $lookup = null): void
    {
        $extracted = PhoneNumber::extractFirstBangladeshMobile($this->phone);

        if ($extracted) {
            $this->phone = $extracted;
        }

        if (! PhoneNumber::isValidDisplayMobile($this->phone)) {
            return;
        }

        $lookup ??= app(CustomerLookupService::class);
        $result = $lookup->lookup($this->phone, null);

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
        }

        $this->refreshDeliveryCharge();
    }

    private function refreshDeliveryCharge(): void
    {
        $itemCount = array_reduce($this->lines, fn (int $carry, array $line) => $carry + (int) $line['quantity'], 0);
        $subtotal = $this->subtotal();

        $this->deliveryCharge = (string) $this->roundedMoney(
            ResellerOrderService::deliveryCharge($this->areaId, $this->cityId, $itemCount, $subtotal)
        );
    }

    private function recalculateLineTotals(): void
    {
        foreach ($this->lines as $productId => $line) {
            $price = max((float) $line['base_price'], $this->roundedMoney($line['price']));
            $this->lines[$productId]['price'] = $price;
            $this->lines[$productId]['line_total'] = (int) $line['quantity'] * $price;
        }
    }

    private function roundedMoney(mixed $value): int
    {
        return (int) round(max(0, (float) $value));
    }

    private function validateForm(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new BangladeshMobile],
            'address' => ['required', 'string', 'max:500'],
            'cityId' => ['nullable', 'integer', 'exists:cities,id'],
            'areaId' => ['nullable', 'integer', 'exists:areas,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ];

        $this->validate($rules, [
            'lines.required' => 'Add at least one product to the order.',
            'lines.min' => 'Add at least one product to the order.',
        ]);

        // Enforce sell price >= base price per line.
        foreach ($this->lines as $productId => $line) {
            $sell = $this->roundedMoney($line['price']);
            $base = $this->roundedMoney($line['base_price']);
            if ($sell < $base) {
                $this->addError('lines.'.$productId.'.price', 'Sell price cannot be below catalog price ৳'.number_format($base, 0).'.');
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lines.'.$productId.'.price' => 'Sell price for "'.$line['name'].'" cannot be below catalog price ৳'.number_format($base, 0).'.',
                ]);
            }
        }
    }
}
