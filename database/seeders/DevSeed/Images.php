<?php

namespace Database\Seeders\DevSeed;

use Illuminate\Http\UploadedFile;

/**
 * Generated placeholder images (GD) for uploads the API requires: clearly labelled, never a real photo or face.
 */
final class Images
{
    public static function faceCapture(): UploadedFile
    {
        return self::png(640, 480, ['DEVSEED PLACEHOLDER', 'NOT A REAL FACE CAPTURE'], 'devseed-face-capture.png');
    }

    public static function passport(string $initials): UploadedFile
    {
        return self::png(240, 300, [$initials, 'DEVSEED'], 'devseed-passport.png');
    }

    /**
     * A one-page PDF standing in for the customer's signed loan agreement (the credit officer needs one uploaded).
     */
    public static function signedAgreement(): UploadedFile
    {
        $text = 'DEVSEED PLACEHOLDER - NOT A REAL SIGNED AGREEMENT';
        $stream = "BT /F1 14 Tf 60 780 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'devseed').'.pdf';
        file_put_contents($path, $pdf);

        return new UploadedFile($path, 'devseed-signed-agreement.pdf', 'application/pdf', null, true);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function png(int $width, int $height, array $lines, string $name): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 72, 84, 96));
        $white = imagecolorallocate($image, 240, 240, 240);

        foreach ($lines as $index => $line) {
            $x = max(4, (int) (($width - imagefontwidth(5) * strlen($line)) / 2));
            imagestring($image, 5, $x, (int) ($height / 2) - 20 + $index * 24, $line, $white);
        }

        $path = tempnam(sys_get_temp_dir(), 'devseed').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
