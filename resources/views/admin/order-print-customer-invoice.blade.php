<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice #{{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page {
            size: A4;
            margin: 12mm;
        }
        html, body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .screen-actions {
            text-align: center;
            margin: 16px 0 8px;
        }
        .screen-actions button {
            font: inherit;
            font-size: 14px;
            padding: 8px 16px;
            cursor: pointer;
            border: 1px solid #ccc;
            background: #f7f7f7;
            border-radius: 6px;
        }
        .invoice {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px 20px 32px;
        }
        .brand {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .doc-title {
            margin-top: 4px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #444;
        }
        .meta {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin: 20px 0 16px;
            font-size: 13px;
            line-height: 1.45;
        }
        .meta strong { font-weight: 700; }
        h2 {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #555;
            margin: 0 0 6px;
        }
        .bill-to {
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.4;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table.items th,
        table.items td {
            border-bottom: 1px solid #ddd;
            padding: 8px 6px;
            vertical-align: top;
            text-align: left;
        }
        table.items th {
            font-size: 11px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #555;
        }
        table.items .num { text-align: right; white-space: nowrap; }
        .photo {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: block;
            background: #f6f6f6;
        }
        .photo-cell { width: 64px; }
        .totals {
            width: 280px;
            margin-left: auto;
            margin-top: 12px;
            font-size: 13px;
        }
        .totals .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 4px 0;
        }
        .totals .grand {
            border-top: 2px solid #111;
            margin-top: 6px;
            padding-top: 8px;
            font-weight: 800;
            font-size: 14px;
        }
        .total-bill {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-top: 8px;
            padding-top: 12px;
            border-top: 2px solid #111;
            font-size: 18px;
            font-weight: 800;
        }
        .footer {
            margin-top: 28px;
            font-size: 12px;
            color: #444;
        }
        @media print {
            .screen-actions { display: none !important; }
            .invoice { max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <div class="invoice">
        <div class="brand">{{ \App\Support\OrderPrintSlip::BRAND }}</div>
        <div class="doc-title">Invoice</div>

        <div class="meta">
            <div>
                <div><strong>Order #{{ $order->order_number }}</strong></div>
                @if (filled($parcelId))
                    <div>Parcel ID {{ $parcelId }}</div>
                @endif
            </div>
            <div>
                @if ($order->placed_at)
                    <div>{{ $order->placed_at->timezone('Asia/Dhaka')->format('d M Y') }}</div>
                @endif
            </div>
        </div>

        <h2>Bill to</h2>
        <div class="bill-to">
            <div><strong>{{ $order->name }}</strong></div>
            @if (filled($order->phone))
                <div>{{ $order->phone }}</div>
            @endif
            @if (filled($shippingAddress))
                <div>{{ $shippingAddress }}</div>
            @endif
        </div>

        @if ($showPhotos)
            <table class="items">
                <thead>
                    <tr>
                        <th class="photo-cell"></th>
                        <th>Product</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit</th>
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($order->items as $item)
                        @php($photoUrl = $item->previewImageUrl())
                        <tr>
                            <td class="photo-cell">
                                @if ($photoUrl)
                                    <img class="photo" data-product-photo src="{{ $photoUrl }}" alt="{{ $item->displayName() }}">
                                @endif
                            </td>
                            <td>{{ $item->displayName() }}</td>
                            <td class="num">{{ (int) $item->quantity }}</td>
                            <td class="num">{{ number_format((float) $item->price, 0) }} Tk</td>
                            <td class="num">{{ number_format((float) $item->line_total, 0) }} Tk</td>
                        </tr>
                    @empty
                        <tr>
                            <td></td>
                            <td colspan="4">No items</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="totals">
                <div class="row"><span>Product price</span><span>{{ number_format($totals->subtotal, 0) }} Tk</span></div>
                @if ($totals->deliveryCharge > 0)
                    <div class="row"><span>+ Delivery</span><span>{{ number_format($totals->deliveryCharge, 0) }} Tk</span></div>
                @endif
                @foreach ($order->adjustments->where('type', 'charge') as $adj)
                    <div class="row"><span>+ {{ $adj->label }}</span><span>{{ number_format((float) $adj->amount, 0) }} Tk</span></div>
                @endforeach
                @if ($order->adjustments->isEmpty() && (float) $order->charge > 0)
                    <div class="row"><span>+ Other charges</span><span>{{ number_format((float) $order->charge, 0) }} Tk</span></div>
                @endif
                @foreach ($order->adjustments->whereIn('type', ['discount', 'coupon']) as $adj)
                    <div class="row"><span>− {{ $adj->label }}</span><span>{{ number_format((float) $adj->amount, 0) }} Tk</span></div>
                @endforeach
                @if ($order->adjustments->isEmpty() && (float) $order->discount > 0)
                    <div class="row"><span>− Discounts</span><span>{{ number_format((float) $order->discount, 0) }} Tk</span></div>
                @endif
                <div class="row"><span>Bill to customer</span><span>{{ number_format($totals->billToCustomer, 0) }} Tk</span></div>
                @php($recognizedPaid = $order->trustedPaidAmount())
                @if ($recognizedPaid > 0)
                    <div class="row"><span>Paid</span><span>{{ number_format($recognizedPaid, 0) }} Tk</span></div>
                @endif
                <div class="row grand"><span>Amount to collect</span><span>{{ number_format($order->collectableAmount(), 0) }} Tk</span></div>
            </div>
        @else
            <div class="total-bill" data-total-bill>
                <span>Total Bill</span>
                <span>{{ number_format($order->collectableAmount(), 0) }} Tk</span>
            </div>
        @endif

        <div class="footer">{{ $helpline }}</div>
    </div>
</body>
</html>
