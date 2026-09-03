// assets/BudgetDashboard.jsx
import React, { useState, useEffect } from 'react';

const BudgetDashboard = () => {
  const [regions, setRegions] = useState([
    { name: 'AMER', currency: 'USD', amount: 8033.76, percentage: 1.3, total: 600090.00 },
    { name: 'DACH', currency: 'EUR', amount: 2456.00, percentage: 0.6, total: 381292.00 },
    { name: 'UKI', currency: 'GBP', amount: 0, percentage: 3.2, total: 5487260.00 },
    { name: 'APAC', currency: 'USD', amount: 0, percentage: 0, total: 92498.00 },
    { name: 'ANZ', currency: 'AUD', amount: 568.76, percentage: 0.5, total: 116420.00 },
    { name: 'NORD', currency: 'EUR', amount: 47014.00, percentage: 27.2, total: 172733.00 },
    { name: 'BNL', currency: 'EUR', amount: 0, percentage: 0, total: 109937.00 },
    { name: 'FRANCE', currency: 'EUR', amount: 0, percentage: 0, total: 184158.00 },
    { name: 'EMEA_PARTNERS', currency: 'EUR', amount: 0, percentage: 0, total: 137790.00 },
    { name: 'INDIA', currency: 'RKR', amount: 0, percentage: 0, total: 183059.00 }
  ]);

  const formatCurrency = (amount, currency) => {
    const formatter = new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currency === 'USD' ? 'USD' : currency === 'EUR' ? 'EUR' : currency === 'GBP' ? 'GBP' : currency === 'AUD' ? 'AUD' : 'USD',
      minimumFractionDigits: 2
    });
    
    if (currency === 'AUD') {
      return `A${formatter.format(amount).replace('$', '')}`;
    }
    
    return formatter.format(amount);
  };

  const totalBudget = regions.reduce((sum, region) => sum + region.total, 0);
  const usedBudget = regions.reduce((sum, region) => sum + region.amount, 0);
  const utilizationPercentage = totalBudget > 0 ? (usedBudget / totalBudget) * 100 : 0;

  return (
    <div className="budget-dashboard">
      <header className="dashboard-header">
        <h1>Global Budget System 2026</h1>
        <div className="system-admin">
          System Admin Regional Views Reports
        </div>
      </header>

      <div className="dashboard-content">
        <div className="regions-grid">
          {regions.map((region, index) => (
            <div key={index} className="region-card">
              <div className="region-header">
                <span className="region-name">{region.name}</span>
                <span className="region-currency">{region.currency}</span>
              </div>
              <div className="region-amount">
                {formatCurrency(region.amount, region.currency)}
              </div>
              <div className="region-percentage">
                {region.percentage}%
              </div>
              <div className="region-total">
                {formatCurrency(region.total, region.currency)}
              </div>
              <div className="progress-bar">
                <div 
                  className="progress-fill" 
                  style={{width: `${Math.min(region.percentage, 100)}%`}}
                ></div>
              </div>
            </div>
          ))}
        </div>

        <div className="charts-section">
          <div className="chart-container">
            <h3>Budget Allocation by Region</h3>
            <div className="chart">
              {regions.map((region, index) => {
                const height = totalBudget > 0 ? (region.total / totalBudget) * 100 : 0;
                return (
                  <div key={index} className="chart-bar-container">
                    <div className="chart-bar-label">{region.name}</div>
                    <div className="chart-bar">
                      <div 
                        className="chart-bar-fill" 
                        style={{height: `${height}%`}}
                      ></div>
                    </div>
                    <div className="chart-bar-value">
                      {formatCurrency(region.total, region.currency)}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="chart-container">
            <h3>Budget Utilization</h3>
            <div className="pie-chart">
              <div className="pie-chart-svg">
                <svg viewBox="0 0 100 100">
                  <circle 
                    cx="50" 
                    cy="50" 
                    r="40" 
                    className="pie-background"
                  />
                  <circle 
                    cx="50" 
                    cy="50" 
                    r="40" 
                    className="pie-fill"
                    strokeDasharray={`${utilizationPercentage * 2.512} 251.2`}
                    transform="rotate(-90 50 50)"
                  />
                </svg>
                <div className="pie-chart-center">
                  <div className="pie-percentage">
                    {utilizationPercentage.toFixed(1)}%
                  </div>
                  <div className="pie-label">Utilized</div>
                </div>
              </div>
              <div className="pie-legend">
                <div className="legend-item">
                  <div className="legend-color utilized"></div>
                  <span>Utilized: {formatCurrency(usedBudget, 'USD')}</span>
                </div>
                <div className="legend-item">
                  <div className="legend-color remaining"></div>
                  <span>Remaining: {formatCurrency(totalBudget - usedBudget, 'USD')}</span>
                </div>
                <div className="legend-item">
                  <div className="legend-color total"></div>
                  <span>Total: {formatCurrency(totalBudget, 'USD')}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default BudgetDashboard;