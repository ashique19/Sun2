<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print #{{ $order->order_number }}</title>
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
            padding: 0 12px;
        }
        .screen-actions p {
            font-size: 13px;
            color: #444;
            margin: 0 0 10px;
            line-height: 1.4;
        }
        .screen-actions button {
            font: inherit;
            font-size: 14px;
            padding: 8px 16px;
            cursor: pointer;
            border: 1px solid #ccc;
            background: #f7f7f7;
            border-radius: 6px;
            margin: 0 4px 8px;
        }
        .slip {
            width: 100%;
            min-width: 100%;
            margin: 0;
            padding: 0.5in 2vw;
            text-align: center;
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
            .slip { padding: 0.5in 2vw !important; }
            @page { margin: 0; size: auto; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        @if (! empty($otgDeepLink))
            <p>
                For the USB thermal printer, tap <strong>Print via OTG app</strong>.
                Chrome’s printer list will be empty — the OTG printer is not selectable there.
            </p>
            <a id="print-otg-app" href="{{ $otgDeepLink }}"
                style="display:inline-block;font:inherit;font-size:15px;font-weight:700;padding:10px 18px;border:1px solid #1E1E1E;background:#1E1E1E;color:#fff;border-radius:6px;margin:0 4px 8px;text-decoration:none;">
                Print via OTG app
            </a>
            <p style="font-size:12px;color:#888;">Then in the app: Connect → <strong>Print slips</strong>. Needs app 1.0.4+ for this short layout.</p>
            <button type="button" onclick="window.print()">Browser print (not USB OTG)</button>
        @else
            <button type="button" onclick="window.print()">Print</button>
        @endif
    </div>

    <div class="slip">
        @if (filled($parcelId))
            <div class="parcel-label">Parcel ID</div>
            <div class="parcel-id">{{ $parcelId }}</div>
        @endif

        <div class="brand">Sundoritoma.com</div>
        <div class="customer">{{ $order->name }}</div>
    </div>
</body>
</html>
