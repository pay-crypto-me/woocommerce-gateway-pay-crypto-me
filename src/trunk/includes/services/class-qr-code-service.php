<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       QrCodeService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\Writer\PngWriter;

\defined('ABSPATH') || exit;

class QrCodeService
{
    private const QR_SIZE = 225;
    private const DEFAULT_LOGO_SIZE = 48;

    // Linear cap for the logo footprint relative to the QR size. Endroid uses High
    // error correction whenever a logo is present (~30% of modules recoverable), so
    // keeping the obscured square at <=25% of the QR width leaves a safe margin.
    private const MAX_LOGO_FRACTION = 0.25;

    private const DEFAULT_BORDER_WIDTH = 4;
    private const DEFAULT_BORDER_COLOR = '#FFFFFF';
    private const DEFAULT_BACKGROUND_COLOR = '#FFFFFF';

    // Supersampling factor used while compositing the badge, downsampled afterwards
    // so circular edges come out anti-aliased.
    private const SUPERSAMPLE = 4;

    /**
     * @param array $options Optional. Accepts a 'border' sub-array to render a bordered
     *                       badge behind the logo instead of Endroid's plain paste:
     *                       [
     *                         'border' => [
     *                           'width'      => int,          // ring thickness in px (default 4)
     *                           'color'      => string|int[], // ring color, hex or [r,g,b]
     *                           'background' => string|int[]|null, // plate/halo behind the icon
     *                           'shape'      => 'circle'|'square', // default 'circle'
     *                           'size'       => int,          // outer footprint in px (clamped)
     *                         ],
     *                       ]
     *                       Without a 'border' entry the native Endroid path is used and no
     *                       other rendering is affected.
     */
    public function generate_qr_code_data_uri(string $data, ?string $logo_src = null, array $options = [], ?callable $logger = null): string
    {
        if ($logo_src !== null && !empty($options['border']) && is_array($options['border'])) {
            $bordered = $this->generate_with_bordered_logo($data, $logo_src, $options['border'], $logger);

            if ($bordered !== null) {
                return $bordered;
            }
            // Any GD failure falls through to the native path so the QR always renders.
        }

        return $this->generate_native($data, $logo_src, $logger);
    }

    /**
     * Both failure paths used to `return ''`/null with no trace anywhere, so a host missing gd,
     * iconv or fileinfo produced an order page with no QR code and nothing to diagnose it with —
     * not even with debug logging on. The logger is optional and injected the same way
     * BitcoinAddressService::generate_address_from_xPub() takes one.
     */
    private function report_failure(\Throwable $e, ?callable $logger): void
    {
        if ($logger === null) {
            return;
        }

        $missing = EnvironmentRequirements::missing_qr_extensions();

        $logger(
            \sprintf(
                'QR code generation failed: %s%s',
                esc_html(wp_strip_all_tags($e->getMessage())),
                empty($missing)
                    ? ''
                    : \sprintf(' (this host is missing the PHP extension(s): %s)', esc_html(implode(', ', $missing)))
            ),
            'error'
        );
    }

    private function generate_native(string $data, ?string $logo_src, ?callable $logger = null): string
    {
        // This is the fallback path generate_with_bordered_logo() falls through to on GD
        // failure, but it depends on GD (PngWriter), fileinfo (mime_content_type for the logo)
        // and iconv (hard requirement of bacon/bacon-qr-code) just the same — any of those
        // missing must degrade to "no QR" rather than fatal the order-details page.
        try {
            $result = Builder::create()
                ->writer(new PngWriter())
                ->writerOptions([])
                ->validateResult(false)
                ->data($data);

            if ($logo_src) {
                $result = $result->logoPath($logo_src)
                    ->logoResizeToWidth(self::DEFAULT_LOGO_SIZE);
            }

            $result = $result->errorCorrectionLevel($logo_src ? new ErrorCorrectionLevelHigh() : new ErrorCorrectionLevelLow())
                ->encoding(new Encoding('UTF-8'))
                ->size(self::QR_SIZE)
                ->margin(0)
                ->build();

            return $result->getDataUri();
        } catch (\Throwable $e) {
            $this->report_failure($e, $logger);

            return '';
        }
    }

    private function generate_with_bordered_logo(string $data, string $logo_src, array $border, ?callable $logger = null): ?string
    {
        if (!\extension_loaded('gd') || !\function_exists('imagefilledellipse')) {
            return null;
        }

        try {
            $qr_image = $this->build_qr_gd_image($data);
            $badge    = $this->build_bordered_logo($logo_src, $border);

            $this->stamp_center($qr_image, $badge);

            $data_uri = $this->gd_to_png_data_uri($qr_image);

            imagedestroy($badge);
            imagedestroy($qr_image);

            return $data_uri;
        } catch (\Throwable $e) {
            // Not fatal on its own — generate_native() is tried next — so this is only worth
            // reporting if that fallback also fails, which it reports itself. Kept as a debug
            // breadcrumb for the case where the bordered path is the one that's broken.
            if ($logger !== null) {
                $logger(
                    \sprintf('QR bordered-logo path failed, falling back: %s', esc_html(wp_strip_all_tags($e->getMessage()))),
                    'debug'
                );
            }

            return null;
        }
    }

    /** @return mixed GD image resource of the logo-less QR at QR_SIZE. */
    private function build_qr_gd_image(string $data)
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
            ->validateResult(false)
            ->data($data)
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->encoding(new Encoding('UTF-8'))
            ->size(self::QR_SIZE)
            ->margin(0)
            ->build();

        return imagecreatefromstring($result->getString());
    }

    /** @return mixed GD image of the composited badge (transparent outside the shape). */
    private function build_bordered_logo(string $logo_src, array $border)
    {
        $size  = $this->clamp_logo_size((int) ($border['size'] ?? self::DEFAULT_LOGO_SIZE));
        $width = max(0, (int) ($border['width'] ?? self::DEFAULT_BORDER_WIDTH));
        $shape = ($border['shape'] ?? 'circle') === 'square' ? 'square' : 'circle';

        $border_color = $this->parse_color($border['color'] ?? self::DEFAULT_BORDER_COLOR);
        $has_background = !array_key_exists('background', $border) || $border['background'] !== null;
        $background_color = $this->parse_color(
            array_key_exists('background', $border) && $border['background'] !== null
                ? $border['background']
                : self::DEFAULT_BACKGROUND_COLOR
        );

        $scale       = self::SUPERSAMPLE;
        $canvas_size = $size * $scale;
        $border_px   = $width * $scale;
        $center      = $canvas_size / 2;

        $badge = $this->create_transparent_canvas($canvas_size, $canvas_size);
        imagealphablending($badge, true);

        // Outer shape draws the ring color; the inner shape (inset by the border width)
        // repaints the plate, leaving a $width-wide ring of the border color visible.
        $this->draw_shape($badge, $shape, $center, $canvas_size, $this->allocate($badge, $border_color));

        $inner_size = $canvas_size - 2 * $border_px;
        if ($has_background && $inner_size > 0) {
            $this->draw_shape($badge, $shape, $center, $inner_size, $this->allocate($badge, $background_color));
        }

        $icon = $this->load_image($logo_src);
        $icon_size = $inner_size > 0 ? $inner_size : $canvas_size;
        imagecopyresampled(
            $badge,
            $icon,
            (int) ($center - $icon_size / 2),
            (int) ($center - $icon_size / 2),
            0,
            0,
            (int) $icon_size,
            (int) $icon_size,
            imagesx($icon),
            imagesy($icon)
        );
        imagedestroy($icon);

        $out = $this->create_transparent_canvas($size, $size);
        imagecopyresampled($out, $badge, 0, 0, 0, 0, $size, $size, $canvas_size, $canvas_size);
        imagedestroy($badge);

        return $out;
    }

    /** Draws a centered filled shape ($shape) of the given full width/height into $image. */
    private function draw_shape($image, string $shape, float $center, int $extent, int $color): void
    {
        if ($shape === 'square') {
            $half = $extent / 2;
            imagefilledrectangle(
                $image,
                (int) ($center - $half),
                (int) ($center - $half),
                (int) ($center + $half),
                (int) ($center + $half),
                $color
            );
            return;
        }

        imagefilledellipse($image, (int) $center, (int) $center, $extent, $extent, $color);
    }

    private function stamp_center($qr_image, $badge): void
    {
        imagealphablending($qr_image, true);
        $x = (int) ((imagesx($qr_image) - imagesx($badge)) / 2);
        $y = (int) ((imagesy($qr_image) - imagesy($badge)) / 2);
        imagecopy($qr_image, $badge, $x, $y, 0, 0, imagesx($badge), imagesy($badge));
    }

    /** @return mixed */
    private function create_transparent_canvas(int $width, int $height)
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        return $image;
    }

    /** @return mixed */
    private function load_image(string $path)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a bundled plugin asset (local logo file), not remote/user input.
        $raw = @file_get_contents($path);

        if (!is_string($raw)) {
            throw new \RuntimeException(sprintf('Unable to read logo at "%s"', esc_html($path)));
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw new \RuntimeException(sprintf('Unable to parse logo at "%s"', esc_html($path)));
        }

        return $image;
    }

    private function gd_to_png_data_uri($image): string
    {
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a data: URI, not obfuscating code.
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function clamp_logo_size(int $size): int
    {
        $max = (int) floor(self::QR_SIZE * self::MAX_LOGO_FRACTION);

        return max(1, min($size, $max));
    }

    /** @param string|int[] $color @return int[] [r, g, b] */
    private function parse_color($color): array
    {
        if (is_array($color)) {
            return [
                (int) ($color[0] ?? 0),
                (int) ($color[1] ?? 0),
                (int) ($color[2] ?? 0),
            ];
        }

        $hex = ltrim((string) $color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [255, 255, 255];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @param int[] $rgb */
    private function allocate($image, array $rgb): int
    {
        return (int) imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }
}
