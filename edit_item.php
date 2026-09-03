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

$external_vendor_options = [];
if (!empty($item['region']) && isset($REGIONAL_SETTINGS[$item['region']])) {
    $external_vendor_options = getFormFieldOptions('external_vendor', $item['region']);
}

$planner_year_get = (int) date('Y');
if (!empty($item['start_date'])) {
    $planner_year_get = (int) date('Y', strtotime($item['start_date']));
} elseif (!empty($item['entry_creation_date'])) {
    $planner_year_get = (int) date('Y', strtotime($item['entry_creation_date']));
}

$attachments = getBudgetItemAttachments($pdo, $id);



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

            budget_accrual_approved = ?,

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

        isset($_POST['budget_accrual_approved']) ? 1 : 0,

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

    // Process file attachments
    if (!empty($_FILES['uploaded_files']['name'][0])) {
        $upload_dir = __DIR__ . '/uploads/budget_attachments/';
        foreach ($_FILES['uploaded_files']['name'] as $i => $name) {
            if (empty($name)) continue;
            $file = [
                'name' => $name,
                'type' => $_FILES['uploaded_files']['type'][$i] ?? '',
                'tmp_name' => $_FILES['uploaded_files']['tmp_name'][$i] ?? '',
                'error' => $_FILES['uploaded_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['uploaded_files']['size'][$i] ?? 0
            ];
            saveBudgetItemAttachment($pdo, $id, $file, $upload_dir);
        }
    }

    header("Location: edit_item.php?id=$id&success=Item updated successfully");

    exit;

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Budget Item - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    <style>
        .action-buttons-edit { position: sticky; bottom: 0; z-index: 50; background: white; padding: 1rem 0; border-top: 1px solid #dee2e6; }
        .file-upload-area { border: 2px dashed #e1e5eb; border-radius: 8px; padding: 1.5rem; text-align: center; background: #f8f9fa; cursor: pointer; }
        .file-upload-area:hover { border-color: #00a399; background: #e8f6ec; }
        .uploaded-file-item { padding: 0.5rem 1rem; margin-bottom: 0.5rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #00a399; }
        .card-header { background: linear-gradient(135deg, #00a399 0%, #00353d 100%); color: white; }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="container py-4">
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="ti ti-circle-check me-2"></i><?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h4 class="mb-0"><i class="ti ti-edit me-2"></i> Edit Budget Item <small class="ms-2 opacity-75">#<?= htmlspecialchars($item['po_number']) ?></small></h4>
                        <div class="d-flex align-items-center gap-2">
                            <a href="index.php" class="btn btn-light btn-sm"><i class="ti ti-arrow-left me-1"></i> Back</a>
                            <button type="submit" form="budgetForm" class="btn btn-light btn-sm">
                                <i class="ti ti-device-floppy me-1"></i> Update Budget Item
                            </button>
                        </div>
                    </div>

                    <div class="card-body">

                        <form method="POST" id="budgetForm" enctype="multipart/form-data">

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

                                <div class="col-md-6">

                                    <label class="form-label">Associated EPOS Staff *</label>

                                    <select name="associated_epos_staff" class="form-select" required id="staff_select">

                                        <option value="">Select Staff Member</option>

                                        <!-- Staff will be populated by JavaScript -->

                                    </select>

                                </div>

                                <div class="col-md-6">

                                    <label class="form-label">Item Type</label>

                                    <select name="item_type" class="form-select" id="item_type_select">

                                        <option value="Reseller" <?= $item['item_type'] == 'Reseller' ? 'selected' : '' ?>>Reseller</option>

                                        <option value="Distributor" <?= $item['item_type'] == 'Distributor' ? 'selected' : '' ?>>Distributor</option>

                                        <option value="End User" <?= $item['item_type'] == 'End User' ? 'selected' : '' ?>>End User</option>

                                        <option value="Other" <?= $item['item_type'] == 'Other' ? 'selected' : '' ?>>Other</option>

                                    </select>

                                </div>

                            </div>

                            <div class="border rounded-2 bg-light p-3 mb-3" id="budget_allocation_panel" style="display:none;">

                                <div class="small text-muted mb-1"><i class="ti ti-chart-pie me-1"></i> Budget vs Item Type (year <?= (int) $planner_year_get ?>)</div>

                                <div id="budget_allocation_content" class="small">Loading allocation…</div>

                            </div>



                            <div class="row mb-3">

                                <div class="col-md-4">

                                    <label class="form-label">Vendor</label>

                                    <input type="text" name="vendor" id="vendor_input" class="form-control" value="<?= htmlspecialchars($item['vendor']) ?>" autocomplete="off">

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">External Vendor</label>

                                    <input type="text" name="external_vendor" id="external_vendor_input" class="form-control" list="external_vendor_datalist" value="<?= htmlspecialchars($item['external_vendor']) ?>" autocomplete="off">

                                    <datalist id="external_vendor_datalist">
                                        <?php foreach ($external_vendor_options as $ev): ?>
                                        <option value="<?= htmlspecialchars($ev['field_label'] ?: $ev['field_value']) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>

                                </div>

                                <div class="col-md-4">

                                    <label class="form-label">Vendor Contact</label>

                                    <input type="text" name="vendor_contact" class="form-control" value="<?= htmlspecialchars($item['vendor_contact']) ?>">

                                </div>

                            </div>

                            <div class="row mb-3" id="partner_history_row" style="display:none;">

                                <div class="col-12">

                                    <div class="card border-0 bg-light">

                                        <div class="card-body py-2">

                                            <h6 class="small fw-semibold mb-2"><i class="ti ti-history me-1"></i> Partner spend history (this region)</h6>

                                            <div id="partner_history_content" class="small text-muted">Loading…</div>

                                        </div>

                                    </div>

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

                            <div class="row mb-3">

                                <div class="col-12">

                                    <div class="form-check">

                                        <input type="checkbox" name="budget_accrual_approved" value="1" class="form-check-input" id="budget_accrual_approved"

                                            <?= ((int)($item['budget_accrual_approved'] ?? 1) === 1) ? 'checked' : '' ?>>

                                        <label class="form-check-label" for="budget_accrual_approved">Budget accrual approved</label>

                                        <small class="d-block text-muted mt-1">When checked, spend is counted against the activity dates only. Uncheck to count spend against the invoice date year when invoicing lands in a later budget year.</small>

                                    </div>

                                </div>

                            </div>



                            <div class="mb-3">

                                <label class="form-label">Activity Description</label>

                                <textarea name="activity_description" class="form-control" rows="3"><?= htmlspecialchars($item['activity_description']) ?></textarea>

                            </div>



                            <div class="mb-3">

                                <label class="form-label">Comments</label>

                                <textarea name="comments" class="form-control" rows="2"><?= htmlspecialchars($item['comments']) ?></textarea>

                            </div>



                            <!-- UPDATED: Path as dropdown -->

                            <div class="mb-3">

                                <label class="form-label">Path</label>

                                <select name="path" class="form-select">

                                    <option value="direct" <?= ($item['path'] ?? '') == 'direct' ? 'selected' : '' ?>>Direct</option>

                                    <option value="channel" <?= ($item['path'] ?? '') == 'channel' ? 'selected' : '' ?>>Channel</option>

                                    <option value="partner" <?= ($item['path'] ?? '') == 'partner' ? 'selected' : '' ?>>Partner</option>

                                </select>

                            </div>

                            <!-- File Attachments -->
                            <div class="mb-4">
                                <label class="form-label">File Attachments</label>
                                <div class="file-upload-area mb-3" onclick="document.getElementById('fileInputEdit').click()">
                                    <i class="ti ti-cloud-upload me-2"></i>Click or drag files here (PDF, DOC, XLS, JPG, PNG, TXT - max 10MB)
                                    <input type="file" name="uploaded_files[]" id="fileInputEdit" multiple class="d-none"
                                           accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt">
                                </div>
                                <?php if (!empty($attachments)): ?>
                                <div class="mt-3">
                                    <strong>Existing files:</strong>
                                    <?php foreach ($attachments as $att): ?>
                                    <div class="uploaded-file-item d-flex justify-content-between align-items-center">
                                        <span><i class="ti ti-file me-2"></i><?= htmlspecialchars($att['file_name']) ?></span>
                                        <div>
                                            <a href="download_attachment.php?id=<?= $att['id'] ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank"><i class="ti ti-download"></i></a>
                                            <a href="delete_attachment.php?id=<?= $att['id'] ?>&return_id=<?= $id ?>&return_edit=1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this attachment?');"><i class="ti ti-trash"></i></a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end action-buttons-edit">

                                <a href="index.php" class="btn btn-secondary me-md-2">Cancel</a>

                                <button type="submit" class="btn btn-primary">Update Budget Item</button>

                            </div>

                        </form>

                    </div>

                </div>



                <!-- Item History -->

                <div class="card mt-4">

                    <div class="card-header">
                        <h6 class="mb-0"><i class="ti ti-history me-2"></i> Item History</h6>
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



    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

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

        const __plannerYear = <?= (int) $planner_year_get ?>;
        const __excludeItemId = <?= (int) $id ?>;

        function escapeHtmlGlobal(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        async function refreshExternalVendorDatalist(region) {
            const dl = document.getElementById('external_vendor_datalist');
            if (!dl || !region) return;
            try {
                const res = await fetch('get_form_options.php?region_group=' + encodeURIComponent(region) + '&country=');
                const data = await res.json();
                const opts = data.external_vendors || [];
                dl.innerHTML = '';
                opts.forEach(function (o) {
                    const op = document.createElement('option');
                    op.value = o.field_label || o.field_value || '';
                    dl.appendChild(op);
                });
            } catch (e) {
                console.error(e);
            }
        }

        async function refreshBudgetAllocation() {
            const panel = document.getElementById('budget_allocation_panel');
            const content = document.getElementById('budget_allocation_content');
            const region = document.getElementById('region_select') ? document.getElementById('region_select').value : '';
            const itemTypeEl = document.getElementById('item_type_select');
            const itemType = itemTypeEl ? itemTypeEl.value : '';
            if (!panel || !content) return;
            if (!region || !itemType) {
                panel.style.display = 'none';
                return;
            }
            try {
                const res = await fetch('budget_planner_api.php?region=' + encodeURIComponent(region) + '&year=' + encodeURIComponent(__plannerYear));
                const data = await res.json();
                if (data.error) {
                    content.innerHTML = '<span class="text-danger">' + escapeHtmlGlobal(data.error) + '</span>';
                    panel.style.display = 'block';
                    return;
                }
                const types = data.types || [];
                let row = null;
                for (let i = 0; i < types.length; i++) {
                    if (types[i].item_type === itemType) { row = types[i]; break; }
                }
                if (!row) {
                    panel.style.display = 'none';
                    return;
                }
                const ccy = data.currency || '';
                const fmt = function (n) {
                    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                };
                let html = '<strong>Cap</strong> ' + ccy + ' ' + fmt(row.cap) +
                    ' &middot; <strong>Spent (this type)</strong> ' + ccy + ' ' + fmt(row.spent) +
                    ' &middot; <strong>Remaining</strong> <span class="' + (row.remaining < 0 ? 'text-danger' : 'text-success') + ' fw-semibold">' + ccy + ' ' + fmt(row.remaining) + '</span>';
                if (data.unspecified_spent > 0.01) {
                    html += '<div class="mt-1 text-muted">Some spend has no Item Type: ' + ccy + ' ' + fmt(data.unspecified_spent) + '</div>';
                }
                content.innerHTML = html;
                panel.style.display = 'block';
            } catch (e) {
                content.textContent = 'Could not load budget planner.';
                panel.style.display = 'block';
            }
        }

        async function refreshPartnerHistory() {
            const row = document.getElementById('partner_history_row');
            const content = document.getElementById('partner_history_content');
            const regionEl = document.getElementById('region_select');
            const vendorEl = document.getElementById('vendor_input');
            const extEl = document.getElementById('external_vendor_input');
            if (!row || !content) return;
            const region = regionEl ? regionEl.value : '';
            const vendor = vendorEl ? vendorEl.value.trim() : '';
            const ext = extEl ? extEl.value.trim() : '';
            if (!region || (!vendor && !ext)) {
                row.style.display = 'none';
                return;
            }
            try {
                let url = 'partner_spend_history.php?region=' + encodeURIComponent(region) +
                    '&vendor=' + encodeURIComponent(vendor) +
                    '&external_vendor=' + encodeURIComponent(ext) +
                    '&exclude_id=' + __excludeItemId;
                const res = await fetch(url);
                const data = await res.json();
                const items = data.items || [];
                if (items.length === 0) {
                    content.innerHTML = '<span class="text-muted">No other matching spend found for this partner in this region.</span>';
                    row.style.display = 'block';
                    return;
                }
                let html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr><th>PO</th><th>Activity</th><th class="text-end">Amount</th><th>Status</th><th>Type</th><th>Start</th></tr></thead><tbody>';
                items.forEach(function (it) {
                    const title = (it.activity_title || '').length > 45 ? (it.activity_title || '').substring(0, 45) + '\u2026' : (it.activity_title || '');
                    html += '<tr><td>' + escapeHtmlGlobal(it.po_number) + '</td><td>' + escapeHtmlGlobal(title) + '</td><td class="text-end">' +
                        escapeHtmlGlobal(it.currency) + ' ' + escapeHtmlGlobal(String(it.amount_requested)) + '</td><td>' +
                        escapeHtmlGlobal(it.status) + '</td><td>' + escapeHtmlGlobal(it.item_type) + '</td><td>' +
                        escapeHtmlGlobal((it.start_date || '').substring(0, 10)) + '</td></tr>';
                });
                html += '</tbody></table></div>';
                content.innerHTML = html;
                row.style.display = 'block';
            } catch (e) {
                content.textContent = 'Could not load partner history.';
                row.style.display = 'block';
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

                refreshExternalVendorDatalist(region);

                refreshBudgetAllocation();

                refreshPartnerHistory();

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

                refreshExternalVendorDatalist(currentRegion);

                setTimeout(function () {

                    refreshBudgetAllocation();

                    refreshPartnerHistory();

                }, 500);

            }

            const itemTypeSel = document.getElementById('item_type_select');

            if (itemTypeSel) {

                itemTypeSel.addEventListener('change', function () { refreshBudgetAllocation(); });

            }

            const vInp = document.getElementById('vendor_input');

            if (vInp) {

                vInp.addEventListener('blur', function () { setTimeout(refreshPartnerHistory, 300); });

            }

            const eInp = document.getElementById('external_vendor_input');

            if (eInp) {

                eInp.addEventListener('blur', function () { setTimeout(refreshPartnerHistory, 300); });

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