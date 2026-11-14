<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Support\CleanJpegWriter;
use App\Support\OrderPrintSlip;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\File;
use RuntimeException;

class InboxCustomerInvoiceImageService
{
    public const RELATIVE_DIRECTORY = 'img/inbox-invoices';

    public const CANVAS_WIDTH = 800;

    private const PAD = 40;

    public function render(Order $order): string
    {
        $order->loadMissing(['items.product.images', 'adjustments', 'courier']);

        $relative = $this->relativePath($order);
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));

        $canvas = $this->compose($order);
        CleanJpegWriter::write($canvas, $absolute, 90);
        imagedestroy($canvas);

        return '/'.$relative;
    }

    public function publicUrl(string $relativePath): string
    {
        $url = StorefrontAssets::url($relativePath);
        $absolute = public_path(ltrim($relativePath, '/'));
        $bust = is_file($absolute) ? (string) filemtime($absolute) : (string) time();

        if (! is_string($url) || $url === '') {
            $url = asset(ltrim($relativePath, '/'));
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'t='.$bust;
    }

    private function relativePath(Order $order): string
    {
        return self::RELATIVE_DIRECTORY.'/'.$order->id.'.jpg';
    }

    /**
     * @return \GdImage
     */
    private function compose(Order $order): mixed
    {
        $font = $this->fontPath();
        $lines = $this->layoutLines($order, $font);
        $height = max(520, $lines['height']);

        $canvas = imagecreatetruecolor(self::CANVAS_WIDTH, $height);
        if ($canvas === false) {
            throw new RuntimeException('Could not create invoice canvas.');
        }

        imagealphablending($canvas, true);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 17, 17, 17);
        $muted = imagecolorallocate($canvas, 85, 85, 85);
        $rule = imagecolorallocate($canvas, 221, 221, 221);
        $strong = imagecolorallocate($canvas, 17, 17, 17);
        imagefilledrectangle($canvas, 0, 0, self::CANVAS_WIDTH, $height, $white);

        foreach ($lines['draw'] as $row) {
            if ($row['type'] === 'rule') {
                imagefilledrectangle($canvas, $row['x1'], $row['y'], $row['x2'], $row['y'] + 1, $row['strong'] ? $strong : $rule);

                continue;
            }

            if ($row['type'] === 'photo') {
                $this->blitPhoto($canvas, $row['path'], $row['x'], $row['y'], 56, 56);

                continue;
            }

            imagettftext(
                $canvas,
                $row['size'],
                0,
                $row['x'],
                $row['y'],
                $row['muted'] ? $muted : $black,
                $font,
                $row['text'],
            );
        }

        return $canvas;
    }

    /**
     * @return array{height: int, draw: list<array<string, mixed>>}
     */
    private function layoutLines(Order $order, string $font): array
    {
        $draw = [];
        $y = self::PAD + 28;
        $contentRight = self::CANVAS_WIDTH - self::PAD;
        $contentWidth = $contentRight - self::PAD;

        $draw[] = $this->text(OrderPrintSlip::BRAND, self::PAD, $y, 18, false);
        $y += 26;
        $draw[] = $this->text('INVOICE', self::PAD, $y, 11, true);
        $y += 36;

        $orderLine = 'Order #'.$order->order_number;
        if ($order->isPostDispatch()) {
            $orderLine .= '  '.ucfirst((string) $order->status);
        }
        $draw[] = $this->text($orderLine, self::PAD, $y, 13, false);
        if ($order->placed_at) {
            $date = $order->placed_at->timezone('Asia/Dhaka')->format('d M Y');
            $dateWidth = $this->textWidth($font, 13, $date);
            $draw[] = $this->text($date, $contentRight - $dateWidth, $y, 13, false);
        }
        $y += 22;

        if ($order->isPostDispatch()) {
            $trackUrl = $order->customerCourierTrackingUrl();
            $trackLabel = $trackUrl ?? $order->courier_tracker ?? $order->printParcelId();
            if (filled($order->courier?->name) && filled($trackLabel)) {
                foreach ($this->wrap($font, 12, $order->courier->name.' - '.$trackLabel, $contentWidth) as $line) {
                    $draw[] = $this->text($line, self::PAD, $y, 12, false);
                    $y += 18;
                }
            }
        }

        $parcelId = $order->printParcelId();
        if (filled($parcelId)) {
            $draw[] = $this->text('Parcel ID '.$parcelId, self::PAD, $y, 12, false);
            $y += 22;
        } else {
            $y += 8;
        }

        $draw[] = $this->text('BILL TO', self::PAD, $y, 10, true);
        $y += 22;
        $draw[] = $this->text((string) ($order->name ?: '—'), self::PAD, $y, 14, false);
        $y += 22;
        if (filled($order->phone)) {
            $draw[] = $this->text((string) $order->phone, self::PAD, $y, 13, false);
            $y += 20;
        }
        $address = OrderPrintSlip::shippingAddress($order);
        if ($address !== '') {
            foreach ($this->wrap($font, 13, $address, $contentWidth) as $line) {
                $draw[] = $this->text($line, self::PAD, $y, 13, false);
                $y += 20;
            }
        }

        $y += 16;
        $colQty = 500;
        $colUnit = 630;
        $colTotal = $contentRight;
        $draw[] = $this->text('PRODUCT', 108, $y, 9, true);
        $draw[] = $this->text('QTY', $colQty - $this->textWidth($font, 9, 'QTY'), $y, 9, true);
        $draw[] = $this->text('UNIT', $colUnit - $this->textWidth($font, 9, 'UNIT'), $y, 9, true);
        $draw[] = $this->text('TOTAL', $colTotal - $this->textWidth($font, 9, 'TOTAL'), $y, 9, true);
        $y += 8;
        $draw[] = ['type' => 'rule', 'x1' => self::PAD, 'x2' => $contentRight, 'y' => $y, 'strong' => false];
        $y += 16;

        $items = $order->items;
        if ($items->isEmpty()) {
            $draw[] = $this->text('No items', 108, $y + 20, 13, true);
            $y += 48;
        } else {
            foreach ($items as $item) {
                /** @var OrderProduct $item */
                $nameLines = $this->wrap($font, 13, $item->displayName(), 320);
                $rowHeight = max(56, count($nameLines) * 18);
                $photoPath = $this->localPhotoPath($item);
                if ($photoPath) {
                    $draw[] = ['type' => 'photo', 'path' => $photoPath, 'x' => self::PAD, 'y' => $y];
                }
                $textY = $y + 18;
                foreach ($nameLines as $line) {
                    $draw[] = $this->text($line, 108, $textY, 13, false);
                    $textY += 18;
                }
                $qty = (string) max(1, (int) $item->quantity);
                $unit = number_format((float) $item->price, 0).' Tk';
                $total = number_format((float) $item->line_total, 0).' Tk';
                $baseline = $y + 22;
                $draw[] = $this->text($qty, $colQty - $this->textWidth($font, 13, $qty), $baseline, 13, false);
                $draw[] = $this->text($unit, $colUnit - $this->textWidth($font, 13, $unit), $baseline, 13, false);
                $draw[] = $this->text($total, $colTotal - $this->textWidth($font, 13, $total), $baseline, 13, false);
                $y += $rowHeight + 12;
                $draw[] = ['type' => 'rule', 'x1' => self::PAD, 'x2' => $contentRight, 'y' => $y, 'strong' => false];
                $y += 12;
            }
        }

        $totals = $order->moneyTotals();
        $totalsX = 460;
        $totalsValueX = $contentRight;
        $y += 8;
        $moneyRows = [
            ['Product price', number_format($totals->subtotal, 0).' Tk', false],
        ];
        if ($totals->deliveryCharge > 0) {
            $moneyRows[] = ['+ Delivery', number_format($totals->deliveryCharge, 0).' Tk', false];
        }
        foreach ($order->adjustments->where('type', 'charge') as $adj) {
            $moneyRows[] = ['+ '.$adj->label, number_format((float) $adj->amount, 0).' Tk', false];
        }
        if ($order->adjustments->isEmpty() && (float) $order->charge > 0) {
            $moneyRows[] = ['+ Other charges', number_format((float) $order->charge, 0).' Tk', false];
        }
        foreach ($order->adjustments->whereIn('type', ['discount', 'coupon']) as $adj) {
            $moneyRows[] = ['− '.$adj->label, number_format((float) $adj->amount, 0).' Tk', false];
        }
        if ($order->adjustments->isEmpty() && (float) $order->discount > 0) {
            $moneyRows[] = ['− Discounts', number_format((float) $order->discount, 0).' Tk', false];
        }
        $moneyRows[] = ['Bill to customer', number_format($totals->billToCustomer, 0).' Tk', false];
        $recognizedPaid = $order->trustedPaidAmount();
        if ($recognizedPaid > 0) {
            $moneyRows[] = ['Paid', number_format($recognizedPaid, 0).' Tk', false];
        }
        $moneyRows[] = ['Amount to collect', number_format($order->collectableAmount(), 0).' Tk', true];

        foreach ($moneyRows as $row) {
            [$label, $value, $grand] = $row;
            if ($grand) {
                $y += 6;
                $draw[] = ['type' => 'rule', 'x1' => $totalsX, 'x2' => $contentRight, 'y' => $y, 'strong' => true];
                $y += 22;
            }
            $draw[] = $this->text($label, $totalsX, $y, $grand ? 14 : 13, false);
            $draw[] = $this->text($value, $totalsValueX - $this->textWidth($font, $grand ? 14 : 13, $value), $y, $grand ? 14 : 13, false);
            $y += $grand ? 28 : 22;
        }

        $y += 24;
        $draw[] = $this->text(OrderPrintSlip::helpline(), self::PAD, $y, 12, true);

        return ['height' => $y + self::PAD, 'draw' => $draw];
    }

    /**
     * @return array{type: string, text: string, x: int, y: int, size: float, muted: bool}
     */
    private function text(string $text, int $x, int $y, float $size, bool $muted): array
    {
        return [
            'type' => 'text',
            'text' => $text,
            'x' => $x,
            'y' => $y,
            'size' => $size,
            'muted' => $muted,
        ];
    }

    /**
     * @return list<string>
     */
    private function wrap(string $font, float $size, string $text, int $maxWidth): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current.' '.$word;
            if ($this->textWidth($font, $size, $trial) <= $maxWidth) {
                $current = $trial;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $this->textWidth($font, $size, $word) <= $maxWidth
                ? $word
                : $this->truncate($font, $size, $word, $maxWidth);
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines !== [] ? $lines : [$text];
    }

    private function truncate(string $font, float $size, string $text, int $maxWidth): string
    {
        $ellipsis = '…';
        if ($this->textWidth($font, $size, $text) <= $maxWidth) {
            return $text;
        }

        $cut = $text;
        while ($cut !== '' && $this->textWidth($font, $size, $cut.$ellipsis) > $maxWidth) {
            $cut = mb_substr($cut, 0, -1);
        }

        return $cut.$ellipsis;
    }

    private function textWidth(string $font, float $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return 0;
        }

        return (int) abs($box[2] - $box[0]);
    }

    private function localPhotoPath(OrderProduct $item): ?string
    {
        $relative = $item->product?->primaryImagePath();
        if (! is_string($relative) || $relative === '') {
            $relative = is_string($item->product_image) ? $item->product_image : null;
        }
        if (! is_string($relative) || $relative === '') {
            return null;
        }

        $absolute = public_path(ltrim($relative, '/'));

        return is_file($absolute) && is_readable($absolute) ? $absolute : null;
    }

    /**
     * @param  \GdImage  $canvas
     */
    private function blitPhoto(mixed $canvas, string $path, int $x, int $y, int $width, int $height): void
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            default => false,
        };
        if ($source === false) {
            return;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $thumb = imagecreatetruecolor($width, $height);
        if ($thumb === false) {
            imagedestroy($source);

            return;
        }

        $bg = imagecolorallocate($thumb, 246, 246, 246);
        imagefilledrectangle($thumb, 0, 0, $width, $height, $bg);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $width, $height, $srcW, $srcH);
        imagecopy($canvas, $thumb, $x, $y, 0, 0, $width, $height);
        $border = imagecolorallocate($canvas, 221, 221, 221);
        imagerectangle($canvas, $x, $y, $x + $width - 1, $y + $height - 1, $border);
        imagedestroy($thumb);
        imagedestroy($source);
    }

    private function fontPath(): string
    {
        $latin = resource_path('fonts/DejaVuSans-Bold.ttf');
        if (is_file($latin)) {
            return $latin;
        }

        $bundled = resource_path('fonts/NotoSansBengali-Bold.ttf');
        if (is_file($bundled)) {
            return $bundled;
        }

        throw new RuntimeException('Invoice font file is missing.');
    }
}
