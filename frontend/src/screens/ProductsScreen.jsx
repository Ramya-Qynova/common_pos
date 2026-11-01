import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { useOutletContext } from 'react-router-dom';
import { t } from '../i18n';
import './ManagementScreens.css';

export function ProductsScreen() {
  const { session } = useAuth();
  const config = useOutletContext();
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [showAddModal, setShowAddModal] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  // Form states
  const [name, setName] = useState('');
  const [sku, setSku] = useState('');
  const [price, setPrice] = useState('0.0000');
  const [barcode, setBarcode] = useState('');
  const [unitId, setUnitId] = useState('');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  const handleSearch = () => {
    setLoading(true);
    setReloadKey((k) => k + 1);
  };

  useEffect(() => {
    let cancelled = false;

    const loadProducts = async () => {
      try {
        const queryParams = new URLSearchParams();
        if (searchTerm) queryParams.set('search', searchTerm);

        const res = await fetch(`${apiBase}/products?${queryParams.toString()}`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setProducts(data.data || []);
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

    loadProducts();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiBase, session?.tokens?.access_token, reloadKey]);

  const handleCreateProduct = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError(null);

    const defaultUnit = unitId || config?.units?.[0]?.id || 1;

    try {
      const res = await fetch(`${apiBase}/products`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
        body: JSON.stringify({
          name,
          sku,
          price,
          barcode: barcode || undefined,
          base_unit_id: Number(defaultUnit),
          price_list_id: config?.price_lists?.[0]?.id || 1,
        }),
      });

      const data = await res.json();
      if (data.success) {
        setShowAddModal(false);
        setName('');
        setSku('');
        setPrice('0.0000');
        setBarcode('');
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
        <h2>{t('products.title')}</h2>
        <button className="btn-primary" onClick={() => setShowAddModal(true)}>
          + {t('products.add')}
        </button>
      </div>

      <div className="filter-bar">
        <input
          type="text"
          className="search-input"
          placeholder={t('products.search')}
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
      ) : products.length === 0 ? (
        <div className="state-panel empty">{t('products.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('products.col.sku')}</th>
                <th>{t('products.col.name')}</th>
                <th>{t('products.col.category')}</th>
                <th>{t('products.col.unit')}</th>
                <th>{t('products.col.price')}</th>
                <th>{t('products.col.stock')}</th>
              </tr>
            </thead>
            <tbody>
              {products.map((p) => (
                <tr key={p.id}>
                  <td className="font-mono">{p.sku}</td>
                  <td className="font-bold">{p.name}</td>
                  <td>{p.category || '—'}</td>
                  <td>{p.unit}</td>
                  <td>{p.default_price}</td>
                  <td className="font-bold">{p.stock_on_hand}</td>
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
              <h3>{t('products.form.title')}</h3>
              <button
                className="btn-close"
                onClick={() => setShowAddModal(false)}
                aria-label={t('common.close')}
              >
                ×
              </button>
            </div>
            <form onSubmit={handleCreateProduct}>
              {formError && <div className="form-error">{formError}</div>}
              <div className="form-group">
                <label>{t('products.form.name')} *</label>
                <input
                  type="text"
                  required
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                />
              </div>
              <div className="form-row">
                <div className="form-group">
                  <label>{t('products.form.sku')} *</label>
                  <input
                    type="text"
                    required
                    value={sku}
                    onChange={(e) => setSku(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>{t('products.form.price')} *</label>
                  <input
                    type="text"
                    required
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                  />
                </div>
              </div>
              <div className="form-row">
                <div className="form-group">
                  <label>{t('products.form.barcode')}</label>
                  <input
                    type="text"
                    value={barcode}
                    onChange={(e) => setBarcode(e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>{t('products.form.unit')}</label>
                  <select value={unitId} onChange={(e) => setUnitId(e.target.value)}>
                    {(config?.units || []).map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name} ({u.code})
                      </option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="modal-actions">
                <button type="submit" className="btn-primary" disabled={saving}>
                  {saving ? t('common.saving') : t('products.form.save')}
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
    </div>
  );
}
