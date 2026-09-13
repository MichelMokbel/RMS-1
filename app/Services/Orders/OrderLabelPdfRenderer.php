<?php

namespace App\Services\Orders;

use App\Models\OrderLabelPrinterProfile;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;

class OrderLabelPdfRenderer
{
    public function render(array $snapshot, OrderLabelPrinterProfile $profile, int $copies, int $sequence): string
    {
        $width = (int) $profile->width_tenths_mm;
        $height = $this->heightTenthsMm($snapshot, $profile);
        $pdf = Pdf::loadView('prints.order-label', [
            'snapshot' => $snapshot,
            'copies' => $copies,
            'sequence' => $sequence,
            'qrDataUri' => $this->qrDataUri($snapshot),
            'pageWidthMm' => $width / 10,
            'pageHeightMm' => $height / 10,
        ]);
        $pdf->setPaper([0, 0, $this->points($width), $this->points($height)]);

        return $pdf->output();
    }

    public function qrDataUri(array $snapshot): string
    {
        $qrValue = strtoupper((string) $snapshot['source_type']).':'.(int) $snapshot['source_id'];
        $writer = new Writer(new ImageRenderer(new RendererStyle(120, 1), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($qrValue));
    }

    public function heightTenthsMm(array $snapshot, OrderLabelPrinterProfile $profile): int
    {
        if ((string) $profile->media_mode === 'fixed') {
            return (int) $profile->height_tenths_mm;
        }

        $calculated = $this->estimatedHeightTenthsMm($snapshot);

        return max(
            (int) $profile->min_height_tenths_mm,
            min((int) $profile->max_height_tenths_mm, $calculated)
        );
    }

    public function overflows(array $snapshot, OrderLabelPrinterProfile $profile): bool
    {
        return (string) $profile->media_mode === 'fixed'
            && $this->estimatedHeightTenthsMm($snapshot) > (int) $profile->height_tenths_mm;
    }

    public function estimatedHeightTenthsMm(array $snapshot): int
    {

        $itemLines = collect($snapshot['items'] ?? [])->sum(function (array $item): int {
            return max(1, (int) ceil(mb_strlen((string) ($item['description'] ?? '')) / 28));
        });
        $destinationLines = max(1, (int) ceil(mb_strlen((string) ($snapshot['destination'] ?? '')) / 32));

        return 360 + ($itemLines * 52) + ($destinationLines * 38);
    }

    private function points(int $tenthsMm): float
    {
        return ($tenthsMm / 10) * 72 / 25.4;
    }
}
