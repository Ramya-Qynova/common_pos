import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import './ManagementScreens.css';

export function ReportsScreen() {
  const { session } = useAuth();
  const [activeTab, setActiveTab] = useState('summary');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [dateRange, setDateRange] = useState('month');
  const [reloadKey, setReloadKey] = useState(0);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleRefresh = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadReportData = async () => {
      try {
        const queryParams = new URLSearchParams();
        const now = new Date();

        if (dateRange === 'today') {
          const todayStr = now.toISOString().slice(0, 10);
          queryParams.set('from', `${todayStr} 00:00:00`);
          queryParams.set('to', `${todayStr} 23:59:59`);
        } else if (dateRange === 'week') {
          const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
          queryParams.set('from', weekAgo.toISOString().slice(0, 19).replace('T', ' '));
          queryParams.set('to', now.toISOString().slice(0, 19).replace('T', ' '));
        } else if (dateRange === 'month') {
          const monthAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
          queryParams.set('from', monthAgo.toISOString().slice(0, 19).replace('T', ' '));
          queryParams.set('to', now.toISOString().slice(0, 19).replace('T', ' '));
        }

        let endpoint = '/reports/summary';
        if (activeTab === 'pl') {
          endpoint = '/reports/profit-loss';
        } else if (activeTab === 'tax') {
          endpoint = '/reports/tax-breakdown';
        } else if (activeTab === 'inventory') {
          endpoint = '/reports/inventory-valuation';
        } else if (activeTab === 'cash') {
          endpoint = '/reports/cash-reconciliation';
        }

        const url = activeTab === 'inventory'
          ? `${apiBase}${endpoint}`
          : `${apiBase}${endpoint}?${queryParams.toString()}`;

        const res = await fetch(url, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const resJson = await res.json();
        if (!cancelled) {
          if (resJson.success) {
            setData(resJson.data);
          } else {
            setError(resJson.error?.message || t('common.error'));
          }
          setLoading(false);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err.message);
          setLoading(false);
        }
      }
    };

    loadReportData();

    return () => {
      cancelled = true;
    };
  }, [apiBase, session?.tokens?.access_token, activeTab, dateRange, reloadKey]);

  const summaryMetrics = [
    {
      key: 'gross_sales',
      label: t('reports.metric.gross_sales'),
      value: data?.gross_sales || '0.0000',
      type: 'primary',
    },
    {
      key: 'net_sales',
      label: t('reports.metric.net_sales'),
      value: data?.net_sales || '0.0000',
      type: 'success',
    },
    {
      key: 'tax_collected',
      label: t('reports.metric.tax_collected'),
      value: data?.tax_collected || data?.tax_total || '0.0000',
      type: 'neutral',
    },
    {
      key: 'cogs',
      label: t('reports.metric.cogs'),
      value: data?.cogs || '0.0000',
      type: 'warning',
    },
    {
      key: 'gross_profit',
      label: t('reports.metric.gross_profit'),
      value: data?.gross_profit || '0.0000',
      type: 'success',
    },
    {
      key: 'stock_valuation',
      label: t('reports.metric.stock_valuation'),
      value: data?.stock_valuation || '0.0000',
      type: 'info',
    },
    {
      key: 'outstanding',
      label: t('reports.metric.outstanding'),
      value: data?.outstanding?.customer || data?.outstanding_balance || '0.0000',
      type: 'danger',
    },
  ];

  const plMetrics = [
    {
      key: 'pl_gross_sales',
      label: t('reports.pl.gross_sales'),
      value: data?.gross_sales || '0.0000',
      type: 'primary',
    },
    {
      key: 'pl_discounts',
      label: t('reports.pl.discounts'),
      value: data?.discounts || '0.0000',
      type: 'warning',
    },
    {
      key: 'pl_returns',
      label: t('reports.pl.returns'),
      value: data?.returns || '0.0000',
      type: 'warning',
    },
    {
      key: 'pl_net_sales',
      label: t('reports.pl.net_sales'),
      value: data?.net_sales || '0.0000',
      type: 'success',
    },
    {
      key: 'pl_cogs',
      label: t('reports.pl.cogs'),
      value: data?.cogs || '0.0000',
      type: 'warning',
    },
    {
      key: 'pl_gross_profit',
      label: t('reports.pl.gross_profit'),
      value: data?.gross_profit || '0.0000',
      type: 'success',
    },
    {
      key: 'pl_expenses',
      label: t('reports.pl.expenses'),
      value: data?.operating_expenses || '0.0000',
      type: 'danger',
    },
    {
      key: 'pl_net_profit',
      label: t('reports.pl.net_profit'),
      value: data?.net_profit || '0.0000',
      type: 'success',
    },
  ];

  const invSummaryMetrics = [
    {
      key: 'inv_total_skus',
      label: t('reports.inv.total_skus'),
      value: String(data?.summary?.total_skus ?? 0),
      type: 'primary',
    },
    {
      key: 'inv_total_units',
      label: t('reports.inv.total_units'),
      value: data?.summary?.total_quantity || '0.0000',
      type: 'info',
    },
    {
      key: 'inv_total_valuation',
      label: t('reports.inv.total_valuation'),
      value: data?.summary?.total_valuation || '0.0000',
      type: 'success',
    },
    {
      key: 'inv_low_stock',
      label: t('reports.inv.low_stock'),
      value: String(data?.summary?.low_stock_count ?? 0),
      type: 'danger',
    },
  ];

  return (
    <div className="management-page">
      <div className="page-header">
        <h2>{t('reports.title')}</h2>
        <button className="btn-secondary" onClick={handleRefresh}>
          {t('reports.refresh')}
        </button>
      </div>

      <div className="report-tabs">
        <button
          className={`report-tab-btn ${activeTab === 'summary' ? 'active' : ''}`}
          onClick={() => {
            setActiveTab('summary');
            setLoading(true);
          }}
        >
          {t('reports.tab.summary')}
        </button>
        <button
          className={`report-tab-btn ${activeTab === 'pl' ? 'active' : ''}`}
          onClick={() => {
            setActiveTab('pl');
            setLoading(true);
          }}
        >
          {t('reports.tab.pl')}
        </button>
        <button
          className={`report-tab-btn ${activeTab === 'tax' ? 'active' : ''}`}
          onClick={() => {
            setActiveTab('tax');
            setLoading(true);
          }}
        >
          {t('reports.tab.tax')}
        </button>
        <button
          className={`report-tab-btn ${activeTab === 'inventory' ? 'active' : ''}`}
          onClick={() => {
            setActiveTab('inventory');
            setLoading(true);
          }}
        >
          {t('reports.tab.inventory')}
        </button>
        <button
          className={`report-tab-btn ${activeTab === 'cash' ? 'active' : ''}`}
          onClick={() => {
            setActiveTab('cash');
            setLoading(true);
          }}
        >
          {t('reports.tab.cash')}
        </button>
      </div>

      {activeTab !== 'inventory' && (
        <div className="filter-bar range-filter-bar">
          <button
            className={`btn-pill ${dateRange === 'today' ? 'active' : ''}`}
            onClick={() => {
              setDateRange('today');
              setLoading(true);
            }}
          >
            {t('reports.range.today')}
          </button>
          <button
            className={`btn-pill ${dateRange === 'week' ? 'active' : ''}`}
            onClick={() => {
              setDateRange('week');
              setLoading(true);
            }}
          >
            {t('reports.range.week')}
          </button>
          <button
            className={`btn-pill ${dateRange === 'month' ? 'active' : ''}`}
            onClick={() => {
              setDateRange('month');
              setLoading(true);
            }}
          >
            {t('reports.range.month')}
          </button>
          <button
            className={`btn-pill ${dateRange === 'all' ? 'active' : ''}`}
            onClick={() => {
              setDateRange('all');
              setLoading(true);
            }}
          >
            {t('reports.range.all')}
          </button>
        </div>
      )}

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : (
        <div className="dashboard-content">
          {activeTab === 'summary' && (
            <div className="metrics-grid">
              {summaryMetrics.map((m) => (
                <div key={m.key} className={`metric-card ${m.type}`}>
                  <div className="metric-label">{m.label}</div>
                  <div className="metric-value font-mono">{m.value}</div>
                </div>
              ))}
            </div>
          )}

          {activeTab === 'pl' && (
            <div>
              <div className="metrics-grid">
                {plMetrics.map((m) => (
                  <div key={m.key} className={`metric-card ${m.type}`}>
                    <div className="metric-label">{m.label}</div>
                    <div className="metric-value font-mono">{m.value}</div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {activeTab === 'tax' && (
            <div className="table-responsive">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('reports.tax.col.code')}</th>
                    <th>{t('reports.tax.col.taxable')}</th>
                    <th>{t('reports.tax.col.collected')}</th>
                    <th>{t('reports.tax.col.refunded')}</th>
                    <th>{t('reports.tax.col.net')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.components && data.components.length > 0 ? (
                    data.components.map((c) => (
                      <tr key={c.tax_code}>
                        <td><strong>{c.tax_code}</strong></td>
                        <td className="font-mono">{c.taxable_amount}</td>
                        <td className="font-mono">{c.collected_amount}</td>
                        <td className="font-mono">{c.refunded_amount}</td>
                        <td className="font-mono font-bold">{c.net_amount}</td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan="5" className="empty-message">
                        {t('reports.no_data')}
                      </td>
                    </tr>
                  )}
                  {data?.totals && (
                    <tr className="totals-row font-bold">
                      <td>{t('reports.tax.total')}</td>
                      <td className="font-mono">{data.totals.taxable_amount}</td>
                      <td className="font-mono">{data.totals.collected_amount}</td>
                      <td className="font-mono">{data.totals.refunded_amount}</td>
                      <td className="font-mono">{data.totals.net_amount}</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'inventory' && (
            <div>
              <div className="metrics-grid">
                {invSummaryMetrics.map((m) => (
                  <div key={m.key} className={`metric-card ${m.type}`}>
                    <div className="metric-label">{m.label}</div>
                    <div className="metric-value font-mono">{m.value}</div>
                  </div>
                ))}
              </div>

              <h3 className="report-section-title">{t('reports.inv.by_warehouse')}</h3>
              <div className="table-responsive" style={{ marginBottom: '2rem' }}>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('reports.inv.col.warehouse')}</th>
                      <th>{t('reports.inv.col.skus')}</th>
                      <th>{t('reports.inv.col.units')}</th>
                      <th>{t('reports.inv.col.value')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.by_warehouse && data.by_warehouse.length > 0 ? (
                      data.by_warehouse.map((w) => (
                        <tr key={w.warehouse_id}>
                          <td><strong>{w.warehouse_name}</strong></td>
                          <td>{w.sku_count}</td>
                          <td className="font-mono">{w.total_units}</td>
                          <td className="font-mono font-bold">{w.total_valuation}</td>
                        </tr>
                      ))
                    ) : (
                      <tr>
                        <td colSpan="4" className="empty-message">
                          {t('reports.no_data')}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>

              <h3 className="report-section-title">{t('reports.inv.by_category')}</h3>
              <div className="table-responsive">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('reports.inv.col.category')}</th>
                      <th>{t('reports.inv.col.skus')}</th>
                      <th>{t('reports.inv.col.units')}</th>
                      <th>{t('reports.inv.col.value')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data?.by_category && data.by_category.length > 0 ? (
                      data.by_category.map((c) => (
                        <tr key={c.category_id}>
                          <td><strong>{c.category_name}</strong></td>
                          <td>{c.sku_count}</td>
                          <td className="font-mono">{c.total_units}</td>
                          <td className="font-mono font-bold">{c.total_valuation}</td>
                        </tr>
                      ))
                    ) : (
                      <tr>
                        <td colSpan="4" className="empty-message">
                          {t('reports.no_data')}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {activeTab === 'cash' && (
            <div className="table-responsive">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('reports.cash.col.shift')}</th>
                    <th>{t('reports.cash.col.register')}</th>
                    <th>{t('reports.cash.col.cashier')}</th>
                    <th>{t('reports.cash.col.opening')}</th>
                    <th>{t('reports.cash.col.sales')}</th>
                    <th>{t('reports.cash.col.refunds')}</th>
                    <th>{t('reports.cash.col.expenses')}</th>
                    <th>{t('reports.cash.col.expected')}</th>
                    <th>{t('reports.cash.col.counted')}</th>
                    <th>{t('reports.cash.col.variance')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.shifts && data.shifts.length > 0 ? (
                    data.shifts.map((s) => (
                      <tr key={s.id}>
                        <td><strong>{s.id}</strong></td>
                        <td>{s.register_name}</td>
                        <td>{s.closed_by_name}</td>
                        <td className="font-mono">{s.opening_cash}</td>
                        <td className="font-mono">{s.cash_sales}</td>
                        <td className="font-mono">{s.cash_refunds}</td>
                        <td className="font-mono">{s.expenses}</td>
                        <td className="font-mono">{s.expected_cash}</td>
                        <td className="font-mono">{s.counted_cash}</td>
                        <td className="font-mono font-bold">{s.cash_difference}</td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan="10" className="empty-message">
                        {t('reports.no_data')}
                      </td>
                    </tr>
                  )}
                  {data?.totals && (
                    <tr className="totals-row font-bold">
                      <td colSpan="3">{t('reports.cash.total')}</td>
                      <td className="font-mono">{data.totals.opening_cash}</td>
                      <td className="font-mono">{data.totals.cash_sales}</td>
                      <td className="font-mono">{data.totals.cash_refunds}</td>
                      <td className="font-mono">{data.totals.expenses}</td>
                      <td className="font-mono">{data.totals.expected_cash}</td>
                      <td className="font-mono">{data.totals.counted_cash}</td>
                      <td className="font-mono">{data.totals.cash_difference}</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
