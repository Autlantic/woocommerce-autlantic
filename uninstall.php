<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_autlantic_settings');
delete_option('autlantic_wc_processed_events');
delete_option('autlantic_wc_activity');
