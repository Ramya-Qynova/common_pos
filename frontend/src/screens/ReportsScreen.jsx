import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import './ManagementScreens.css';

export function ReportsScreen() {
  const { session } = useAuth();
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

    const loadSummary = async () => {
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

        const res = await fetch(`${apiBase}/reports/summary?${queryParams.toString()}`, {
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

    loadSummary();

    return () => {
      cancelled = true;
    };
  }, [apiBase, session?.tokens?.access_token, dateRange, reloadKey]);

  const metrics = [
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
      value: data?.tax_total || '0.0000',
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
      value: data?.outstanding_balance || '0.0000',
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

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : (
        <div className="dashboard-content">
          <div className="metrics-grid">
            {metrics.map((m) => (
              <div key={m.key} className={`metric-card ${m.type}`}>
                <div className="metric-label">{m.label}</div>
                <div className="metric-value font-mono">{m.value}</div>
              </div>
            ))}
          </div>
          <div className="reports-hint">
            <small>{t('reports.currency_hint')}</small>
          </div>
        </div>
      )}
    </div>
  );
}
