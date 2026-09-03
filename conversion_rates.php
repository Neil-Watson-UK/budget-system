<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('conversion_rates.php'));
    exit;
}

$pdo = getDBConnection();
initConversionRates($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rates'])) {
    $hasPermission = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager']);
    if (!$hasPermission) {
        $error = 'You do not have permission to update conversion rates.';
    } else {
        $updated = 0;
        foreach ($_POST['rates'] ?? [] as $currency => $rate) {
            $rate = floatval(str_replace(',', '.', $rate));
            if ($currency && $currency !== 'EUR' && $rate > 0) {
                if (updateConversionRate($pdo, $currency, $rate)) {
                    $updated++;
                }
            }
        }
        $message = $updated > 0 ? "Updated $updated conversion rate(s)." : "No changes applied.";
    }
}

$rates = getConversionRates($pdo);
global $DEFAULT_CONVERSION_RATES, $CURRENCY_SYMBOLS;

// Merge with defaults so we show all known currencies even if not in DB yet
$allCurrencies = array_unique(array_merge(
    array_column($rates, 'target_currency'),
    array_keys($DEFAULT_CONVERSION_RATES)
));
sort($allCurrencies);
$ratesByCurrency = [];
foreach ($rates as $r) {
    $ratesByCurrency[$r['target_currency']] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conversion Rates - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="container-xl">
            <div class="page-header d-print-none mb-4">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <h2 class="page-title">
                            <i class="ti ti-exchange me-2"></i>Conversion Rates
                        </h2>
                        <div class="text-muted mt-1">Exchange rates used to convert regional spend to EUR. Base currency: EUR.</div>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="ti ti-check me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="ti ti-currency-euro me-2"></i>Currency Exchange Rates (1 EUR = X)</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="update_rates" value="1">
                        <div class="table-responsive">
                            <table class="table table-vcenter">
                                <thead>
                                    <tr>
                                        <th>Currency</th>
                                        <th>Rate (1 EUR =)</th>
                                        <th>Last Updated</th>
                                        <th>Symbol</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allCurrencies as $curr): 
                                        if ($curr === 'EUR') continue;
                                        $row = $ratesByCurrency[$curr] ?? null;
                                        $rateVal = $row ? $row['exchange_rate'] : ($DEFAULT_CONVERSION_RATES[$curr] ?? '');
                                        $lastUpdated = $row ? $row['last_updated'] : null;
                                        $symbol = $CURRENCY_SYMBOLS[$curr] ?? $curr;
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($curr) ?></strong></td>
                                        <td>
                                            <input type="number" name="rates[<?= htmlspecialchars($curr) ?>]" 
                                                   value="<?= htmlspecialchars($rateVal) ?>" 
                                                   step="0.0001" min="0.0001" class="form-control" style="max-width: 140px;">
                                        </td>
                                        <td class="text-muted"><?= $lastUpdated ? date('M j, Y H:i', strtotime($lastUpdated)) : '�' ?></td>
                                        <td><?= htmlspecialchars($symbol) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="ti ti-device-floppy me-1"></i> Save Rates
                            </button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
