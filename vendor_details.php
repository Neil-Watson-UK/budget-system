<?php
// vendor_details.php - AJAX endpoint for vendor details
require_once __DIR__ . '/config.php';

if (isset($_POST['vendor_id'])) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
            DB_USER, 
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
        $stmt->execute([$_POST['vendor_id']]);
        $vendor = $stmt->fetch();
        
        if ($vendor) {
            // Group fields logically
            $fieldGroups = [
                'Basic Information' => [
                    'vendor_name' => 'Vendor Name',
                    'salesforce_id' => 'Salesforce ID',
                    'account_type' => 'Account Type',
                    'Type_value__c' => 'Type Value',
                    'AMPLIFY_Level__c' => 'AMPLIFY Level',
                    'Account_Status__c' => 'Account Status',
                    'region' => 'Region'
                ],
                'Contact & Owner' => [
                    'Owner_Full_Name__c' => 'Owner Name',
                    'Email_of_Account_Owner__c' => 'Owner Email',
                    'Phone' => 'Phone',
                    'Website' => 'Website',
                    'Industry' => 'Industry'
                ],
                'Address Information' => [
                    'BillingStreet' => 'Street',
                    'BillingCity' => 'City',
                    'BillingState' => 'State',
                    'BillingPostalCode' => 'Postal Code',
                    'BillingCountry' => 'Country',
                    'BillingCountryCode' => 'Country Code'
                ],
                'Financial Details' => [
                    'Customer_Number__c' => 'Customer Number',
                    'VAT_No__c' => 'VAT Number',
                    'Company_Registration_Number__c' => 'Company Reg. No.',
                    'CurrencyIsoCode' => 'Currency',
                    'Controlling_Legal_Entity__c' => 'Legal Entity'
                ],
                'Salesforce Details' => [
                    'RecordTypeId' => 'Record Type ID',
                    'OwnerId' => 'Owner ID',
                    'SalesMarket__c' => 'Sales Market',
                    'IsCustomerPortal' => 'Customer Portal',
                    'ChannelProgramName' => 'Channel Program',
                    'ChannelProgramLevelName' => 'Program Level'
                ]
            ];
            
            echo '<div class="vendor-details">';
            
            foreach ($fieldGroups as $groupName => $fields) {
                $hasData = false;
                foreach ($fields as $field => $label) {
                    if (!empty($vendor[$field])) {
                        $hasData = true;
                        break;
                    }
                }
                
                if ($hasData) {
                    echo '<h6 class="mt-3 border-bottom pb-2 text-primary">' . $groupName . '</h6>';
                    echo '<div class="row">';
                    foreach ($fields as $field => $label) {
                        $value = $vendor[$field] ?? '';
                        if (!empty($value)) {
                            // Format boolean values
                            if ($field === 'IsCustomerPortal') {
                                $value = $value ? 'Yes' : 'No';
                            }
                            
                            echo '<div class="col-md-6 mb-2">';
                            echo '<strong>' . $label . ':</strong><br>';
                            echo '<span class="text-muted">' . htmlspecialchars($value) . '</span>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                }
            }
            
            // Last updated info
            echo '<div class="mt-4 pt-3 border-top">';
            echo '<small class="text-muted">';
            if (!empty($vendor['updated_at'])) {
                echo 'Last updated: ' . date('F j, Y, g:i a', strtotime($vendor['updated_at']));
            } else if (!empty($vendor['created_at'])) {
                echo 'Created: ' . date('F j, Y', strtotime($vendor['created_at']));
            }
            echo '</small>';
            echo '</div>';
            
            echo '</div>';
        } else {
            echo '<div class="alert alert-warning">Vendor not found</div>';
        }
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
?>