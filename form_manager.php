<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is admin (you might want to add proper authentication)
$is_admin = true; // You should replace this with actual admin check

if (!$is_admin) {
    header("Location: index.php");
    exit;
}

$pdo = getDBConnection();

// Handle form submissions
if ($_POST) {
    if (isset($_POST['add_option'])) {
        $stmt = $pdo->prepare("
            INSERT INTO form_field_options (field_type, field_value, field_label, region_group, sort_order) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['field_type'],
            $_POST['field_value'],
            $_POST['field_label'],
            $_POST['region_group'] ?: null,
            $_POST['sort_order'] ?: 0
        ]);
    } 
    elseif (isset($_POST['update_option'])) {
        $stmt = $pdo->prepare("
            UPDATE form_field_options 
            SET field_value = ?, field_label = ?, region_group = ?, sort_order = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['field_value'],
            $_POST['field_label'],
            $_POST['region_group'] ?: null,
            $_POST['sort_order'] ?: 0,
            $_POST['is_active'] ? 1 : 0,
            $_POST['option_id']
        ]);
    }
    elseif (isset($_POST['delete_option'])) {
        $stmt = $pdo->prepare("DELETE FROM form_field_options WHERE id = ?");
        $stmt->execute([$_POST['option_id']]);
    }
    elseif (isset($_POST['bulk_import'])) {
        $field_type = $_POST['field_type'];
        $region_group = $_POST['region_group'] ?: null;
        $bulk_values = $_POST['bulk_values'];
        
        // Split by new lines and process each line
        $lines = explode("\n", $bulk_values);
        $imported_count = 0;
        $sort_order = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                // Use the same value for both field_value and field_label
                // You can modify this logic if you want to split code and description
                $field_value = $line;
                $field_label = $line;
                
                // Check if this value already exists
                $check_stmt = $pdo->prepare("
                    SELECT id FROM form_field_options 
                    WHERE field_type = ? AND field_value = ? AND (region_group = ? OR (region_group IS NULL AND ? IS NULL))
                ");
                $check_stmt->execute([$field_type, $field_value, $region_group, $region_group]);
                
                if (!$check_stmt->fetch()) {
                    // Insert new option
                    $stmt = $pdo->prepare("
                        INSERT INTO form_field_options (field_type, field_value, field_label, region_group, sort_order) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $field_type,
                        $field_value,
                        $field_label,
                        $region_group,
                        $sort_order++
                    ]);
                    $imported_count++;
                }
            }
        }
        
        $_SESSION['import_message'] = "Successfully imported $imported_count $field_type options!";
    }
    
    header("Location: form_manager.php?success=1");
    exit;
}

// Show import message if set
if (isset($_SESSION['import_message'])) {
    $import_message = $_SESSION['import_message'];
    unset($_SESSION['import_message']);
}

// Get all existing options
$field_types = [
    'accounting_code' => 'Accounting Codes',
    'sub_accounting_code' => 'Sub Accounting Codes',
    'staff' => 'Staff Members',
    'channel_vendor' => 'Channel Vendors',
    'external_vendor' => 'External Vendors',
    'budget_activity' => 'Budget Activities',
    'mdf_activity' => 'MDF Activities',
    'primary_campaign' => 'Primary Campaigns',
    'secondary_campaign' => 'Secondary Campaigns'
];

$region_groups = ['AMER', 'DACH', 'UKI', 'APAC', 'ANZ', 'NORD', 'BNL', 'FRANCE'];

// Get all options from database
$options = [];
foreach ($field_types as $type => $label) {
    $stmt = $pdo->prepare("
        SELECT * FROM form_field_options 
        WHERE field_type = ? 
        ORDER BY region_group IS NULL, region_group, sort_order, field_label
    ");
    $stmt->execute([$type]);
    $options[$type] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Field Manager - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 2rem;
        }
        
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #4361ee, #3f37c9);
            border: none;
        }
        
        .region-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .table-actions {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">
                <i class="fas fa-cog me-2"></i>Form Field Manager - <?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-5 pt-5">
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Settings updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>Settings updated successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($import_message)): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i><?= $import_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
        <div class="glass-card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Form Field Options Manager</h4>
            </div>
            
            <div class="card-body">
    <!-- Bulk Import Section -->
<div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark">
            <h6 class="mb-0"><i class="fas fa-upload me-2"></i>Bulk Import Options</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- Accounting Codes -->
                <div class="col-md-6 mb-4">
                    <h6>Import Accounting Codes</h6>
                    <form method="POST">
                        <input type="hidden" name="field_type" value="accounting_code">
                        <div class="mb-3">
                            <label class="form-label">Paste Accounting Codes (one per line):</label>
                            <textarea class="form-control" name="bulk_values" rows="6" placeholder="189800_OTHER SELLING EXPENSES&#10;191000_ADVERTISING CAMPAIGNS&#10;192000_DIRECT MAILING"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group (optional):</label>
                            <select class="form-select" name="region_group">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="bulk_import" class="btn btn-warning">
                            <i class="fas fa-upload me-2"></i>Import Accounting Codes
                        </button>
                    </form>
                </div>
                
                <!-- Sub Accounting Codes -->
                <div class="col-md-6 mb-4">
                    <h6>Import Sub Accounting Codes</h6>
                    <form method="POST">
                        <input type="hidden" name="field_type" value="sub_accounting_code">
                        <div class="mb-3">
                            <label class="form-label">Paste Sub Accounting Codes (one per line):</label>
                            <textarea class="form-control" name="bulk_values" rows="6" placeholder="181001_Exhibitions and Conventions&#10;182002_Seminars and training&#10;186001_Key Opinion Leader Activities"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group (optional):</label>
                            <select class="form-select" name="region_group">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="bulk_import" class="btn btn-warning">
                            <i class="fas fa-upload me-2"></i>Import Sub Accounting Codes
                        </button>
                    </form>
                </div>
                
                <!-- Budget Activities -->
                <div class="col-md-6 mb-4">
                    <h6>Import Budget Activities</h6>
                    <form method="POST">
                        <input type="hidden" name="field_type" value="budget_activity">
                        <div class="mb-3">
                            <label class="form-label">Paste Budget Activities (one per line):</label>
                            <textarea class="form-control" name="bulk_values" rows="6" placeholder="Advertising&#10;Digital&#10;Strategic Alliances"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group (optional):</label>
                            <select class="form-select" name="region_group">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="bulk_import" class="btn btn-warning">
                            <i class="fas fa-upload me-2"></i>Import Budget Activities
                        </button>
                    </form>
                </div>
                
                <!-- MDF Activities -->
                <div class="col-md-6 mb-4">
                    <h6>Import MDF Activities</h6>
                    <form method="POST">
                        <input type="hidden" name="field_type" value="mdf_activity">
                        <div class="mb-3">
                            <label class="form-label">Paste MDF Activities (one per line):</label>
                            <textarea class="form-control" name="bulk_values" rows="6" placeholder="Advertising - online&#10;Advertising - print&#10;Events - digital (webinar)"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group (optional):</label>
                            <select class="form-select" name="region_group">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" name="bulk_import" class="btn btn-warning">
                            <i class="fas fa-upload me-2"></i>Import MDF Activities
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- Staff Members Bulk Import -->
<div class="col-md-6 mb-4">
    <h6>Import Staff Members</h6>
    <form method="POST">
        <input type="hidden" name="field_type" value="staff">
        <div class="mb-3">
            <label class="form-label">Paste Staff Members (one per line):</label>
            <textarea class="form-control" name="bulk_values" rows="6" placeholder="john.doe@company.com|John Doe - Marketing Manager&#10;jane.smith@company.com|Jane Smith - Sales Director"></textarea>
            <div class="form-text">Format: email|Display Name - Role</div>
        </div>
        <div class="mb-3">
            <label class="form-label">Region Group (optional):</label>
            <select class="form-select" name="region_group">
                <option value="">Global</option>
                <?php foreach ($region_groups as $group): ?>
                    <option value="<?= $group ?>"><?= $group ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="bulk_import" class="btn btn-warning">
            <i class="fas fa-upload me-2"></i>Import Staff Members
        </button>
    </form>
</div>
<!-- Vendor Import Section -->
<div class="col-12 mb-4">
    <div class="card border-info">
        <div class="card-header bg-info text-white">
            <h6 class="mb-0"><i class="fas fa-file-excel me-2"></i>Import Vendors from Spreadsheet</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Import Channel Vendors</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="import_type" value="channel_vendor">
                        <div class="mb-3">
                            <label class="form-label">Upload CSV File:</label>
                            <input type="file" class="form-control" name="vendor_file" accept=".csv" required>
                            <div class="form-text">
                                CSV format: Vendor ID, Vendor Name, Country Code
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group (optional):</label>
                            <select class="form-select" name="region_group">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Region group will be auto-detected from country if left empty
                            </div>
                        </div>
                        <button type="submit" name="import_vendors" class="btn btn-info">
                            <i class="fas fa-file-import me-2"></i>Import Channel Vendors
                        </button>
                    </form>
                </div>
                
                <div class="col-md-6">
                    <h6>Import External Vendors</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="import_type" value="external_vendor">
                        <div class="mb-3">
                            <label class="form-label">Upload CSV File:</label>
                            <input type="file" class="form-control" name="vendor_file" accept=".csv" required>
                            <div class="form-text">
                                CSV format: Vendor ID, Vendor Name, Country Code
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group (optional):</label>
                            <select class="form-select" name="region_group">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Region group will be auto-detected from country if left empty
                            </div>
                        </div>
                        <button type="submit" name="import_vendors" class="btn btn-info">
                            <i class="fas fa-file-import me-2"></i>Import External Vendors
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- CSV Format Guide -->
            <div class="mt-4 p-3 bg-light rounded">
                <h6><i class="fas fa-info-circle me-2"></i>CSV Format Guide</h6>
                <p class="mb-2">Your CSV file should have this format (Vendor ID is required):</p>
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Vendor ID</th>
                            <th>Vendor Name</th>
                            <th>Country Code</th>
                            <th>Description (Optional)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>0011100000ScvKO</td>
                            <td>Media & Communications Ltd</td>
                            <td>UK</td>
                            <td>Primary media vendor</td>
                        </tr>
                        <tr>
                            <td>0011100000ScvKP</td>
                            <td>Nimans Ltd</td>
                            <td>UK</td>
                            <td>Technology distributor</td>
                        </tr>
                        <tr>
                            <td>0011100000ScvKQ</td>
                            <td>Misco Technologies</td>
                            <td>US</td>
                            <td>US operations</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mb-0"><strong>Vendor IDs:</strong> Use Salesforce Vendor IDs (e.g., 0011100000ScvKO)</p>
                <p class="mb-0"><strong>Country Codes:</strong> UK, US, CA, DE, FR, etc.</p>
            </div>
            
            <!-- Download Template -->
            <div class="mt-3">
                <a href="download_template.php?type=vendor_with_id" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-file-csv me-1"></i>Download CSV Template with Vendor IDs
                </a>
            </div>
        </div>
    </div>
</div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills mb-4" id="fieldTypeTabs" role="tablist">
        <!-- ... existing tab code ... -->
            <div class="card-body">
                <!-- Navigation Tabs -->
                <ul class="nav nav-pills mb-4" id="fieldTypeTabs" role="tablist">
                    <?php foreach ($field_types as $type => $label): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $type === 'accounting_code' ? 'active' : '' ?>" 
                                id="<?= $type ?>-tab" data-bs-toggle="pill" 
                                data-bs-target="#<?= $type ?>" type="button" role="tab">
                            <?= $label ?>
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content" id="fieldTypeTabsContent">
                    <?php foreach ($field_types as $type => $label): ?>
                    <div class="tab-pane fade <?= $type === 'accounting_code' ? 'show active' : '' ?>" 
                         id="<?= $type ?>" role="tabpanel">
                        
                        <!-- Add New Option Form -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-plus me-2"></i>Add New <?= $label ?></h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="row g-3">
                                    <input type="hidden" name="field_type" value="<?= $type ?>">
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Value</label>
                                        <input type="text" class="form-control" name="field_value" required 
                                               placeholder="Unique identifier">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Display Label</label>
                                        <input type="text" class="form-control" name="field_label" required 
                                               placeholder="Display text">
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">Region Group</label>
                                        <select class="form-select" name="region_group">
                                            <option value="">Global</option>
                                            <?php foreach ($region_groups as $group): ?>
                                                <option value="<?= $group ?>"><?= $group ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <label class="form-label">Sort Order</label>
                                        <input type="number" class="form-control" name="sort_order" value="0">
                                    </div>
                                    
                                    <div class="col-md-1">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="submit" name="add_option" class="btn btn-success w-100">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Existing Options Table -->
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Value</th>
                                        <th>Label</th>
                                        <th>Region</th>
                                        <th>Sort</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($options[$type] as $option): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($option['field_value']) ?></code></td>
                                        <td><?= htmlspecialchars($option['field_label']) ?></td>
                                        <td>
                                            <?php if ($option['region_group']): ?>
                                                <span class="badge region-badge bg-primary"><?= $option['region_group'] ?></span>
                                            <?php else: ?>
                                                <span class="badge region-badge bg-secondary">Global</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $option['sort_order'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $option['is_active'] ? 'success' : 'danger' ?>">
                                                <?= $option['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="table-actions">
                                            <!-- Edit Form -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="option_id" value="<?= $option['id'] ?>">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editOptionModal"
                                                            data-option-id="<?= $option['id'] ?>"
                                                            data-field-value="<?= htmlspecialchars($option['field_value']) ?>"
                                                            data-field-label="<?= htmlspecialchars($option['field_label']) ?>"
                                                            data-region-group="<?= $option['region_group'] ?>"
                                                            data-sort-order="<?= $option['sort_order'] ?>"
                                                            data-is-active="<?= $option['is_active'] ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="submit" name="delete_option" 
                                                            class="btn btn-outline-danger"
                                                            onclick="return confirm('Delete this option?')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($options[$type])): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                            No <?= strtolower($label) ?> found. Add your first one above.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Option Modal -->
    <div class="modal fade" id="editOptionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="option_id" id="editOptionId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Value</label>
                            <input type="text" class="form-control" name="field_value" id="editFieldValue" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Display Label</label>
                            <input type="text" class="form-control" name="field_label" id="editFieldLabel" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Region Group</label>
                            <select class="form-select" name="region_group" id="editRegionGroup">
                                <option value="">Global</option>
                                <?php foreach ($region_groups as $group): ?>
                                    <option value="<?= $group ?>"><?= $group ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" id="editSortOrder">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive" value="1" checked>
                                    <label class="form-check-label" for="editIsActive">Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_option" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit modal handler
        const editOptionModal = document.getElementById('editOptionModal');
        editOptionModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            document.getElementById('editOptionId').value = button.getAttribute('data-option-id');
            document.getElementById('editFieldValue').value = button.getAttribute('data-field-value');
            document.getElementById('editFieldLabel').value = button.getAttribute('data-field-label');
            document.getElementById('editRegionGroup').value = button.getAttribute('data-region-group') || '';
            document.getElementById('editSortOrder').value = button.getAttribute('data-sort-order');
            document.getElementById('editIsActive').checked = button.getAttribute('data-is-active') === '1';
        });
    </script>
</body>
</html>