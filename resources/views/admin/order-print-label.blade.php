<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- Match thermal roll width so Android/Chrome print does not lay out as A4 --}}
    <meta name="viewport" content="width=80mm, initial-scale=1">
    <title>Print #{{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page {
            size: 80mm auto;
            margin: 0;
        }
        html {
            width: 80mm;
            max-width: 80mm;
            margin: 0;
            padding: 0;
        }
        body {
            width: 80mm;
            max-width: 80mm;
            min-width: 80mm;
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .sheet {
            width: 80mm;
            max-width: 80mm;
            min-width: 80mm;
            margin: 0;
            padding: 2mm 1mm 3mm;
            text-align: center;
        }
        .cn-label {
            font-size: 32px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            line-height: 1.1;
            margin-bottom: 4px;
        }
        .cn {
            font-size: 72px;
            font-weight: 900;
            letter-spacing: 0;
            margin-bottom: 14px;
            line-height: 1.05;
            word-break: break-word;
            width: 100%;
        }
        .brand {
            font-size: 48px;
            font-weight: 900;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            line-height: 1.15;
            width: 100%;
        }
        .helpline {
            font-size: 32px;
            font-weight: 700;
            color: #000;
            margin-top: 6px;
            margin-bottom: 14px;
            width: 100%;
        }
        .box,
        .due {
            width: 100% !important;
            min-width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 0 0 12px;
        }
        .box {
            border: 3px solid #000;
            text-align: left;
        }
        .box td {
            border: 3px solid #000;
            padding: 12px 8px;
            font-size: 36px;
            font-weight: 800;
            vertical-align: top;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.25;
        }
        .due td {
            border: 3px solid #000;
            padding: 14px 8px;
            font-size: 40px;
            font-weight: 900;
            text-transform: uppercase;
            line-height: 1.15;
        }
        .due td:first-child { width: 55%; }
        .due td:last-child { text-align: right; white-space: nowrap; }
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
        @media print {
            .screen-actions { display: none !important; }
            html, body, .sheet {
                width: 80mm !important;
                max-width: 80mm !important;
                min-width: 80mm !important;
                margin: 0 !important;
            }
            .box, .due { width: 100% !important; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <div class="sheet">
        @if (filled($parcelId))
            <div class="cn-label">Parcel ID</div>
            <div class="cn">{{ $parcelId }}</div>
        @endif

        <div class="brand">SUNDORITOMA</div>
        <div class="helpline">CALL: 01880001255</div>

        <table class="box">
            <tr>
                <td>{{ $order->name }}</td>
            </tr>
            <tr>
                <td>{{ $order->phone }}</td>
            </tr>
            <tr>
                <td>{{ $shippingAddress }}</td>
            </tr>
        </table>

        <table class="due">
            <tr>
                <td>TOTAL DUE</td>
                <td>{{ number_format((float) ($order->cod_amount ?: $order->total), 0) }} Tk</td>
            </tr>
        </table>
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 150);
        });
    </script>
</body>
</html>
