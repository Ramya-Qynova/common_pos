import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { useOutletContext } from 'react-router-dom';
import { t } from '../i18n';
import './ManagementScreens.css';

export function InventoryScreen() {
  const { session } = useAuth();
  const config = useOutletContext();
  const [balances, setBalances] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const [showAdjustModal, setShowAdjustModal] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  // FIFO cost layers modal states
  const [selectedProductForLayers, setSelectedProductForLayers] = useState(null);
  const [costLayers, setCostLayers] = useState([]);
  const [layersLoading, setLayersLoading] = useState(false);
  const [layersError, setLayersError] = useState(null);

  // Adjustment form states
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('1.0000');
  const [direction, setDirection] = useState('out');
  const [reason, setReason] = useState('damage');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleRefresh = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadBalances = async () => {
      try {
        const queryParams = new URLSearchParams();
        if (lowStockOnly) queryParams.set('low_stock', '1');

        const res = await fetch(`${apiBase}/inventory/balances?${queryParams.toString()}`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setBalances(data.data || []);
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

    loadBalances();

    return () => {
      cancelled = true;
    };
  }, [apiBase, session?.tokens?.access_token, lowStockOnly, reloadKey]);

  const handleViewCostLayers = async (balance) => {
    setSelectedProductForLayers(balance);
    setLayersLoading(true);
    setLayersError(null);
    try {
      const res = await fetch(
        `${apiBase}/inventory/cost-layers?warehouse_id=${balance.warehouse_id}&product_id=${balance.product_id}`,
        {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        }
      );
      const data = await res.json();
      if (data.success) {
        setCostLayers(data.data || []);
      } else {
        setLayersError(data.error?.message || t('common.error'));
      }
    } catch (err) {
      setLayersError(err.message);
    } finally {
      setLayersLoading(false);
    }
  };

  const handleAdjustStock = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);

    const warehouseId = config?.warehouses?.[0]?.id || 1;

    try {
      const res = await fetch(`${apiBase}/stock-adjustments`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({
          warehouse_id: warehouseId,
          type: reason,
          reason: `Manual adjustment: ${reason}`,
          items: [
            {
              product_id: Number(productId),
              direction,
              quantity,
            },
          ],
        }),
      });

      const data = await res.json();
      if (data.success) {
        setShowAdjustModal(false);
        setProductId('');
        setQuantity('1.0000');
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

  return (
    <div className="management-page">
      <div className="page-header">
        <h2>{t('inventory.title')}</h2>
        <button className="btn-primary" onClick={() => setShowAdjustModal(true)}>
          + {t('inventory.adjust')}
        </button>
      </div>

      <div className="filter-bar">
        <label className="checkbox-label">
          <input
            type="checkbox"
            checked={lowStockOnly}
            onChange={(e) => {
              setLowStockOnly(e.target.checked);
              setLoading(true);
            }}
          />
          {t('inventory.filter.low_stock')}
        </label>
        <button className="btn-secondary" onClick={handleRefresh}>
          {t('common.refresh')}
        </button>
      </div>

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : balances.length === 0 ? (
        <div className="state-panel empty">{t('inventory.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('inventory.col.warehouse')}</th>
                <th>{t('inventory.col.product')}</th>
                <th>{t('products.col.sku')}</th>
                <th>{t('products.col.unit')}</th>
                <th>{t('inventory.col.qty')}</th>
                <th>{t('inventory.col.cost')}</th>
                <th>{t('inventory.col.value')}</th>
                <th>{t('sales.col.status')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {balances.map((b) => (
                <tr key={b.id}>
                  <td>{b.warehouse_name}</td>
                  <td className="font-bold">{b.product_name}</td>
                  <td className="font-mono">{b.sku}</td>
                  <td>{b.unit}</td>
                  <td className="font-bold">{b.quantity}</td>
                  <td>{b.average_cost}</td>
                  <td>{b.total_value}</td>
                  <td>
                    {b.is_low_stock && (
                      <span className="badge-warning">{t('inventory.low_stock')}</span>
                    )}
                  </td>
                  <td>
                    <button
                      type="button"
                      className="btn-secondary btn-sm"
                      onClick={() => handleViewCostLayers(b)}
                    >
                      {t('inventory.action.view_layers')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showAdjustModal && (
        <div className="modal-backdrop" onClick={() => setShowAdjustModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>{t('inventory.adjust.title')}</h3>
              <button
                className="btn-close"
                onClick={() => setShowAdjustModal(false)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <form onSubmit={handleAdjustStock}>
              {formError && <div className="form-error">{formError}</div>}
              <div className="form-group">
                <label>{t('inventory.adjust.product')} *</label>
                <select
                  required
                  value={productId}
                  onChange={(e) => setProductId(e.target.value)}
                >
                  <option value="">—</option>
                  {balances.map((b) => (
                    <option key={b.id || b.product_id} value={b.product_id}>
                      {b.product_name} ({b.sku})
                    </option>
                  ))}
                </select>
              </div>
              <div className="form-row">
                <div className="form-group">
                  <label>{t('inventory.adjust.type')}</label>
                  <select
                    value={direction}
                    onChange={(e) => setDirection(e.target.value)}
                  >
                    <option value="in">{t('inventory.adjust.type.increase')}</option>
                    <option value="out">{t('inventory.adjust.type.decrease')}</option>
                  </select>
                </div>
                <div className="form-group">
                  <label>{t('inventory.adjust.qty')} *</label>
                  <input
                    type="text"
                    required
                    value={quantity}
                    onChange={(e) => setQuantity(e.target.value)}
                  />
                </div>
              </div>
              <div className="form-group">
                <label>{t('inventory.reason')} *</label>
                <select value={reason} onChange={(e) => setReason(e.target.value)}>
                  <option value="damage">{t('inventory.adjust.reason.damage')}</option>
                  <option value="wastage">{t('inventory.adjust.reason.wastage')}</option>
                  <option value="discrepancy">{t('inventory.adjust.reason.discrepancy')}</option>
                  <option value="reconciliation">{t('inventory.adjust.reason.reconciliation')}</option>
                </select>
              </div>
              <div className="modal-actions">
                <button type="submit" className="btn-primary" disabled={saving}>
                  {saving ? t('inventory.adjust.applying') : t('inventory.adjust.submit')}
                </button>
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => setShowAdjustModal(false)}
                >
                  {t('common.cancel')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {selectedProductForLayers && (
        <div
          className="modal-backdrop"
          onClick={() => setSelectedProductForLayers(null)}
        >
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>
                {t('inventory.cost_layers.title')} — {selectedProductForLayers.product_name}
              </h3>
              <button
                type="button"
                className="btn-close"
                onClick={() => setSelectedProductForLayers(null)}
                aria-label={t('common.close')}
              >
                &times;
              </button>
            </div>
            {layersLoading ? (
              <div className="state-panel">{t('common.loading')}</div>
            ) : layersError ? (
              <div className="state-panel error">{layersError}</div>
            ) : costLayers.length === 0 ? (
              <div className="state-panel empty">{t('inventory.cost_layers.empty')}</div>
            ) : (
              <div className="table-responsive">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('inventory.cost_layers.layer')}</th>
                      <th>{t('inventory.cost_layers.received')}</th>
                      <th>{t('inventory.cost_layers.remaining')}</th>
                      <th>{t('inventory.cost_layers.unit_cost')}</th>
                      <th>{t('inventory.cost_layers.total_value')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {costLayers.map((layer) => (
                      <tr key={layer.id}>
                        <td className="font-mono">{layer.id}</td>
                        <td>{layer.received_at ? new Date(layer.received_at).toLocaleDateString() : ''}</td>
                        <td className="font-bold">{layer.quantity_remaining}</td>
                        <td>{layer.unit_cost}</td>
                        <td>{layer.total_value}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div className="modal-actions">
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setSelectedProductForLayers(null)}
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
