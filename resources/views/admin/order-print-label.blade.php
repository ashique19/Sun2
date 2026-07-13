<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print #{{ $order->order_number }}</title>
    <style>
        /*
         * Android USB print often lays out on A4, then scales the whole page
         * down to the thermal roll. Fixed 80mm content becomes ~1/3 of the roll.
         * Use 100% page width so scaling fills the paper.
         */
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
        .sheet {
            width: 100%;
            min-width: 100%;
            margin: 0;
            padding: 2vw 1vw 3vw;
            text-align: center;
        }
        .cn-label {
            font-size: clamp(28px, 8vw, 64px);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            line-height: 1.1;
            margin-bottom: 0.3vw;
            width: 100%;
        }
        .cn {
            font-size: clamp(48px, 18vw, 140px);
            font-weight: 900;
            letter-spacing: 0;
            margin-bottom: 2vw;
            line-height: 1.05;
            word-break: break-word;
            overflow-wrap: anywhere;
            width: 100%;
        }
        .brand {
            font-size: clamp(36px, 12vw, 96px);
            font-weight: 900;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            line-height: 1.15;
            width: 100%;
        }
        .helpline {
            font-size: clamp(28px, 8vw, 64px);
            font-weight: 700;
            color: #000;
            margin-top: 1vw;
            margin-bottom: 2vw;
            width: 100%;
        }
        .box,
        .due {
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 0 0 2vw;
        }
        .box {
            border: 0.5vw solid #000;
            text-align: left;
        }
        .box td {
            border: 0.5vw solid #000;
            padding: 2vw 1.5vw;
            font-size: clamp(28px, 9vw, 72px);
            font-weight: 800;
            vertical-align: top;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.25;
        }
        .due td {
            border: 0.5vw solid #000;
            padding: 2.2vw 1.5vw;
            font-size: clamp(28px, 10vw, 80px);
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
                width: 100% !important;
                min-width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
            }
            .box, .due { width: 100% !important; min-width: 100% !important; }
            @page { margin: 0; size: auto; }
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

        <table class="box" width="100%">
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

        <table class="due" width="100%">
            <tr>
                <td>TOTAL DUE</td>
                <td>{{ number_format($order->collectableAmount(), 0) }} Tk</td>
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
