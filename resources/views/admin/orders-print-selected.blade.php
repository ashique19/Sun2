<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print selected orders</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page {
            size: auto;
            margin: 0;
        }
        html, body {
            width: 100%;
            min-width: 100%;
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
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
        .slip {
            width: 100%;
            min-width: 100%;
            margin: 0;
            padding: 3vw 2vw 4vw;
            text-align: center;
            page-break-after: always;
            break-after: page;
        }
        .slip:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .parcel-label {
            font-size: clamp(22px, 6vw, 48px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            line-height: 1.1;
            margin-bottom: 0.4vw;
        }
        .parcel-id {
            font-size: clamp(40px, 14vw, 112px);
            font-weight: 900;
            line-height: 1.05;
            word-break: break-word;
            overflow-wrap: anywhere;
            margin-bottom: 2.5vw;
        }
        .brand {
            font-size: clamp(28px, 9vw, 72px);
            font-weight: 900;
            letter-spacing: 0.01em;
            line-height: 1.15;
            margin-bottom: 2.5vw;
            word-break: break-word;
        }
        .customer {
            font-size: clamp(28px, 9vw, 72px);
            font-weight: 800;
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        @media print {
            .screen-actions { display: none !important; }
            html, body, .slip {
                width: 100% !important;
                min-width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
            }
            @page { margin: 0; size: auto; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    @foreach ($orders as $order)
        <div class="slip">
            @if (filled($order->printParcelId()))
                <div class="parcel-label">Parcel ID</div>
                <div class="parcel-id">{{ $order->printParcelId() }}</div>
            @endif

            <div class="brand">Sundoritoma.com</div>
            <div class="customer">{{ $order->name }}</div>
        </div>
    @endforeach

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 150);
        });
    </script>
</body>
</html>
