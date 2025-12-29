<?php

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PayCryptoMePaymentException extends PayCryptoMeException
{
    private string $user_friendly_message;

    public function __construct(string $message, string $user_friendly_message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->user_friendly_message = $user_friendly_message ?: 'We couldn\'t complete your payment. Please try again or contact support if the problem persists.';
    }

    public function getUserFriendlyMessage(): string
    {
        return $this->user_friendly_message;
    }

    public static function convertToMyself(\Exception $e): PayCryptoMePaymentException
    {
        if ($e instanceof PayCryptoMePaymentException) {
            return $e;
        }

        return new self($e->getMessage(), '', (int) $e->getCode(), $e);
    }
}