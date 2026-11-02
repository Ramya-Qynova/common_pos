import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import './ManagementScreens.css';

export function TemplatesScreen() {
  const { session } = useAuth();
  const [templates, setTemplates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedTemplate, setSelectedTemplate] = useState(null);

  // Update preview state
  const [updatePreview, setUpdatePreview] = useState(null);
  const [checkingUpdates, setCheckingUpdates] = useState(false);
  const [applying, setApplying] = useState(false);
  const [updateMessage, setUpdateMessage] = useState(null);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  useEffect(() => {
    let cancelled = false;
    const fetchTemplates = async () => {
      setLoading(true);
      setError(null);
      try {
        const res = await fetch(`${apiBase}/templates`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled) {
          if (data.success) {
            setTemplates(data.data || []);
          } else {
            setError(data.error?.message || t('common.error'));
          }
        }
      } catch (err) {
        if (!cancelled) {
          setError(err.message);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    fetchTemplates();

    return () => {
      cancelled = true;
    };
  }, [apiBase, session?.tokens?.access_token]);

  const handleCheckUpdates = async () => {
    setCheckingUpdates(true);
    setUpdateMessage(null);
    try {
      const res = await fetch(`${apiBase}/template/update-preview`, {
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setUpdatePreview(data.data);
      } else {
        setError(data.error?.message || t('common.error'));
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setCheckingUpdates(false);
    }
  };

  const handleApplyUpdates = async () => {
    setApplying(true);
    setError(null);
    try {
      const res = await fetch(`${apiBase}/template/update`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setUpdateMessage(t('templates.applied_success'));
        setUpdatePreview(null);
      } else {
        setError(data.error?.message || t('common.error'));
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setApplying(false);
    }
  };

  const hasPendingUpdates =
    updatePreview &&
    ((updatePreview.added_modules && updatePreview.added_modules.length > 0) ||
      (updatePreview.added_units && updatePreview.added_units.length > 0) ||
      (updatePreview.added_categories && updatePreview.added_categories.length > 0) ||
      (updatePreview.added_lookups && updatePreview.added_lookups.length > 0) ||
      (updatePreview.added_custom_fields && updatePreview.added_custom_fields.length > 0));

  return (
    <div className="management-screen">
      <div className="screen-header">
        <div>
          <h2>{t('templates.title')}</h2>
          <p className="screen-subtitle">{t('templates.subtitle')}</p>
        </div>
        <div className="header-actions">
          <button
            type="button"
            className="btn-primary"
            disabled={checkingUpdates}
            onClick={handleCheckUpdates}
          >
            {checkingUpdates ? t('common.loading') : t('templates.action.check_updates')}
          </button>
        </div>
      </div>

      {updateMessage && <div className="alert-success">{updateMessage}</div>}

      {updatePreview && (
        <div className="card-panel mb-4">
          <div className="modal-header">
            <h3>{t('templates.update_preview')}</h3>
            <button
              type="button"
              className="btn-close"
              onClick={() => setUpdatePreview(null)}
              aria-label={t('common.close')}
            >
              &times;
            </button>
          </div>
          {hasPendingUpdates ? (
            <div>
              {updatePreview.added_modules?.length > 0 && (
                <div className="mb-2">
                  <strong>{t('templates.col.modules')}: </strong>
                  {updatePreview.added_modules.join(', ')}
                </div>
              )}
              {updatePreview.added_units?.length > 0 && (
                <div className="mb-2">
                  <strong>{t('templates.units')}: </strong>
                  {updatePreview.added_units.map((u) => u.name || u.code).join(', ')}
                </div>
              )}
              {updatePreview.added_categories?.length > 0 && (
                <div className="mb-2">
                  <strong>{t('templates.categories')}: </strong>
                  {updatePreview.added_categories.map((c) => c.name || c.code).join(', ')}
                </div>
              )}
              {updatePreview.added_custom_fields?.length > 0 && (
                <div className="mb-2">
                  <strong>{t('templates.custom_fields')}: </strong>
                  {updatePreview.added_custom_fields.map((cf) => cf.label || cf.code).join(', ')}
                </div>
              )}
              <div className="mt-4">
                <button
                  type="button"
                  className="btn-primary"
                  disabled={applying}
                  onClick={handleApplyUpdates}
                >
                  {applying ? t('templates.action.applying') : t('templates.action.apply')}
                </button>
              </div>
            </div>
          ) : (
            <div className="state-panel empty">{t('templates.no_updates')}</div>
          )}
        </div>
      )}

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : templates.length === 0 ? (
        <div className="state-panel empty">{t('templates.empty')}</div>
      ) : (
        <div className="table-responsive">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('templates.col.name')}</th>
                <th>{t('templates.col.code')}</th>
                <th>{t('templates.col.version')}</th>
                <th>{t('templates.col.description')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {templates.map((tpl) => (
                <tr key={tpl.id || tpl.code}>
                  <td className="font-bold">{tpl.name}</td>
                  <td className="font-mono">{tpl.code}</td>
                  <td>{tpl.version}</td>
                  <td>{tpl.description}</td>
                  <td>
                    <button
                      type="button"
                      className="btn-secondary btn-sm"
                      onClick={() => setSelectedTemplate(tpl)}
                    >
                      {t('templates.action.view')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {selectedTemplate && (
        <div className="modal-backdrop" onClick={() => setSelectedTemplate(null)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>
                {t('templates.details.title')} — {selectedTemplate.name}
              </h3>
              <button
                type="button"
                className="btn-close"
                onClick={() => setSelectedTemplate(null)}
                aria-label={t('common.close')}
              >
                &times;
              </button>
            </div>
            <div className="modal-body">
              <p className="mb-4">{selectedTemplate.description}</p>

              {selectedTemplate.modules?.length > 0 && (
                <div className="mb-4">
                  <h4>{t('templates.col.modules')}</h4>
                  <div className="badge-list">
                    {selectedTemplate.modules.map((m) => (
                      <span key={m} className="badge-info mr-1">
                        {m}
                      </span>
                    ))}
                  </div>
                </div>
              )}

              {selectedTemplate.units?.length > 0 && (
                <div className="mb-4">
                  <h4>{t('templates.units')}</h4>
                  <div className="badge-list">
                    {selectedTemplate.units.map((u) => (
                      <span key={u.code} className="badge-neutral mr-1">
                        {u.name} ({u.code})
                      </span>
                    ))}
                  </div>
                </div>
              )}

              {selectedTemplate.categories?.length > 0 && (
                <div className="mb-4">
                  <h4>{t('templates.categories')}</h4>
                  <div className="badge-list">
                    {selectedTemplate.categories.map((c) => (
                      <span key={c.code} className="badge-neutral mr-1">
                        {c.name}
                      </span>
                    ))}
                  </div>
                </div>
              )}

              {selectedTemplate.custom_fields?.length > 0 && (
                <div className="mb-4">
                  <h4>{t('templates.custom_fields')}</h4>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>{t('templates.col.name')}</th>
                        <th>{t('templates.col.code')}</th>
                        <th>{t('products.col.sku')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedTemplate.custom_fields.map((cf) => (
                        <tr key={cf.code}>
                          <td className="font-bold">{cf.label}</td>
                          <td className="font-mono">{cf.code}</td>
                          <td>{cf.type}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {selectedTemplate.lookups?.length > 0 && (
                <div className="mb-4">
                  <h4>{t('templates.lookups')}</h4>
                  {selectedTemplate.lookups.map((lk) => (
                    <div key={lk.code} className="mb-2">
                      <strong>{lk.name}: </strong>
                      {lk.values?.map((v) => v.label || v.code).join(', ')}
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="modal-actions">
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setSelectedTemplate(null)}
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
