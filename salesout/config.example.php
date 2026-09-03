<?php
// salesout/config.example.php — copy to config.php (gitignored)
define('SALESOUT_PATH', __DIR__);
define('BUDGET_PATH', dirname(__DIR__));

require_once BUDGET_PATH . '/config.php';

define('SALESOUT_APP_NAME', 'Distributor Sales Out Report');
define('SALESOUT_SITE_URL', (defined('SITE_URL') ? SITE_URL : '') . '/salesout');

define('SALESOUT_MAX_EXCEL_UPLOAD', 8 * 1024 * 1024);
define('SALESOUT_MAX_CSV_UPLOAD', 50 * 1024 * 1024);

if (!defined('SALESOUT_PRODUCT_THUMBS_PUBLIC_ENABLED')) {
    define('SALESOUT_PRODUCT_THUMBS_PUBLIC_ENABLED', true);
}

if (!defined('AI_SUMMARY_ENABLED')) define('AI_SUMMARY_ENABLED', false);
define('DEEPSEEK_API_KEY', '');
define('GEMINI_API_KEY', '');
define('OPENAI_API_KEY', '');

if (!defined('SALESOUT_OPPORTUNITIES_ENABLED')) define('SALESOUT_OPPORTUNITIES_ENABLED', false);
define('SALESOUT_OPPORTUNITIES_SHEET_ID', '');
define('SALESOUT_OPPORTUNITIES_SHEET_GID', '0');

if (!defined('SALESOUT_SF_ENABLED')) define('SALESOUT_SF_ENABLED', false);
if (!defined('SALESOUT_SF_CLIENT_ID')) define('SALESOUT_SF_CLIENT_ID', '');
if (!defined('SALESOUT_SF_CLIENT_SECRET')) define('SALESOUT_SF_CLIENT_SECRET', '');
if (!defined('SALESOUT_SF_USERNAME')) define('SALESOUT_SF_USERNAME', '');
if (!defined('SALESOUT_SF_PASSWORD')) define('SALESOUT_SF_PASSWORD', '');
if (!defined('SALESOUT_SF_SECURITY_TOKEN')) define('SALESOUT_SF_SECURITY_TOKEN', '');

if (!defined('VENDOR_API_URL')) define('VENDOR_API_URL', '');
if (!defined('VENDOR_API_KEY')) define('VENDOR_API_KEY', '');
