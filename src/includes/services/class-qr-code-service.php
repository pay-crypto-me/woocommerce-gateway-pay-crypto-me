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
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\Writer\PngWriter;


class QrCodeService
{
    public function generate_qr_code_data_uri(string $data, ?string $logo_src = null): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
            ->validateResult(false)
            ->data($data);

        if ($logo_src) {
            $result = $result->logoPath($logo_src)
                ->logoResizeToWidth(70);
        }

        $result = $result->errorCorrectionLevel(new ErrorCorrectionLevelLow())
            ->encoding(new Encoding('UTF-8'))
            ->size(225)
            ->margin(0)
            ->build();

        return $result->getDataUri();
    }
}