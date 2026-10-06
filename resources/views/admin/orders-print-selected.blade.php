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
            padding: 0 12px;
        }
        .screen-actions p {
            font-size: 13px;
            color: #444;
            margin-bottom: 10px;
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
        #print-progress {
            font-size: 13px;
            font-weight: 700;
            min-height: 1.2em;
        }
        .slip {
            width: 100%;
            min-width: 100%;
            margin: 0;
            padding: 0.5in 2vw;
            text-align: center;
            page-break-after: always;
            break-after: page;
            page-break-inside: avoid;
            break-inside: avoid;
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
            html, body {
                width: 100% !important;
                min-width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
            }
            .slip {
                width: 100% !important;
                min-width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0.5in 2vw !important;
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            /*
             * POS cutters act on the end of a print *job*, not CSS page breaks.
             * Sequential mode prints one slip per job so the printer can cut.
             */
            body.pos-cut-mode .slip { display: none !important; }
            body.pos-cut-mode .slip.is-printing { display: block !important; }
            @page { margin: 0; size: auto; }
        }
    </style>
</head>
<body data-slip-count="{{ $orders->count() }}">
    <div class="screen-actions">
        <p>
            POS printers cut at the end of each print job, not on a CSS page break.
            This page prints <strong>one invoice per job</strong> so the cutter can fire after each slip.
        </p>
        <p id="print-progress"></p>
        <button type="button" id="print-cut-each" onclick="printCutEach()">Print (cut after each)</button>
        <button type="button" id="print-next" onclick="printNextManual()">Next invoice</button>
        <button type="button" id="print-one-job" onclick="printOneJob()">Print as one job</button>
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
        (function () {
            var slips = Array.prototype.slice.call(document.querySelectorAll('.slip'));
            var progress = document.getElementById('print-progress');
            var index = 0;
            var chaining = false;
            var printOpen = false;

            function setProgress(text) {
                if (progress) {
                    progress.textContent = text;
                }
            }

            function showOnly(i) {
                document.body.classList.add('pos-cut-mode');
                slips.forEach(function (slip, j) {
                    slip.classList.toggle('is-printing', j === i);
                });
            }

            function finishChain() {
                chaining = false;
                document.body.classList.remove('pos-cut-mode');
                slips.forEach(function (slip) {
                    slip.classList.remove('is-printing');
                });
                setProgress('Done. ' + slips.length + ' invoice' + (slips.length === 1 ? '' : 's') + ' sent.');
            }

            window.printCurrentSlip = function () {
                if (index >= slips.length) {
                    finishChain();
                    return;
                }
                showOnly(index);
                setProgress('Printing ' + (index + 1) + ' of ' + slips.length + ' — confirm Print so the POS cutter can fire, then the next slip starts.');
                printOpen = true;
                window.setTimeout(function () {
                    window.print();
                }, 50);
            };

            window.printCutEach = function () {
                if (slips.length === 0) {
                    return;
                }
                chaining = true;
                index = 0;
                window.printCurrentSlip();
            };

            window.printNextManual = function () {
                chaining = true;
                if (index < slips.length) {
                    index += 1;
                }
                window.printCurrentSlip();
            };

            window.printOneJob = function () {
                chaining = false;
                document.body.classList.remove('pos-cut-mode');
                slips.forEach(function (slip) {
                    slip.classList.remove('is-printing');
                });
                setProgress('');
                window.print();
            };

            window.addEventListener('afterprint', function () {
                if (! chaining || ! printOpen) {
                    return;
                }
                printOpen = false;
                index += 1;
                window.setTimeout(window.printCurrentSlip, 400);
            });

            window.addEventListener('load', function () {
                window.setTimeout(window.printCutEach, 150);
            });
        })();
    </script>
</body>
</html>
