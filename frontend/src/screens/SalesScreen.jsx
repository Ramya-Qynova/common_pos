import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import { ReceiptModal } from './ReceiptModal';
import './ManagementScreens.css';

export function SalesScreen() {
  const { session } = useAuth();
  const [sales, setSales] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [selectedSale, setSelectedSale] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleSearch = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadSales = async () => {
      try {
        const queryParams = new URLSearchParams();
        if (searchTerm) queryParams.set('search', searchTerm);
        if (statusFilter) queryParams.set('status', statusFilter);

        const res = await fetch(`${apiBase}/sales?${queryParams.toString()}`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setSales(data.data || []);
          } else {
            setError(data.error?.message || t('common.error'));
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

    loadSales();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiBase, session?.tokens?.access_token, statusFilter, reloadKey]);

  const handleViewReceipt = async (saleId) => {
    try {
      const res = await fetch(`${apiBase}/sales/${saleId}`, {
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setSelectedSale(data.data);
      }
    } catch {
      // Ignore
    }
  };

  return (
    <div className="management-page">
      <div className="page-header">
        <h2>{t('sales.title')}</h2>
      </div>

      <div className="filter-bar">
        <input
          type="text"
          className="search-input"
          placeholder={t('sales.search')}
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
        />
        <select
          className="filter-select"
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setLoading(true);
          }}
        >
          <option value="">{t('sales.status.all')}</option>
          <option value="completed">{t('sales.status.completed')}</option>
          <option value="cancelled">{t('sales.status.cancelled')}</option>
        </select>
        <button className="btn-primary" onClick={handleSearch}>
          {t('common.search')}
        </button>
      </div>

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : sales.length === 0 ? (
        <div className="state-panel empty">{t('sales.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('sales.col.invoice')}</th>
                <th>{t('sales.col.date')}</th>
                <th>{t('sales.col.customer')}</th>
                <th>{t('sales.col.cashier')}</th>
                <th>{t('sales.col.total')}</th>
                <th>{t('sales.col.status')}</th>
                <th>{t('sales.col.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {sales.map((sale) => (
                <tr key={sale.id}>
                  <td className="font-mono">{sale.invoice_number || '—'}</td>
                  <td>{new Date(sale.created_at).toLocaleString()}</td>
                  <td>{sale.customer ? sale.customer.name : t('sales.customer.walk_in')}</td>
                  <td>{sale.cashier}</td>
                  <td className="font-bold">{sale.grand_total}</td>
                  <td>
                    <span className={`badge-status ${sale.status}`}>{sale.status}</span>
                  </td>
                  <td>
                    <button
                      className="btn-action"
                      onClick={() => handleViewReceipt(sale.id)}
                    >
                      {t('sales.action.view')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {selectedSale && (
        <ReceiptModal sale={selectedSale} onClose={() => setSelectedSale(null)} />
      )}
    </div>
  );
}
