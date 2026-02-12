<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currency Conversion Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            padding: 20px 0;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-role {
            background-color: rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .dashboard-title {
            text-align: center;
            padding: 20px 0;
            font-size: 28px;
            font-weight: 600;
        }
        
        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .last-updated {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .rates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .rate-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid #3498db;
        }
        
        .rate-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }
        
        .currency-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .currency-symbol {
            font-size: 16px;
            color: #7f8c8d;
            margin-bottom: 15px;
        }
        
        .exchange-rate {
            font-size: 24px;
            font-weight: 700;
            color: #27ae60;
            margin-bottom: 5px;
        }
        
        .base-currency {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .default-rate {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 5px;
        }
        
        .base-info {
            text-align: center;
            margin-top: 30px;
            padding: 15px;
            background-color: #e8f4fc;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .section-divider {
            height: 1px;
            background: linear-gradient(to right, transparent, #3498db, transparent);
            margin: 30px 0;
        }
        
        .budget-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .budget-table th {
            background-color: #2c3e50;
            color: white;
            text-align: left;
            padding: 12px 15px;
        }
        
        .budget-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        
        .budget-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .budget-table tr:hover {
            background-color: #e8f4fc;
        }
        
        .budget-amount {
            font-weight: 600;
        }
        
        .update-section {
            background-color: #e8f4fc;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 30px 0;
        }
        
        .update-btn {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .update-btn:hover {
            background: linear-gradient(135deg, #2980b9, #1c638e);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .back-button {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-button:hover {
            background-color: #2980b9;
        }
        
        .currency-group {
            margin-bottom: 30px;
        }
        
        .group-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #2c3e50;
            padding-bottom: 8px;
            border-bottom: 2px solid #3498db;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .rates-grid {
                grid-template-columns: 1fr;
            }
            
            .budget-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-exchange-alt"></i>
                    CurrencyPro
                </div>
                <div class="user-info">
                    <div class="user-role">Admin</div>
                    <div>Welcome, Administrator</div>
                </div>
            </div>
        </header>
        
        <main>
            <h1 class="dashboard-title">Currency Conversion Dashboard</h1>
            <p class="subtitle">Current exchange rates with Euro (EUR) as base currency</p>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Exchange Rates</h2>
                    <div class="last-updated">Last updated: 2025-11-24</div>
                </div>
                
                <div class="base-info">
                    <strong>Base Currency:</strong> Euro (EUR) | 
                    <strong>Target Currency Rate:</strong> 1 Euro = 7 Target Currency
                </div>
                
                <div class="currency-group">
                    <h3 class="group-title">Major Currencies</h3>
                    <div class="rates-grid">
                        <div class="rate-card">
                            <div class="currency-name">US Dollar</div>
                            <div class="currency-symbol">USD ($)</div>
                            <div class="exchange-rate">1.0800</div>
                            <div class="base-currency">1 EUR = 1.0800 USD</div>
                            <div class="default-rate">Default: 1.0800</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">British Pound</div>
                            <div class="currency-symbol">GBP (£)</div>
                            <div class="exchange-rate">0.8500</div>
                            <div class="base-currency">1 EUR = 0.8500 GBP</div>
                            <div class="default-rate">Default: 0.8500</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">Australian Dollar</div>
                            <div class="currency-symbol">AUD (A$)</div>
                            <div class="exchange-rate">1.6500</div>
                            <div class="base-currency">1 EUR = 1.6500 AUD</div>
                            <div class="default-rate">Default: 1.6500</div>
                        </div>
                    </div>
                </div>
                
                <div class="currency-group">
                    <h3 class="group-title">American & Asian Currencies</h3>
                    <div class="rates-grid">
                        <div class="rate-card">
                            <div class="currency-name">Canadian Dollar</div>
                            <div class="currency-symbol">CAD (C$)</div>
                            <div class="exchange-rate">1.4700</div>
                            <div class="base-currency">1 EUR = 1.4700 CAD</div>
                            <div class="default-rate">Default: 1.4700</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">Hong Kong Dollar</div>
                            <div class="currency-symbol">HKD (HK$)</div>
                            <div class="exchange-rate">8.4500</div>
                            <div class="base-currency">1 EUR = 8.4500 HKD</div>
                            <div class="default-rate">Default: 8.4500</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">Chinese Yuan</div>
                            <div class="currency-symbol">CNY (¥)</div>
                            <div class="exchange-rate">7.8000</div>
                            <div class="base-currency">1 EUR = 7.8000 CNY</div>
                            <div class="default-rate">Default: 7.8000</div>
                        </div>
                    </div>
                </div>
                
                <div class="currency-group">
                    <h3 class="group-title">European & Other Currencies</h3>
                    <div class="rates-grid">
                        <div class="rate-card">
                            <div class="currency-name">Swiss Franc</div>
                            <div class="currency-symbol">CHF</div>
                            <div class="exchange-rate">0.9500</div>
                            <div class="base-currency">1 EUR = 0.9500 CHF</div>
                            <div class="default-rate">Default: 0.9500</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">Singapore Dollar</div>
                            <div class="currency-symbol">SGD (S$)</div>
                            <div class="exchange-rate">1.4500</div>
                            <div class="base-currency">1 EUR = 1.4500 SGD</div>
                            <div class="default-rate">Default: 1.4500</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">Indian Rupee</div>
                            <div class="currency-symbol">INR (?)</div>
                            <div class="exchange-rate">90.0000</div>
                            <div class="base-currency">1 EUR = 90.0000 INR</div>
                            <div class="default-rate">Default: 90.0000</div>
                        </div>
                    </div>
                </div>
                
                <div class="currency-group">
                    <h3 class="group-title">Pacific Currencies</h3>
                    <div class="rates-grid">
                        <div class="rate-card">
                            <div class="currency-name">New Zealand Dollar</div>
                            <div class="currency-symbol">NZD (NZ$)</div>
                            <div class="exchange-rate">1.7800</div>
                            <div class="base-currency">1 EUR = 1.7800 NZD</div>
                            <div class="default-rate">Default: 1.7800</div>
                        </div>
                        
                        <div class="rate-card">
                            <div class="currency-name">Japanese Yen</div>
                            <div class="currency-symbol">JPY (¥)</div>
                            <div class="exchange-rate">160.0000</div>
                            <div class="base-currency">1 EUR = 160.0000 JPY</div>
                            <div class="default-rate">Default: 160.0000</div>
                        </div>
                    </div>
                </div>
                
                <div class="update-section">
                    <h3>Update All Conversion Rates</h3>
                    <p>Click the button below to update all currency conversion rates to the latest values.</p>
                    <button class="update-btn">
                        <i class="fas fa-sync-alt"></i>
                        Update All Rates
                    </button>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Regional Budgets in Local Currencies</h2>
                </div>
                
                <table class="budget-table">
                    <thead>
                        <tr>
                            <th>Region</th>
                            <th>Budget (EUR)</th>
                            <th>Local Currency</th>
                            <th>Budget (Local)</th>
                            <th>Conversion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>AMER</td>
                            <td class="budget-amount">€600,090.00</td>
                            <td>USD ($)</td>
                            <td class="budget-amount">$648,097.20</td>
                            <td>1 EUR = 1.0800 USD</td>
                        </tr>
                        <tr>
                            <td>DACH</td>
                            <td class="budget-amount">€381,292.00</td>
                            <td>EUR (€)</td>
                            <td class="budget-amount">€381,292.00</td>
                            <td>1 EUR = 1.0000 EUR</td>
                        </tr>
                        <tr>
                            <td>UKI</td>
                            <td class="budget-amount">€349,246.00</td>
                            <td>GBP (£)</td>
                            <td class="budget-amount">£296,859.10</td>
                            <td>1 EUR = 0.8500 GBP</td>
                        </tr>
                        <tr>
                            <td>APAC</td>
                            <td class="budget-amount">€92,498.00</td>
                            <td>USD ($)</td>
                            <td class="budget-amount">$99,897.84</td>
                            <td>1 EUR = 1.0800 USD</td>
                        </tr>
                        <tr>
                            <td>ANZ</td>
                            <td class="budget-amount">€116,420.00</td>
                            <td>AUD (A$)</td>
                            <td class="budget-amount">A$192,093.00</td>
                            <td>1 EUR = 1.6500 AUD</td>
                        </tr>
                        <tr>
                            <td>NORD</td>
                            <td class="budget-amount">€172,733.00</td>
                            <td>EUR (€)</td>
                            <td class="budget-amount">€172,733.00</td>
                            <td>1 EUR = 1.0000 EUR</td>
                        </tr>
                        <tr>
                            <td>BNL</td>
                            <td class="budget-amount">€109,937.00</td>
                            <td>EUR (€)</td>
                            <td class="budget-amount">€109,937.00</td>
                            <td>1 EUR = 1.0000 EUR</td>
                        </tr>
                        <tr>
                            <td>FRANCE</td>
                            <td class="budget-amount">€184,158.00</td>
                            <td>EUR (€)</td>
                            <td class="budget-amount">€184,158.00</td>
                            <td>1 EUR = 1.0000 EUR</td>
                        </tr>
                        <tr>
                            <td>EMEA PARTNERS</td>
                            <td class="budget-amount">€137,790.00</td>
                            <td>EUR (€)</td>
                            <td class="budget-amount">€137,790.00</td>
                            <td>1 EUR = 1.0000 EUR</td>
                        </tr>
                        <tr>
                            <td>INDIA</td>
                            <td class="budget-amount">€183,059.00</td>
                            <td>INR (?)</td>
                            <td class="budget-amount">?16,475,310.00</td>
                            <td>1 EUR = 90.0000 INR</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div style="text-align: center;">
                <a href="#" class="back-button">Back to Dashboard</a>
            </div>
        </main>
        
        <footer>
            <p>Currency Conversion Dashboard &copy; 2025 | For authorized personnel only</p>
        </footer>
    </div>

    <script>
        // Simple animation for the update button
        document.querySelector('.update-btn').addEventListener('click', function() {
            const btn = this;
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating Rates...';
            btn.disabled = true;
            
            // Simulate API call
            setTimeout(function() {
                btn.innerHTML = '<i class="fas fa-check"></i> Rates Updated!';
                
                setTimeout(function() {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }, 2000);
            }, 1500);
        });
        
        // Add subtle animation to rate cards when they come into view
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Apply initial styles and observe
        document.querySelectorAll('.rate-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s, transform 0.5s';
            observer.observe(card);
        });
    </script>
</body>
</html>