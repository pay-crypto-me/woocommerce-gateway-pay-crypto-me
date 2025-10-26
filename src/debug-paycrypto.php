<?php
/**
 * PayCrypto.Me Debug Script
 * 
 * Add this to your functions.php or create a temporary plugin to debug
 * Remove this file after debugging is complete
 */

add_action('wp_footer', function() {
    if (!is_admin() && (is_checkout() || is_cart())) {
        ?>
        <script>
        console.log('=== PayCrypto.Me Debug Info ===');
        
        // Check if WooCommerce is loaded
        console.log('WC Object:', typeof WC !== 'undefined' ? 'Available' : 'Not Available');
        
        // Check payment gateways
        if (typeof wc_checkout_params !== 'undefined') {
            console.log('Available Payment Methods:', wc_checkout_params.available_gateways);
        }
        
        // Check if our payment method data is available
        if (typeof paycrypto_me_data !== 'undefined') {
            console.log('PayCrypto.Me Data:', paycrypto_me_data);
        } else {
            console.log('PayCrypto.Me Data: Not Available');
        }
        
        // Check for our script
        const ourScript = document.querySelector('script[src*="paycrypto"]');
        console.log('PayCrypto.Me Script Loaded:', ourScript ? 'Yes' : 'No');
        
        console.log('=== End Debug Info ===');
        </script>
        <?php
    }
});

// Add admin debug info
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $gateways = WC()->payment_gateways->payment_gateways();
    $paycrypto_gateway = isset($gateways['paycrypto_me']) ? $gateways['paycrypto_me'] : null;
    
    if ($paycrypto_gateway) {
        $enabled = $paycrypto_gateway->enabled;
        $available = $paycrypto_gateway->is_available() ? 'Yes' : 'No';
        $hide_for_non_admin = $paycrypto_gateway->hide_for_non_admin_users;
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>PayCrypto.Me Debug:</strong><br>';
        echo 'Gateway Found: Yes<br>';
        echo 'Enabled: ' . $enabled . '<br>';
        echo 'Available: ' . $available . '<br>';
        echo 'Hide for Non-Admin: ' . $hide_for_non_admin . '<br>';
        echo 'Current User Is Admin: ' . (current_user_can('manage_options') ? 'Yes' : 'No');
        echo '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p><strong>PayCrypto.Me Gateway not found!</strong></p></div>';
    }
});
?>