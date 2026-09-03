<?php
// salesout/bootstrap.php - Load PhpSpreadsheet and dependencies
// Tries: budget/vendor/autoload.php, salesout/vendor/autoload.php
// Fallback: if budget/vendor/phpoffice/phpspreadsheet exists, use inline autoloader

$budgetVendor = dirname(__DIR__) . '/vendor';
$paths = [
    $budgetVendor . '/autoload.php',     // Budget's vendor
    __DIR__ . '/vendor/autoload.php',    // Salesout's own composer
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        return;
    }
}

// Fallback: PhpSpreadsheet folder exists but no autoload.php (e.g. partial vendor deploy)
$psPath = $budgetVendor . '/phpoffice/phpspreadsheet/src/PhpSpreadsheet';
if (is_dir($psPath)) {
    spl_autoload_register(function (string $class) use ($budgetVendor): void {
        $map = [
            'PhpOffice\\PhpSpreadsheet\\' => $budgetVendor . '/phpoffice/phpspreadsheet/src/PhpSpreadsheet/',
            'Composer\\Pcre\\' => $budgetVendor . '/composer/pcre/src/',
            'Psr\\SimpleCache\\' => $budgetVendor . '/psr/simple-cache/src/',
        ];
        foreach ($map as $prefix => $base) {
            if (strncmp($prefix, $class, strlen($prefix)) === 0) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (file_exists($file)) {
                    require $file;
                    return;
                }
            }
        }
    });
    return;
}

throw new RuntimeException(
    'PhpSpreadsheet required. Ensure budget/vendor/ exists (phpoffice/phpspreadsheet). ' .
    'If missing, run in budget folder: composer install'
);
