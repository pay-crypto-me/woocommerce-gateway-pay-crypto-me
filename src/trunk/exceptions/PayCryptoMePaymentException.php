<?php

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PayCryptoMePaymentException extends PayCryptoMeException
{
    private string $user_friendly_message;

    public function __construct(string $message, string $user_friendly_message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        // Translated like every other customer-facing string: this is the fallback shown for any
        // failure that doesn't carry its own message (every non-PayCryptoMePaymentException goes
        // through convertToMyself()), so it is the one customers see most often.
        $this->user_friendly_message = $user_friendly_message
            ?: __('We couldn\'t complete your payment. Please try again or contact support if the problem persists.', 'paycrypto-me-for-woocommerce');
    }

    public function getUserFriendlyMessage(): string
    {
        return $this->user_friendly_message;
    }

    public static function convertToMyself(\Throwable $e): PayCryptoMePaymentException
    {
        if ($e instanceof PayCryptoMePaymentException) {
            return $e;
        }

        return new self($e->getMessage(), '', (int) $e->getCode(), $e);
    }
}