<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print #{{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .sheet {
            width: 80mm;
            max-width: 100%;
            margin: 12px auto;
            padding: 8px 10px 12px;
            text-align: center;
        }
        .cn {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-bottom: 10px;
            line-height: 1.2;
        }
        .brand {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            line-height: 1.2;
        }
        .helpline {
            font-size: 12px;
            color: #888;
            margin-top: 2px;
            margin-bottom: 12px;
        }
        .box {
            border: 1px solid #cfcfcf;
            text-align: left;
            width: 100%;
            margin: 0 auto 10px;
            border-collapse: collapse;
        }
        .box td {
            border: 1px solid #cfcfcf;
            padding: 8px 10px;
            font-size: 14px;
            font-weight: 700;
            vertical-align: top;
            word-break: break-word;
        }
        .due {
            width: 100%;
            border-collapse: collapse;
            margin: 0 auto;
        }
        .due td {
            border: 1px solid #cfcfcf;
            padding: 10px 12px;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
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
            .sheet { margin: 0; width: 100%; max-width: none; }
            @page { margin: 6mm; size: auto; }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <div class="sheet">
        @if (filled($order->courier_tracker))
            <div class="cn">CN# {{ $order->courier_tracker }}</div>
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
