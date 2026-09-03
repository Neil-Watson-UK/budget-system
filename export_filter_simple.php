<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Budget Export with Filter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            min-height: 100vh;
        }
        
        /* Add padding top to account for fixed navbar */
        .main-container {
            padding-top: 80px;
            max-width: 800px;
            margin: 0 auto;
            padding-left: 20px;
            padding-right: 20px;
        }
        
        .export-card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        h1 { 
            color: #2c3e50; 
            padding-bottom: 15px;
            margin-bottom: 25px;
            border-bottom: 2px solid #e8eef4;
            font-weight: 600;
        }
        
        .filter-group { 
            margin-bottom: 25px; 
        }
        
        label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: #34495e;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        select, .form-select { 
            width: 100%; 
            padding: 12px 15px; 
            border: 2px solid #e8eef4; 
            border-radius: 8px; 
            font-size: 16px; 
            background-color: white;
            color: #2c3e50;
            transition: all 0.3s ease;
        }
        
        select:focus, .form-select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            outline: none;
        }
        
        .btn-primary, .btn-secondary {
            padding: 14px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            margin-bottom: 15px;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #2980b9 0%, #1c6ea4 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
            margin-bottom: 15px;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(149, 165, 166, 0.3);
        }
        
        .info-box { 
            background: linear-gradient(135deg, #e8f4fc 0%, #d6eaf8 100%);
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
            border-left: 4px solid #3498db;
        }
        
        .info-box p {
            margin: 0;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 30px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            margin: -40px -40px 30px -40px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .main-container {
                padding-top: 70px;
                padding-left: 15px;
                padding-right: 15px;
            }
            
            .export-card {
                padding: 25px;
            }
            
            .card-header {
                margin: -25px -25px 20px -25px;
                padding: 15px;
            }
        }
        
        /* Custom select arrow */
        select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 45px;
        }
        
        /* Loading animation for button */
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .page-title i {
            background: #3498db;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
    </style>
</head>
<body>
    <!-- Include the header navigation -->
    <?php require_once 'header.php'; ?>
    
    <div class="main-container">
        <div class="export-card">
            <div class="page-title">
                <i class="fas fa-filter"></i>
                <h1>Filtered Budget Export</h1>
            </div>
            
            <div class="info-box">
                <p><i class="fas fa-info-circle me-2"></i>Select region, year, or status to filter the export, or leave as "All" to export everything. Year filters by start date or entry creation date.</p>
            </div>
            
            <form action="budget_export_filtered.php" method="get">
                <div class="filter-group">
                    <label for="region"><i class="fas fa-globe-americas me-1"></i>Region:</label>
                    <select id="region" name="region" class="form-select">
                        <option value="">All Regions</option>
                        <option value="AMER">AMER (Americas)</option>
                        <option value="DACH">DACH (Germany, Austria, Switzerland)</option>
                        <option value="UKI">UKI (UK & Ireland)</option>
                        <option value="ANZ">ANZ (Australia & New Zealand)</option>
                        <option value="NORD">NORD (Nordic Countries)</option>
                        <option value="FRANCE">FRANCE</option>
                        <option value="BNL">BNL (Belgium & Netherlands)</option>
                        <option value="EMEA_PARTNERS">EMEA Partners</option>
                        <option value="INDIA">INDIA</option>
                        <option value="APAC">APAC (Asia Pacific)</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="year"><i class="fas fa-calendar-alt me-1"></i>Year (optional):</label>
                    <select id="year" name="year" class="form-select">
                        <option value="">All Years</option>
                        <?php
                        $current_year = (int)date('Y');
                        for ($y = $current_year + 1; $y >= $current_year - 10; $y--) {
                            echo '<option value="' . $y . '">' . $y . '</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="status"><i class="fas fa-tag me-1"></i>Status (optional):</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="Planned">Planned</option>
                        <option value="Invoiced">Invoiced</option>
                        <option value="Executed">Executed</option>
                        <option value="Cancelled">Cancelled</option>
                        <option value="Allocated">Allocated</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download Filtered Export
                </button>
            </form>
            
            <div class="action-buttons">
                <a href="budget_export.php" class="btn btn-secondary">
                    <i class="fas fa-file-export"></i> Download Full Export (No Filters)
                </a>
                
                <a href="budget_import.php" class="btn btn-secondary">
                    <i class="fas fa-upload"></i> Back to Import
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Add focus effects to form elements
            const formElements = document.querySelectorAll('select');
            formElements.forEach(element => {
                element.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                    this.parentElement.style.transition = 'transform 0.3s ease';
                });
                
                element.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
            
            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const region = document.getElementById('region').value;
                const year = document.getElementById('year').value;
                const status = document.getElementById('status').value;
                
                if (!region && !year && !status) {
                    if (confirm('You haven\'t selected any filters. This will export ALL data. Continue?')) {
                        return true;
                    } else {
                        e.preventDefault();
                        return false;
                    }
                }
                return true;
            });
            
            // Add loading state to button
            const submitButton = document.querySelector('button[type="submit"]');
            if (submitButton) {
                form.addEventListener('submit', function() {
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing Export...';
                    submitButton.disabled = true;
                });
            }
        });
    </script>
</body>
</html>