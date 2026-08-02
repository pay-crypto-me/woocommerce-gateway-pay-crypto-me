<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\QrCodeService;

class QrCodeServiceTest extends TestCase
{
    public function test_generate_qr_code_data_uri_without_logo()
    {
        $svc = new QrCodeService();
        $data = 'bitcoin:1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa?amount=0.001';
        $uri = $svc->generate_qr_code_data_uri($data, null);

        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertGreaterThan(100, strlen($uri));
    }

    public function test_generate_qr_code_data_uri_with_logo()
    {
        $svc = new QrCodeService();
        $tmp = sys_get_temp_dir() . '/php_qr_test_logo.png';

        // Create a small PNG using GD to ensure Endroid can parse it
        $img = imagecreatetruecolor(16, 16);
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, 16, 16, $bg);
        imagepng($img, $tmp);
        imagedestroy($img);

        $this->assertFileExists($tmp);

        $uri = $svc->generate_qr_code_data_uri('hello world', $tmp);
        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        @unlink($tmp);
    }

    public function test_generate_qr_code_data_uri_with_bordered_logo()
    {
        $svc = new QrCodeService();
        $tmp = sys_get_temp_dir() . '/php_qr_test_bordered_logo.png';

        // Round-ish icon with transparent corners so the ring shows around it.
        $img = imagecreatetruecolor(32, 32);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        $fill = imagecolorallocate($img, 247, 147, 26);
        imagefilledellipse($img, 16, 16, 32, 32, $fill);
        imagepng($img, $tmp);
        imagedestroy($img);

        $this->assertFileExists($tmp);

        $uri = $svc->generate_qr_code_data_uri('hello world', $tmp, [
            'border' => [
                'shape'      => 'circle',
                'width'      => 4,
                'color'      => '#FFFFFF',
                'background' => '#FFFFFF',
                'size'       => 48,
            ],
        ]);

        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        @unlink($tmp);
    }

    public function test_generate_native_degrades_to_empty_string_instead_of_fatal_on_failure()
    {
        // Regression test for C6: the guard used to be inverted — a GD failure in
        // generate_with_bordered_logo() fell through to generate_native(), which had no
        // try/catch of its own, so any of its dependencies failing (GD/fileinfo/iconv, or
        // here, an unreadable logo path) fatal'd the order-details page instead of degrading.
        $svc = new QrCodeService();

        $uri = $svc->generate_qr_code_data_uri('hello world', '/nonexistent/path/to/logo.png');

        $this->assertSame('', $uri);
    }

    public function test_logo_footprint_is_clamped_to_stay_scannable()
    {
        $svc = new QrCodeService();
        $tmp = sys_get_temp_dir() . '/php_qr_test_oversized_logo.png';

        $img = imagecreatetruecolor(32, 32);
        imagefilledrectangle($img, 0, 0, 32, 32, imagecolorallocate($img, 0, 0, 0));
        imagepng($img, $tmp);
        imagedestroy($img);

        // Request an absurd logo size; it must be clamped, not returned as-is.
        $uri = $svc->generate_qr_code_data_uri('hello world', $tmp, [
            'border' => ['size' => 400, 'width' => 4],
        ]);

        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        // 25% of the 225px QR = 56px cap; the stamped badge must never exceed it.
        $png = base64_decode(substr($uri, strlen('data:image/png;base64,')));
        $decoded = imagecreatefromstring($png);
        $this->assertSame(225, imagesx($decoded));
        $this->assertSame(225, imagesy($decoded));
        imagedestroy($decoded);

        @unlink($tmp);
    }
}
