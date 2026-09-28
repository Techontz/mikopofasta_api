<?php

namespace App\Services\Assets;

use App\Models\Asset;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Server-side SVG QR codes (bacon/bacon-qr-code). The code encodes only the internal scan URL
 * {frontend_url}/capital/assets/scan/{qr_token} — no asset data — and the scan page requires a signed-in user with
 * capital access.
 */
class AssetQrCode
{
    public function url(Asset $asset): string
    {
        return config('assets.frontend_url').'/capital/assets/scan/'.$asset->qr_token;
    }

    public function svg(Asset $asset, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd));

        return $writer->writeString($this->url($asset));
    }
}
