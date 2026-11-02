import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { useOutletContext } from 'react-router-dom';
import { t } from '../i18n';
import './ManagementScreens.css';

export function TransfersScreen() {
  const { session } = useAuth();
  const config = useOutletContext();
  const [transfers, setTransfers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  // Create Modal
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [sourceWarehouseId, setSourceWarehouseId] = useState('');
  const [destWarehouseId, setDestWarehouseId] = useState('');
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('1.0000');
  const [autoDispatch, setAutoDispatch] = useState(true);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  // Receive Modal
  const [selectedTransfer, setSelectedTransfer] = useState(null);
  const [receiveQuantities, setReceiveQuantities] = useState({});

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleRefresh = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadTransfers = async () => {
      try {
        const res = await fetch(`${apiBase}/stock-transfers`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setTransfers(data.data || []);
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

    loadTransfers();

    return () => {
      cancelled = true;
    };
  }, [apiBase, session?.tokens?.access_token, reloadKey]);

  const handleCreateTransfer = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);

    try {
      const res = await fetch(`${apiBase}/stock-transfers`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({
          source_warehouse_id: Number(sourceWarehouseId),
          destination_warehouse_id: Number(destWarehouseId),
          items: [
            {
              product_id: Number(productId),
              quantity,
            },
          ],
          auto_dispatch: autoDispatch,
        }),
      });

      const data = await res.json();
      if (data.success) {
        setShowCreateModal(false);
        setSourceWarehouseId('');
        setDestWarehouseId('');
        setProductId('');
        setQuantity('1.0000');
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

  const handleDispatch = async (transferId) => {
    try {
      const res = await fetch(`${apiBase}/stock-transfers/${transferId}/dispatch`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        handleRefresh();
      }
    } catch {
      // Ignore
    }
  };

  const openReceiveModal = async (transfer) => {
    try {
      const res = await fetch(`${apiBase}/stock-transfers/${transfer.id}`, {
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setSelectedTransfer(data.data);
        const initial = {};
        (data.data.items || []).forEach((item) => {
          initial[item.id] = item.quantity_dispatched;
        });
        setReceiveQuantities(initial);
      }
    } catch {
      // Ignore
    }
  };

  const handleConfirmReceive = async () => {
    if (!selectedTransfer) return;
    setSaving(true);
    try {
      const itemsPayload = Object.entries(receiveQuantities).map(([itemId, qty]) => ({
        item_id: Number(itemId),
        quantity_received: qty,
      }));

      const res = await fetch(`${apiBase}/stock-transfers/${selectedTransfer.id}/receive`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({ items: itemsPayload }),
      });

      const data = await res.json();
      if (data.success) {
        setSelectedTransfer(null);
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
        <h2>{t('transfers.title')}</h2>
        <button className="btn-primary" onClick={() => setShowCreateModal(true)}>
          + {t('transfers.create')}
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
      ) : transfers.length === 0 ? (
        <div className="state-panel empty">{t('transfers.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('transfers.col.number')}</th>
                <th>{t('transfers.col.source')}</th>
                <th>{t('transfers.col.destination')}</th>
                <th>{t('transfers.col.status')}</th>
                <th>{t('transfers.col.date')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {transfers.map((tr) => (
                <tr key={tr.id}>
                  <td className="font-mono">{tr.transfer_number}</td>
                  <td>{tr.source_warehouse}</td>
                  <td>{tr.destination_warehouse}</td>
                  <td>
                    <span className={`badge-status ${tr.status}`}>{tr.status}</span>
                  </td>
                  <td>{tr.dispatched_at ? new Date(tr.dispatched_at).toLocaleString() : '—'}</td>
                  <td>
                    {tr.status === 'draft' && (
                      <button
                        className="btn-action"
                        onClick={() => handleDispatch(tr.id)}
                      >
                        {t('transfers.action.dispatch')}
                      </button>
                    )}
                    {tr.status === 'dispatched' && (
                      <button
                        className="btn-action"
                        onClick={() => openReceiveModal(tr)}
                      >
                        {t('transfers.action.receive')}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showCreateModal && (
        <div className="modal-backdrop" onClick={() => setShowCreateModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{t('transfers.modal.create_title')}</h3>
              <button
                className="btn-close"
                onClick={() => setShowCreateModal(false)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <form onSubmit={handleCreateTransfer}>
              {formError && <div className="form-error">{formError}</div>}
              <div className="form-group">
                <label>{t('transfers.modal.source')} *</label>
                <select
                  required
                  value={sourceWarehouseId}
                  onChange={(e) => setSourceWarehouseId(e.target.value)}
                >
                  <option value="">—</option>
                  {(config?.warehouses || []).map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-group">
                <label>{t('transfers.modal.destination')} *</label>
                <select
                  required
                  value={destWarehouseId}
                  onChange={(e) => setDestWarehouseId(e.target.value)}
                >
                  <option value="">—</option>
                  {(config?.warehouses || []).map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-row">
                <div className="form-group">
                  <label>{t('transfers.modal.product')} *</label>
                  <input
                    type="text"
                    required
                    placeholder="Product ID / SKU"
                    value={productId}
                    onChange={(e) => setProductId(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>{t('transfers.modal.quantity')} *</label>
                  <input
                    type="text"
                    required
                    value={quantity}
                    onChange={(e) => setQuantity(e.target.value)}
                  />
                </div>
              </div>
              <div className="form-group">
                <label className="checkbox-label">
                  <input
                    type="checkbox"
                    checked={autoDispatch}
                    onChange={(e) => setAutoDispatch(e.target.checked)}
                  />
                  {t('transfers.modal.autodispatch')}
                </label>
              </div>
              <div className="modal-actions">
                <button type="submit" className="btn-primary" disabled={saving}>
                  {saving ? t('common.saving') : t('common.save')}
                </button>
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => setShowCreateModal(false)}
                >
                  {t('common.cancel')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {selectedTransfer && (
        <div className="modal-backdrop" onClick={() => setSelectedTransfer(null)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>
                {t('transfers.modal.receive_title')}: {selectedTransfer.transfer_number}
              </h3>
              <button
                className="btn-close"
                onClick={() => setSelectedTransfer(null)}
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
                    <th>{t('transfers.modal.quantity')}</th>
                    <th>{t('transfers.modal.received_qty')}</th>
                  </tr>
                </thead>
                <tbody>
                  {(selectedTransfer.items || []).map((item) => (
                    <tr key={item.id}>
                      <td>{item.product_name}</td>
                      <td>{item.quantity_dispatched}</td>
                      <td>
                        <input
                          type="text"
                          value={receiveQuantities[item.id] ?? item.quantity_dispatched}
                          onChange={(e) =>
                            setReceiveQuantities({
                              ...receiveQuantities,
                              [item.id]: e.target.value,
                            })
                          }
                          style={{ width: '80px', padding: '4px' }}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="modal-actions">
              <button
                className="btn-primary"
                onClick={handleConfirmReceive}
                disabled={saving}
              >
                {saving ? t('common.saving') : t('transfers.action.receive')}
              </button>
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setSelectedTransfer(null)}
              >
                {t('common.cancel')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

