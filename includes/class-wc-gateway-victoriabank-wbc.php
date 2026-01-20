<?php

/**
 * @package wc-victoriabank
 */

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

require_once plugin_dir_path(WC_VICTORIABANK_PLUGIN_FILE) . 'includes/class-wc-payment-gateway-wbc-base.php';

final class WC_Gateway_Victoriabank_WBC extends WC_Payment_Gateway_WBC_Base
{
}
