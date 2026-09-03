<?php
// config.example.php - Copy this file to config.php and update DB credentials
// cp config.example.php config.php
// DO NOT commit config.php - it is in .gitignore

// Application settings - UPDATE THESE
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmmbudget');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password');

// Optional: required as ?key= for excel_api.php (Power Query). Leave empty to disable that endpoint.
define('EXCEL_API_KEY', '');

define('SITE_URL', 'https://yoursite.com/budgets');
define('APP_NAME', 'Global Budget System');
define('DEFAULT_CURRENCY', 'EUR');
define('FISCAL_YEAR', 2026);

// Aggregate Regional configurations
$REGIONAL_SETTINGS = [
    'AMER' => [
        'currency' => 'USD',
        'allowed_currencies' => ['USD', 'CAD'],
        'budget_limit' => 600090,
        'color' => '#B22234',
        'countries' => ['America', 'Canada']
    ],
    'DACH' => [
        'currency' => 'EUR',
        'allowed_currencies' => ['EUR'],
        'budget_limit' => 381292,
        'color' => '#002395',
        'countries' => ['Germany', 'Austria', 'Switzerland']
    ],
    'UKI' => [
        'currency' => 'GBP',
        'allowed_currencies' => ['GBP'],
        'budget_limit' => 349246,
        'color' => '#012169',
        'countries' => ['UK', 'Ireland']
    ],
    'APAC' => [
        'currency' => 'USD',
        'allowed_currencies' => ['USD', 'JPY', 'CNY', 'HKD', 'SGD'],
        'budget_limit' => 92498,
        'color' => '#DE2910',
        'countries' => ['Hong Kong', 'Singapore', 'China', 'Japan']
    ],
    'ANZ' => [
        'currency' => 'AUD',
        'allowed_currencies' => ['AUD', 'NZD'],
        'budget_limit' => 116420,
        'color' => '#012169',
        'countries' => ['Australia', 'New Zealand']
    ],
    'NORD' => [
        'currency' => 'EUR',
        'allowed_currencies' => ['EUR'],
        'budget_limit' => 172733,
        'color' => '#006AA7',
        'countries' => ['Denmark', 'Sweden', 'Norway', 'Finland', 'Iceland']
    ],
    'BNL' => [
        'currency' => 'EUR',
        'allowed_currencies' => ['EUR'],
        'budget_limit' => 109937,
        'color' => '#FFCE00',
        'countries' => ['Belgium', 'Netherlands', 'Luxembourg']
    ],
    'FRANCE' => [
        'currency' => 'EUR',
        'allowed_currencies' => ['EUR'],
        'budget_limit' => 184158,
        'color' => '#0055A4',
        'countries' => ['France']
    ],
    'EMEA_PARTNERS' => [
        'currency' => 'EUR',
        'allowed_currencies' => ['EUR'],
        'budget_limit' => 137790,
        'color' => '#8A2BE2',
        'countries' => ['Italy', 'Spain', 'Portugal', 'Greece', 'Poland', 'Czech Republic', 'Hungary', 'Romania', 'Russia', 'South Africa', 'UAE', 'Saudi Arabia']
    ],
    'INDIA' => [
        'currency' => 'INR',
        'allowed_currencies' => ['INR'],
        'budget_limit' => 183059,
        'color' => '#FF9933',
        'countries' => ['India']
    ]
];

$CURRENCY_SYMBOLS = [
    'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$',
    'CHF' => 'CHF ', 'NZD' => 'NZ$', 'HKD' => 'HK$', 'SGD' => 'S$',
    'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹'
];

$DEFAULT_CONVERSION_RATES = [
    'EUR' => 1.00, 'USD' => 1.08, 'GBP' => 0.85, 'AUD' => 1.65, 'CAD' => 1.47,
    'CHF' => 0.95, 'NZD' => 1.78, 'HKD' => 8.45, 'SGD' => 1.45,
    'JPY' => 160.00, 'CNY' => 7.80, 'INR' => 90.00
];

$COUNTRY_TO_REGION = [
    'America' => 'AMER', 'Canada' => 'AMER', 'Germany' => 'DACH', 'Austria' => 'DACH', 'Switzerland' => 'DACH',
    'UK' => 'UKI', 'Ireland' => 'UKI', 'Hong Kong' => 'APAC', 'Singapore' => 'APAC', 'China' => 'APAC', 'Japan' => 'APAC',
    'India' => 'INDIA', 'Australia' => 'ANZ', 'New Zealand' => 'ANZ',
    'Denmark' => 'NORD', 'Sweden' => 'NORD', 'Norway' => 'NORD', 'Finland' => 'NORD', 'Iceland' => 'NORD',
    'Belgium' => 'BNL', 'Netherlands' => 'BNL', 'Luxembourg' => 'BNL', 'France' => 'FRANCE',
    'Italy' => 'EMEA_PARTNERS', 'Spain' => 'EMEA_PARTNERS', 'Portugal' => 'EMEA_PARTNERS', 'Greece' => 'EMEA_PARTNERS',
    'Poland' => 'EMEA_PARTNERS', 'Czech Republic' => 'EMEA_PARTNERS', 'Hungary' => 'EMEA_PARTNERS',
    'Romania' => 'EMEA_PARTNERS', 'Russia' => 'EMEA_PARTNERS', 'South Africa' => 'EMEA_PARTNERS',
    'UAE' => 'EMEA_PARTNERS', 'Saudi Arabia' => 'EMEA_PARTNERS'
];

$USER_ROLES = [
    'admin' => ['name' => 'Administrator', 'permissions' => ['manage_users', 'manage_budgets', 'manage_rates', 'view_all']],
    'manager' => ['name' => 'Regional Manager', 'permissions' => ['manage_budgets', 'view_all']],
    'user' => ['name' => 'Standard User', 'permissions' => ['view_budgets']]
];

$REGION_ACCESS = [
    'AMER' => ['AMER'], 'DACH' => ['DACH'], 'UKI' => ['UKI'], 'APAC' => ['APAC', 'ANZ', 'INDIA'],
    'ANZ' => ['ANZ'], 'NORD' => ['NORD'], 'BNL' => ['BNL'], 'FRANCE' => ['FRANCE'],
    'EMEA_PARTNERS' => ['EMEA_PARTNERS'], 'INDIA' => ['INDIA']
];

function getDBConnection() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function initConversionRates($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS conversion_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        base_currency VARCHAR(3) DEFAULT 'EUR',
        target_currency VARCHAR(3) NOT NULL,
        exchange_rate DECIMAL(10,4) NOT NULL,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_currency (target_currency)
    )";
    $pdo->exec($sql);
    $check = $pdo->query("SELECT COUNT(*) FROM conversion_rates")->fetchColumn();
    if ($check == 0) {
        global $DEFAULT_CONVERSION_RATES;
        $stmt = $pdo->prepare("INSERT INTO conversion_rates (target_currency, exchange_rate) VALUES (?, ?)");
        foreach ($DEFAULT_CONVERSION_RATES as $currency => $rate) {
            if ($currency !== 'EUR') $stmt->execute([$currency, $rate]);
        }
    }
}

function convertToLocal($amountEUR, $targetCurrency, $pdo = null) {
    if ($targetCurrency === 'EUR') return $amountEUR;
    if (!$pdo) $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT exchange_rate FROM conversion_rates WHERE target_currency = ?");
    $stmt->execute([$targetCurrency]);
    $rate = $stmt->fetchColumn() ?? ($GLOBALS['DEFAULT_CONVERSION_RATES'][$targetCurrency] ?? 1.0);
    return $amountEUR * $rate;
}

function convertToEUR($amount, $sourceCurrency, $pdo = null) {
    if ($sourceCurrency === 'EUR') return $amount;
    if (!$pdo) $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT exchange_rate FROM conversion_rates WHERE target_currency = ?");
    $stmt->execute([$sourceCurrency]);
    $rate = $stmt->fetchColumn() ?? ($GLOBALS['DEFAULT_CONVERSION_RATES'][$sourceCurrency] ?? 1.0);
    return $amount / $rate;
}

function getConversionRates($pdo) {
    return $pdo->query("SELECT target_currency, exchange_rate, last_updated FROM conversion_rates ORDER BY target_currency")->fetchAll(PDO::FETCH_ASSOC);
}

function updateConversionRate($pdo, $currency, $rate) {
    $stmt = $pdo->prepare("INSERT INTO conversion_rates (target_currency, exchange_rate) VALUES (?, ?) ON DUPLICATE KEY UPDATE exchange_rate = ?, last_updated = CURRENT_TIMESTAMP");
    return $stmt->execute([$currency, $rate, $rate]);
}
?>
