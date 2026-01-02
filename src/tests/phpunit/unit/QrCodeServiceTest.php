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
}
