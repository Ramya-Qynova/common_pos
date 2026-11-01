import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import './ManagementScreens.css';

export function CustomersScreen() {
  const { session } = useAuth();
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [showAddModal, setShowAddModal] = useState(false);
  const [selectedLedger, setSelectedLedger] = useState(null);
  const [ledgerEntries, setLedgerEntries] = useState([]);
  const [ledgerLoading, setLedgerLoading] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  // Form states
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [creditLimit, setCreditLimit] = useState('0.0000');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleSearch = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadCustomers = async () => {
      try {
        const queryParams = new URLSearchParams();
        if (searchTerm) queryParams.set('search', searchTerm);

        const res = await fetch(`${apiBase}/customers?${queryParams.toString()}`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setCustomers(data.data || []);
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

    loadCustomers();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiBase, session?.tokens?.access_token, reloadKey]);

  const handleCreateCustomer = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);

    try {
      const res = await fetch(`${apiBase}/customers`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({
          name,
          phone: phone || undefined,
          email: email || undefined,
          credit_limit: creditLimit,
        }),
      });

      const data = await res.json();
      if (data.success) {
        setShowAddModal(false);
        setName('');
        setPhone('');
        setEmail('');
        setCreditLimit('0.0000');
        setLoading(true);
        setReloadKey((k) => k + 1);
      } else {
        setFormError(data.error?.message || t('common.error'));
      }
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };

  const handleViewLedger = async (customer) => {
    setSelectedLedger(customer);
    setLedgerLoading(true);
    try {
      const res = await fetch(`${apiBase}/customers/${customer.id}/ledger`, {
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setLedgerEntries(data.data || []);
      }
    } catch {
      // Ignore
    } finally {
      setLedgerLoading(false);
    }
  };

  return (
    <div className="management-page">
      <div className="page-header">
        <h2>{t('customers.title')}</h2>
        <button className="btn-primary" onClick={() => setShowAddModal(true)}>
          + {t('customers.add')}
        </button>
      </div>

      <div className="filter-bar">
        <input
          type="text"
          className="search-input"
          placeholder={t('customers.search')}
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
        />
        <button className="btn-secondary" onClick={handleSearch}>
          {t('common.search')}
        </button>
      </div>

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : customers.length === 0 ? (
        <div className="state-panel empty">{t('customers.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('customers.col.code')}</th>
                <th>{t('customers.col.name')}</th>
                <th>{t('customers.col.phone')}</th>
                <th>{t('customers.col.credit_limit')}</th>
                <th>{t('customers.col.balance')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id}>
                  <td className="font-mono">{c.code}</td>
                  <td className="font-bold">{c.name}</td>
                  <td>{c.phone || '—'}</td>
                  <td>{c.credit_limit}</td>
                  <td className="font-bold">{c.current_balance}</td>
                  <td>
                    <button
                      className="btn-action"
                      onClick={() => handleViewLedger(c)}
                    >
                      {t('customers.ledger')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showAddModal && (
        <div className="modal-backdrop" onClick={() => setShowAddModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{t('customers.add')}</h3>
              <button
                className="btn-close"
                onClick={() => setShowAddModal(false)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <form onSubmit={handleCreateCustomer}>
              {formError && <div className="form-error">{formError}</div>}
              <div className="form-group">
                <label>{t('customers.form.name')} *</label>
                <input
                  type="text"
                  required
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>
              <div className="form-row">
                <div className="form-group">
                  <label>{t('customers.form.phone')}</label>
                  <input
                    type="text"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>{t('customers.form.email')}</label>
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </div>
              </div>
              <div className="form-group">
                <label>{t('customers.form.credit_limit')}</label>
                <input
                  type="text"
                  value={creditLimit}
                  onChange={(e) => setCreditLimit(e.target.value)}
                />
              </div>
              <div className="modal-actions">
                <button type="submit" className="btn-primary" disabled={saving}>
                  {saving ? t('common.saving') : t('customers.form.save')}
                </button>
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => setShowAddModal(false)}
                >
                  {t('common.cancel')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {selectedLedger && (
        <div className="modal-backdrop" onClick={() => setSelectedLedger(null)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>
                {t('customers.ledger.title')}: {selectedLedger.name}
              </h3>
              <button
                className="btn-close"
                onClick={() => setSelectedLedger(null)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <div className="ledger-container">
              {ledgerLoading ? (
                <div>{t('customers.ledger.loading')}</div>
              ) : ledgerEntries.length === 0 ? (
                <div>{t('customers.ledger.empty')}</div>
              ) : (
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('customers.ledger.date')}</th>
                      <th>{t('customers.ledger.ref')}</th>
                      <th>{t('customers.ledger.direction')}</th>
                      <th>{t('customers.ledger.amount')}</th>
                      <th>{t('customers.ledger.balance')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {ledgerEntries.map((e) => (
                      <tr key={e.id}>
                        <td>{new Date(e.occurred_at).toLocaleDateString()}</td>
                        <td>{e.source_type}</td>
                        <td className={`font-bold ${e.direction}`}>
                          {e.direction.toUpperCase()}
                        </td>
                        <td>{e.amount}</td>
                        <td>{e.balance_after}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
            <div className="modal-actions">
              <button
                className="btn-secondary"
                onClick={() => setSelectedLedger(null)}
              >
                {t('common.close')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
