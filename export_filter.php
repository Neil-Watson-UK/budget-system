<?php
// export_filter_simple.php - Simple filter interface
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Budget Export with Filter</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
        .filter-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #495057; }
        select { width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 16px; }
        button { padding: 12px 30px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; width: 100%; margin-top: 10px; }
        button:hover { background: #0056b3; }
        .btn-secondary { background: #6c757d; margin-top: 10px; }
        .btn-secondary:hover { background: #545b62; }
        .info-box { background: #e7f3ff; padding: 15px; border-radius: 6px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Filtered Budget Export</h1>
        
        <div class="info-box">
            <p>Select a region to filter the export, or leave as "All Regions" to export everything.</p>
        </div>
        
        <form action="budget_export_filtered.php" method="get">
            <div class="filter-group">
                <label for="region">Region:</label>
                <select id="region" name="region">
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
                <label for="status">Status (optional):</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="Planned">Planned</option>
                    <option value="Invoiced">Invoiced</option>
                    <option value="Executed">Executed</option>
                    <option value="Cancelled">Cancelled</option>
                    <option value="Allocated">Allocated</option>
                </select>
            </div>
            
            <button type="submit">📥 Download Filtered Export</button>
        </form>
        
        <a href="budget_export.php">
            <button class="btn-secondary">📥 Download Full Export (No Filters)</button>
        </a>
        
        <a href="budget_import.php" style="text-decoration: none;">
            <button class="btn-secondary">📤 Back to Import</button>
        </a>
    </div>
</body>
</html>