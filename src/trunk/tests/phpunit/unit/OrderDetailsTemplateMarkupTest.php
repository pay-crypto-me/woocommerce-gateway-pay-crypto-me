<?php

use PHPUnit\Framework\TestCase;

/**
 * The order-details section renders on BOTH the customer's order page and the admin order screen,
 * and the admin one sits inside WooCommerce's order <form>. A <button> with no type attribute
 * defaults to submit there, so clicking "copy address" saved the order and answered with
 * "Order updated." — a write the merchant never asked for, from a button that only copies text.
 *
 * Asserted against the template source because nothing else in the suite can see it: the template
 * is rendered through wc_get_template(), which the unit shims stub out.
 */
class OrderDetailsTemplateMarkupTest extends TestCase
{
    private function template(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/templates/order-details/paycrypto-me-order-details.php'
        );
    }

    public function test_every_button_declares_an_explicit_type()
    {
        preg_match_all('/<button\b[^>]*>/s', $this->template(), $matches);

        $this->assertNotEmpty($matches[0], 'template no longer has the copy button — update this test');

        foreach ($matches[0] as $tag) {
            $this->assertMatchesRegularExpression(
                '/\btype\s*=\s*"(button|submit|reset)"/',
                $tag,
                'a button with no type submits the admin order form it renders inside'
            );
        }
    }

    public function test_the_copy_address_button_is_not_a_submit()
    {
        $this->assertMatchesRegularExpression(
            '/<button\s+type="button"\s+class="paycrypto-me-order-details__copy-address-button"/',
            $this->template()
        );
    }
}
