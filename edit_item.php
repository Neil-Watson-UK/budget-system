<?php

session_start();

require_once 'config.php';

require_once 'functions.php';



$pdo = getDBConnection();



// Get the item to edit

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id = ?");

$stmt->execute([$id]);

$item = $stmt->fetch(PDO::FETCH_ASSOC);



if (!$item) {

    header("Location: index.php?error=Item not found");

    exit;

}



// Get form field options

$account_options = getFormFieldOptions('accounting_code');

$sub_account_options = getFormFieldOptions('sub_accounting_code');



// Handle form submission

if ($_POST) {

    $stmt = $pdo->prepare("

        UPDATE budget_items SET

            po_prefix = ?,

            region = ?,

            country = ?,

            start_date = ?,

            end_date = ?,

            invoiced_date = ?,

            amount_requested = ?,

            currency = ?,

            activity_title = ?,

            status = ?,

            frequency_of_spend = ?,

            vendor = ?,

            external_vendor = ?,

            vendor_contact = ?,

            account = ?,

            sub_account = ?,

            budget_category = ?,

            activity_description = ?,

            comments = ?,

            associated_epos_staff = ?,

            item_type = ?,

            path = ?,

            entry_updated_date = NOW()

        WHERE id = ?

    ");

    

    $currency = $REGIONAL_SETTINGS[$_POST['region']]['currency'] ?? 'EUR';

    

    $stmt->execute([

        $_POST['po_prefix'],

        $_POST['region'],

        $_POST['country'] ?? '',

        $_POST['start_date'] ?: null,

        $_POST['end_date'] ?: null,

        $_POST['invoiced_date'] ?: null,

        $_POST['amount_requested'],

        $currency,

        $_POST['activity_title'],

        $_POST['status'],

        $_POST['frequency_of_spend'],

        $_POST['vendor'],

        $_POST['external_vendor'],

        $_POST['vendor_contact'],

        $_POST['account'],

        $_POST['sub_account'],

        $_POST['budget_category'],

        $_POST['activity_description'],

        $_POST['comments'],

        $_POST['associated_epos_staff'],

        $_POST['item_type'],

        $_POST['path'],

        $id

    ]);

    

    header("Location: index.php?success=Item updated successfully");

    exit;

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Budget Item - <?= APP_NAME ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">

        <div class="container">

            <a class="navbar-brand" href="index.php">

                <i class="fas fa-edit"></i> Edit Budget Item

            </a>

        </div>

    </nav>



    <div class="container mt-4">

        <div class="row">

            <div class="col-md-10 mx-auto">

                <div class="card">

                    <div class="card-header d-flex justify-content-between align-items-center">

                        <h4><i class="fas fa-edit"></i> Edit Budget Item</h4>

                        <span class="badge bg-primary"><?= $item['po_number'] ?></span>

                    </div>

                    <div class="card-body">

                        <form method="POST" id="budgetForm">

                            <div class="row mb-3">

                                <div class="col-md-6">

                                    <label class="form-label">PO Number</label>

                                    <input type="text" class="form-control" value="<?= htmlspecialchars($item['po_number']) ?>" readonly>

                                    <div class="form-text">PO Number cannot be changed</div>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">PO Prefix</label>

                                    <input type="text" name="po_prefix" class="form-control" value="<?= htmlspecialchars($item['po_prefix']) ?>" placeholder="e.g., MKTG, OPS, IT">

                                </div>

                            </div>



                            <div class="row mb-3">

                                <div class="col-md-4">

                                    <label class="form-label">Region *</label>

                                    <select name="region" class="form-select" required id="region_select">

                                        <option value="">Select Region</option>

                                        <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>

                                        <option value="<?= $region ?>" <?= $item['region'] == $region ? 'selected' : '' ?>>

                                            <?= $region ?> (<?= $settings['currency'] ?>)

                                        </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Country *</label>

                                    <select name="country" class="form-select" required id="country_select">

                                        <option value="">Select Country</option>

                                        <!-- Countries will be populated by JavaScript -->

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Currency</label>

                                    <input type="text" class="form-control" id="currency_display" value="<?= $REGIONAL_SETTINGS[$item['region']]['currency'] ?? 'EUR' ?>" readonly>

                                    <div class="form-text">Currency is determined by region</div>

                                </div>

                            </div>



                            <div class="row mb-3">

                                <div class="col-md-4">

                                    <label class="form-label">Amount Requested *</label>

                                    <div class="input-group">

                                        <span class="input-group-text" id="currency_symbol"><?= $CURRENCY_SYMBOLS[$REGIONAL_SETTINGS[$item['region']]['currency'] ?? 'EUR'] ?? '€' ?></span>

                                        <input type="number" name="amount_requested" class="form-control" step="0.01" required value="<?= $item['amount_requested'] ?>">

                                    </div>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Status *</label>

                                    <select name="status" class="form-select" required>

                                        <option value="Planned" <?= $item['status'] == 'Planned' ? 'selected' : '' ?>>Planned</option>

                                        <option value="Invoiced" <?= $item['status'] == 'Invoiced' ? 'selected' : '' ?>>Invoiced</option>

                                        <option value="Executed" <?= $item['status'] == 'Executed' ? 'selected' : '' ?>>Executed</option>

                                        <option value="Cancelled" <?= $item['status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>

                                        <option value="Allocated" <?= $item['status'] == 'Allocated' ? 'selected' : '' ?>>Allocated</option>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Frequency</label>

                                    <select name="frequency_of_spend" class="form-select">

                                        <option value="One-off" <?= $item['frequency_of_spend'] == 'One-off' ? 'selected' : '' ?>>One-off</option>

                                        <option value="Monthly" <?= $item['frequency_of_spend'] == 'Monthly' ? 'selected' : '' ?>>Monthly</option>

                                        <option value="Quarterly" <?= $item['frequency_of_spend'] == 'Quarterly' ? 'selected' : '' ?>>Quarterly</option>

                                        <option value="Annually" <?= $item['frequency_of_spend'] == 'Annually' ? 'selected' : '' ?>>Annually</option>

                                        <option value="Bi-Annually" <?= $item['frequency_of_spend'] == 'Bi-Annually' ? 'selected' : '' ?>>Bi-Annually</option>

                                    </select>

                                </div>

                            </div>



                            <div class="mb-3">

                                <label class="form-label">Activity Title *</label>

                                <input type="text" name="activity_title" class="form-control" required value="<?= htmlspecialchars($item['activity_title']) ?>">

                            </div>



                            <div class="row mb-3">

                                <div class="col-md-4">

                                    <label class="form-label">Vendor</label>

                                    <input type="text" name="vendor" class="form-control" value="<?= htmlspecialchars($item['vendor']) ?>">

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">External Vendor</label>

                                    <input type="text" name="external_vendor" class="form-control" value="<?= htmlspecialchars($item['external_vendor']) ?>">

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Vendor Contact</label>

                                    <input type="text" name="vendor_contact" class="form-control" value="<?= htmlspecialchars($item['vendor_contact']) ?>">

                                </div>

                            </div>



                            <!-- UPDATED: Account and Sub Account as dropdowns -->

                            <div class="row mb-3">

                                <div class="col-md-4">

                                    <label class="form-label">Account</label>

                                    <select name="account" class="form-select">

                                        <option value="">Select Account</option>

                                        <?php foreach ($account_options as $option): ?>

                                            <option value="<?= htmlspecialchars($option['field_value']) ?>" <?= $item['account'] == $option['field_value'] ? 'selected' : '' ?>>

                                                <?= htmlspecialchars($option['field_label']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Sub Account</label>

                                    <select name="sub_account" class="form-select">

                                        <option value="">Select Sub Account</option>

                                        <?php foreach ($sub_account_options as $option): ?>

                                            <option value="<?= htmlspecialchars($option['field_value']) ?>" <?= $item['sub_account'] == $option['field_value'] ? 'selected' : '' ?>>

                                                <?= htmlspecialchars($option['field_label']) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

    <label class="form-label">Budget Category</label>

    <select name="budget_category" class="form-select">

        <option value="">Select Category</option>

        <option value="advertising" <?= $item['budget_category'] == 'advertising' ? 'selected' : '' ?>>Advertising</option>

        <option value="catalogue" <?= $item['budget_category'] == 'catalogue' ? 'selected' : '' ?>>Catalogue</option>

        <option value="digital" <?= $item['budget_category'] == 'digital' ? 'selected' : '' ?>>Digital</option>

        <option value="email" <?= $item['budget_category'] == 'email' ? 'selected' : '' ?>>Email</option>

        <option value="event" <?= $item['budget_category'] == 'event' ? 'selected' : '' ?>>Event</option>

        <option value="gift" <?= $item['budget_category'] == 'gift' ? 'selected' : '' ?>>Gift</option>

        <option value="other" <?= $item['budget_category'] == 'other' ? 'selected' : '' ?>>Other</option>

        <option value="product" <?= $item['budget_category'] == 'product' ? 'selected' : '' ?>>Product</option>

        <option value="shipping" <?= $item['budget_category'] == 'shipping' ? 'selected' : '' ?>>Shipping</option>

        <option value="sponsorship" <?= $item['budget_category'] == 'sponsorship' ? 'selected' : '' ?>>Sponsorship</option>

    </select>

</div>

                            </div>



                            <div class="row mb-3">

                                <div class="col-md-4">

                                    <label class="form-label">Start Date</label>

                                    <input type="date" name="start_date" class="form-control" value="<?= $item['start_date'] ?>">

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">End Date</label>

                                    <input type="date" name="end_date" class="form-control" value="<?= $item['end_date'] ?>">

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Invoiced Date</label>

                                    <input type="date" name="invoiced_date" class="form-control" value="<?= $item['invoiced_date'] ?>">

                                </div>

                            </div>



                            <div class="mb-3">

                                <label class="form-label">Activity Description</label>

                                <textarea name="activity_description" class="form-control" rows="3"><?= htmlspecialchars($item['activity_description']) ?></textarea>

                            </div>



                            <!-- UPDATED: Staff as dropdown and Item Type as dropdown -->

                            <div class="row mb-3">

                                <div class="col-md-6">

                                    <label class="form-label">Associated EPOS Staff *</label>

                                    <select name="associated_epos_staff" class="form-select" required id="staff_select">

                                        <option value="">Select Staff Member</option>

                                        <!-- Staff will be populated by JavaScript -->

                                    </select>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">Item Type</label>

                                    <select name="item_type" class="form-select">

                                        <option value="Reseller" <?= $item['item_type'] == 'Reseller' ? 'selected' : '' ?>>Reseller</option>

                                        <option value="Distributor" <?= $item['item_type'] == 'Distributor' ? 'selected' : '' ?>>Distributor</option>

                                        <option value="End User" <?= $item['item_type'] == 'End User' ? 'selected' : '' ?>>End User</option>

                                        <option value="Other" <?= $item['item_type'] == 'Other' ? 'selected' : '' ?>>Other</option>

                                    </select>

                                </div>

                            </div>



                            <div class="mb-3">

                                <label class="form-label">Comments</label>

                                <textarea name="comments" class="form-control" rows="2"><?= htmlspecialchars($item['comments']) ?></textarea>

                            </div>



                            <!-- UPDATED: Path as dropdown -->

                            <div class="mb-3">

                                <label class="form-label">Path</label>

                                <select name="path" class="form-select">

                                    <option value="direct" <?= $item['path'] == 'direct' ? 'selected' : '' ?>>Direct</option>

                                    <option value="channel" <?= $item['path'] == 'channel' ? 'selected' : '' ?>>Channel</option>

                                    <option value="partner" <?= $item['path'] == 'partner' ? 'selected' : '' ?>>Partner</option>

                                </select>

                            </div>



                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">

                                <a href="index.php" class="btn btn-secondary me-md-2">Cancel</a>

                                <button type="submit" class="btn btn-primary">Update Budget Item</button>

                            </div>

                        </form>

                    </div>

                </div>



                <!-- Item History -->

                <div class="card mt-4">

                    <div class="card-header">

                        <h6><i class="fas fa-history"></i> Item History</h6>

                    </div>

                    <div class="card-body">

                        <div class="row">

                            <div class="col-md-6">

                                <small class="text-muted">Created: <?= $item['entry_creation_date'] ?></small>

                            </div>

                            <div class="col-md-6">

                                <small class="text-muted">Last Updated: <?= $item['entry_updated_date'] ?></small>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>

        // Regional groups with their countries - MATCHING YOUR DATABASE ENUM

        const regionGroups = {
    'AMER': ['America', 'Canada'],
    'DACH': ['Germany', 'Austria', 'Switzerland'],
    'UKI': ['UK', 'Ireland'],
    'APAC': ['Hong Kong', 'Singapore', 'Japan', 'China'],
    'ANZ': ['Australia', 'New Zealand'],
    'NORD': ['Denmark', 'Sweden', 'Norway', 'Finland', 'Iceland'], // UPDATED
    'BNL': ['Belgium', 'Netherlands', 'Luxembourg'], // UPDATED
    'FRANCE': ['France'],
    'EMEA_PARTNERS': ['Italy', 'Spain', 'Portugal', 'Greece', 'Poland', 'Czech Republic', 'Hungary', 'Romania', 'Russia', 'South Africa', 'UAE', 'Saudi Arabia'],
    'INDIA': ['India']
};


        // Currency mapping

        const regionGroupCurrencies = {

            'AMER': 'USD',

            'DACH': 'EUR', 

            'UKI': 'GBP',

            'APAC': 'USD',

            'ANZ': 'AUD',

            'NORD': 'EUR',

            'BNL': 'EUR',

            'FRANCE': 'EUR',

            'EMEA_PARTNERS': 'EUR',

            'INDIA': 'INR'

        };



        const currencySymbols = <?= json_encode($CURRENCY_SYMBOLS) ?>;



        // Function to load staff based on region

        async function loadStaff(region) {

            const staffSelect = document.getElementById('staff_select');

            

            if (!region) {

                staffSelect.innerHTML = '<option value="">Select Staff Member</option>';

                return;

            }



            try {

                const response = await fetch(`get_staff.php?region=${region}`);

                const staff = await response.json();

                

                staffSelect.innerHTML = '<option value="">Select Staff Member</option>';

                staff.forEach(person => {

                    const option = document.createElement('option');

                    option.value = person.field_value;

                    option.textContent = person.field_label;

                    // Select if this is the current staff member

                    if (person.field_value === '<?= $item['associated_epos_staff'] ?>') {

                        option.selected = true;

                    }

                    staffSelect.appendChild(option);

                });

                

                // Add "not specified" option

                const notSpecifiedOption = document.createElement('option');

                notSpecifiedOption.value = 'notspecified@epos.com';

                notSpecifiedOption.textContent = 'Not Specified';

                if ('<?= $item['associated_epos_staff'] ?>' === 'notspecified@epos.com') {

                    notSpecifiedOption.selected = true;

                }

                staffSelect.appendChild(notSpecifiedOption);

            } catch (error) {

                console.error('Error loading staff:', error);

                staffSelect.innerHTML = '<option value="">Error loading staff</option>';

            }

        }



        // When region changes, update countries, currency, and staff

        document.getElementById('region_select').addEventListener('change', function() {

            const region = this.value;

            const countrySelect = document.getElementById('country_select');

            const currencyDisplay = document.getElementById('currency_display');

            const currencySymbol = document.getElementById('currency_symbol');

            

            if (region && regionGroups[region]) {

                // Populate countries

                countrySelect.innerHTML = '<option value="">Select Country</option>';

                regionGroups[region].forEach(country => {

                    const option = document.createElement('option');

                    option.value = country;

                    option.textContent = country;

                    // Select if this is the current country

                    if (country === '<?= $item['country'] ?>') {

                        option.selected = true;

                    }

                    countrySelect.appendChild(option);

                });

                

                // Update currency

                const currency = regionGroupCurrencies[region];

                const symbol = currencySymbols[currency] || '€';

                currencyDisplay.value = currency;

                currencySymbol.textContent = symbol;

                

                // Load staff for this region

                loadStaff(region);

            } else {

                countrySelect.innerHTML = '<option value="">Select Country</option>';

                currencyDisplay.value = '';

                currencySymbol.textContent = '€';

            }

        });



        // Initialize form

        document.addEventListener('DOMContentLoaded', function() {

            const currentRegion = '<?= $item['region'] ?>';

            const currentCountry = '<?= $item['country'] ?>';

            

            // If we have a current region, populate countries and load staff

            if (currentRegion && regionGroups[currentRegion]) {

                const countrySelect = document.getElementById('country_select');

                countrySelect.innerHTML = '<option value="">Select Country</option>';

                regionGroups[currentRegion].forEach(country => {

                    const option = document.createElement('option');

                    option.value = country;

                    option.textContent = country;

                    if (country === currentCountry) {

                        option.selected = true;

                    }

                    countrySelect.appendChild(option);

                });

                

                // Load staff for current region

                loadStaff(currentRegion);

            }

        });



        // Form validation

        document.getElementById('budgetForm').addEventListener('submit', function(e) {

            const region = document.getElementById('region_select').value;

            const country = document.getElementById('country_select').value;

            const staff = document.getElementById('staff_select').value;

            

            if (!region || !country) {

                e.preventDefault();

                alert('Please select both Region and Country');

                return false;

            }

        });

    </script>

</body>

</html>