import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { useOutletContext } from 'react-router-dom';
import { t } from '../i18n';
import './ManagementScreens.css';

export function ReconciliationsScreen() {
  const { session } = useAuth();
  const config = useOutletContext();
  const [reconciliations, setReconciliations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  // Start stocktake modal
  const [showStartModal, setShowStartModal] = useState(false);
  const [warehouseId, setWarehouseId] = useState('');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  // Counts audit modal
  const [selectedAudit, setSelectedAudit] = useState(null);
  const [countInputs, setCountInputs] = useState({});

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleRefresh = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadReconciliations = async () => {
      try {
        const res = await fetch(`${apiBase}/stock-reconciliations`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setReconciliations(data.data || []);
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

    loadReconciliations();

    return () => {
      cancelled = true;
    };
  }, [apiBase, session?.tokens?.access_token, reloadKey]);

  const handleStartStocktake = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);

    try {
      const res = await fetch(`${apiBase}/stock-reconciliations`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({
          warehouse_id: Number(warehouseId),
        }),
      });

      const data = await res.json();
      if (data.success) {
        setShowStartModal(false);
        setWarehouseId('');
        handleRefresh();
      } else {
        setFormError(data.error?.message || t('common.error'));
      }
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };

  const openAuditModal = async (rec) => {
    try {
      const res = await fetch(`${apiBase}/stock-reconciliations/${rec.id}`, {
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setSelectedAudit(data.data);
        const inputs = {};
        (data.data.items || []).forEach((item) => {
          inputs[item.id] = item.counted_quantity ?? item.system_quantity;
        });
        setCountInputs(inputs);
      }
    } catch {
      // Ignore
    }
  };

  const handleSaveCounts = async () => {
    if (!selectedAudit) return;
    setSaving(true);
    try {
      const countsPayload = Object.entries(countInputs).map(([itemId, qty]) => ({
        item_id: Number(itemId),
        counted_quantity: qty,
      }));

      const res = await fetch(`${apiBase}/stock-reconciliations/${selectedAudit.id}/submit-counts`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({ counts: countsPayload }),
      });

      const data = await res.json();
      if (data.success) {
        openAuditModal(selectedAudit);
      }
    } catch {
      // Ignore
    } finally {
      setSaving(false);
    }
  };

  const handleCompleteReconciliation = async () => {
    if (!selectedAudit) return;
    setSaving(true);
    try {
      const res = await fetch(`${apiBase}/stock-reconciliations/${selectedAudit.id}/complete`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });

      const data = await res.json();
      if (data.success) {
        setSelectedAudit(null);
        handleRefresh();
      }
    } catch {
      // Ignore
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="management-page">
      <div className="page-header">
        <h2>{t('reconciliations.title')}</h2>
        <button className="btn-primary" onClick={() => setShowStartModal(true)}>
          + {t('reconciliations.start')}
        </button>
      </div>

      <div className="filter-bar">
        <button className="btn-secondary" onClick={handleRefresh}>
          {t('common.refresh')}
        </button>
      </div>

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : reconciliations.length === 0 ? (
        <div className="state-panel empty">{t('reconciliations.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reconciliations.col.ref')}</th>
                <th>{t('reconciliations.col.warehouse')}</th>
                <th>{t('reconciliations.col.status')}</th>
                <th>{t('reconciliations.col.completed_at')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {reconciliations.map((r) => (
                <tr key={r.id}>
                  <td className="font-mono">{r.reference_number}</td>
                  <td>{r.warehouse}</td>
                  <td>
                    <span className={`badge-status ${r.status}`}>{r.status}</span>
                  </td>
                  <td>{r.completed_at ? new Date(r.completed_at).toLocaleString() : '—'}</td>
                  <td>
                    <button
                      className="btn-action"
                      onClick={() => openAuditModal(r)}
                    >
                      {r.status === 'in_progress'
                        ? t('reconciliations.action.count')
                        : t('transfers.action.view')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showStartModal && (
        <div className="modal-backdrop" onClick={() => setShowStartModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{t('reconciliations.modal.start_title')}</h3>
              <button
                className="btn-close"
                onClick={() => setShowStartModal(false)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <form onSubmit={handleStartStocktake}>
              {formError && <div className="form-error">{formError}</div>}
              <div className="form-group">
                <label>{t('reconciliations.modal.warehouse')} *</label>
                <select
                  required
                  value={warehouseId}
                  onChange={(e) => setWarehouseId(e.target.value)}
                >
                  <option value="">—</option>
                  {(config?.warehouses || []).map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="modal-actions">
                <button type="submit" className="btn-primary" disabled={saving}>
                  {saving ? t('common.saving') : t('reconciliations.start')}
                </button>
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => setShowStartModal(false)}
                >
                  {t('common.cancel')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {selectedAudit && (
        <div className="modal-backdrop" onClick={() => setSelectedAudit(null)}>
          <div className="modal-content" style={{ maxWidth: '750px' }} onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>
                {t('reconciliations.modal.counts_title')}: {selectedAudit.reference_number}
              </h3>
              <button
                className="btn-close"
                onClick={() => setSelectedAudit(null)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <div className="table-responsive">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('products.col.name')}</th>
                    <th>{t('reconciliations.col.system_qty')}</th>
                    <th>{t('reconciliations.col.counted_qty')}</th>
                    <th>{t('reconciliations.col.difference')}</th>
                    <th>{t('reconciliations.col.variance')}</th>
                  </tr>
                </thead>
                <tbody>
                  {(selectedAudit.items || []).map((item) => (
                    <tr key={item.id}>
                      <td className="font-bold">{item.product_name}</td>
                      <td>{item.system_quantity}</td>
                      <td>
                        {selectedAudit.status === 'in_progress' ? (
                          <input
                            type="text"
                            value={countInputs[item.id] ?? ''}
                            onChange={(e) =>
                              setCountInputs({
                                ...countInputs,
                                [item.id]: e.target.value,
                              })
                            }
                            style={{ width: '80px', padding: '4px' }}
                          />
                        ) : (
                          item.counted_quantity
                        )}
                      </td>
                      <td className="font-mono">{item.difference_quantity}</td>
                      <td className="font-mono">{item.variance_value}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="modal-actions">
              {selectedAudit.status === 'in_progress' && (
                <>
                  <button
                    className="btn-secondary"
                    onClick={handleSaveCounts}
                    disabled={saving}
                  >
                    {saving ? t('common.saving') : t('common.save')}
                  </button>
                  <button
                    className="btn-primary"
                    onClick={handleCompleteReconciliation}
                    disabled={saving}
                  >
                    {t('reconciliations.action.complete')}
                  </button>
                </>
              )}
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setSelectedAudit(null)}
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

