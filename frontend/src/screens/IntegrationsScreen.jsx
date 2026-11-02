import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import './ManagementScreens.css';

export function IntegrationsScreen() {
  const { session } = useAuth();
  const [activeTab, setActiveTab] = useState('webhooks');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);

  // Webhooks state
  const [webhooks, setWebhooks] = useState([]);
  const [showAddWebhookModal, setShowAddWebhookModal] = useState(false);
  const [webhookForm, setWebhookForm] = useState({
    name: '',
    url: '',
    secret: '',
    events: ['sale.created'],
  });
  const [selectedWebhookLogs, setSelectedWebhookLogs] = useState(null);
  const [deliveryLogs, setDeliveryLogs] = useState([]);
  const [logsLoading, setLogsLoading] = useState(false);

  // API Keys state
  const [apiKeys, setApiKeys] = useState([]);
  const [showAddKeyModal, setShowAddKeyModal] = useState(false);
  const [keyName, setKeyName] = useState('');
  const [newlyCreatedKey, setNewlyCreatedKey] = useState(null);
  const [copiedKey, setCopiedKey] = useState(false);

  // Accounting export state
  const [exportFrom, setExportFrom] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
  });
  const [exportTo, setExportTo] = useState(() => new Date().toISOString().slice(0, 10));
  const [exportingType, setExportingType] = useState(null);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';
  const token = session?.tokens?.access_token;

  useEffect(() => {
    let cancelled = false;

    const loadData = async () => {
      setLoading(true);
      setError(null);
      try {
        if (activeTab === 'webhooks') {
          const res = await fetch(`${apiBase}/integrations/webhooks`, {
            headers: {
              Authorization: `Bearer ${token}`,
              Accept: 'application/json',
            },
          });
          const json = await res.json();
          if (!cancelled) {
            if (json.success) {
              setWebhooks(json.data || []);
            } else {
              setError(json.error?.message || t('common.error'));
            }
            setLoading(false);
          }
        } else if (activeTab === 'api_keys') {
          const res = await fetch(`${apiBase}/integrations/api-keys`, {
            headers: {
              Authorization: `Bearer ${token}`,
              Accept: 'application/json',
            },
          });
          const json = await res.json();
          if (!cancelled) {
            if (json.success) {
              setApiKeys(json.data || []);
            } else {
              setError(json.error?.message || t('common.error'));
            }
            setLoading(false);
          }
        } else {
          setLoading(false);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err.message);
          setLoading(false);
        }
      }
    };

    loadData();

    return () => {
      cancelled = true;
    };
  }, [apiBase, token, activeTab, reloadKey]);

  // Handle register webhook
  const handleSaveWebhook = async (e) => {
    e.preventDefault();
    try {
      const res = await fetch(`${apiBase}/integrations/webhooks`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(webhookForm),
      });
      const json = await res.json();
      if (json.success) {
        setShowAddWebhookModal(false);
        setWebhookForm({ name: '', url: '', secret: '', events: ['sale.created'] });
        setReloadKey((k) => k + 1);
      } else {
        alert(json.error?.message || t('common.error'));
      }
    } catch (err) {
      alert(err.message);
    }
  };

  // Test ping webhook
  const handleTestPing = async (id) => {
    try {
      const res = await fetch(`${apiBase}/integrations/webhooks/${id}/test`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      const json = await res.json();
      if (json.success) {
        alert(`Ping result: ${json.data.status} (HTTP ${json.data.response_status})`);
        setReloadKey((k) => k + 1);
      } else {
        alert(json.error?.message || t('common.error'));
      }
    } catch (err) {
      alert(err.message);
    }
  };

  // View webhook delivery logs
  const handleViewLogs = async (webhook) => {
    setSelectedWebhookLogs(webhook);
    setLogsLoading(true);
    try {
      const res = await fetch(`${apiBase}/integrations/webhooks/${webhook.id}/deliveries`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      const json = await res.json();
      if (json.success) {
        setDeliveryLogs(json.data || []);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLogsLoading(false);
    }
  };

  // Retry delivery
  const handleRetryDelivery = async (deliveryId) => {
    try {
      const res = await fetch(`${apiBase}/integrations/webhooks/deliveries/${deliveryId}/retry`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      const json = await res.json();
      if (json.success) {
        alert(`Retry status: ${json.data.status}`);
        if (selectedWebhookLogs) {
          handleViewLogs(selectedWebhookLogs);
        }
      }
    } catch (err) {
      alert(err.message);
    }
  };

  // Delete webhook
  const handleDeleteWebhook = async (id) => {
    if (!window.confirm('Delete this webhook?')) return;
    try {
      await fetch(`${apiBase}/integrations/webhooks/${id}`, {
        method: 'DELETE',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      setReloadKey((k) => k + 1);
    } catch (err) {
      alert(err.message);
    }
  };

  // Generate API key
  const handleCreateApiKey = async (e) => {
    e.preventDefault();
    try {
      const res = await fetch(`${apiBase}/integrations/api-keys`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ name: keyName, permissions: ['*'] }),
      });
      const json = await res.json();
      if (json.success) {
        setShowAddKeyModal(false);
        setKeyName('');
        setNewlyCreatedKey(json.data.plain_key);
        setCopiedKey(false);
        setReloadKey((k) => k + 1);
      } else {
        alert(json.error?.message || t('common.error'));
      }
    } catch (err) {
      alert(err.message);
    }
  };

  // Revoke API key
  const handleRevokeApiKey = async (id) => {
    if (!window.confirm('Revoke this API key?')) return;
    try {
      await fetch(`${apiBase}/integrations/api-keys/${id}`, {
        method: 'DELETE',
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      setReloadKey((k) => k + 1);
    } catch (err) {
      alert(err.message);
    }
  };

  // Accounting export download
  const handleDownloadExport = async (type) => {
    setExportingType(type);
    try {
      let endpoint = '';
      let filename = `${type}.csv`;
      if (type === 'sales') {
        endpoint = `/integrations/accounting/export-sales?from=${exportFrom} 00:00:00&to=${exportTo} 23:59:59`;
      } else if (type === 'ledger') {
        endpoint = `/integrations/accounting/export-ledger?from=${exportFrom} 00:00:00&to=${exportTo} 23:59:59`;
      } else if (type === 'inventory') {
        endpoint = '/integrations/accounting/export-inventory';
      }

      const res = await fetch(`${apiBase}${endpoint}`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'text/csv',
        },
      });

      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      alert(err.message);
    } finally {
      setExportingType(null);
    }
  };

  return (
    <div className="management-page">
      <div className="page-header">
        <h2>{t('integrations.title')}</h2>
        {activeTab === 'webhooks' && (
          <button className="btn-primary" onClick={() => setShowAddWebhookModal(true)}>
            {t('integrations.webhooks.add')}
          </button>
        )}
        {activeTab === 'api_keys' && (
          <button className="btn-primary" onClick={() => setShowAddKeyModal(true)}>
            {t('integrations.keys.add')}
          </button>
        )}
      </div>

      <div className="report-tabs">
        <button
          className={`report-tab-btn ${activeTab === 'webhooks' ? 'active' : ''}`}
          onClick={() => setActiveTab('webhooks')}
        >
          {t('integrations.tab.webhooks')}
        </button>
        <button
          className={`report-tab-btn ${activeTab === 'api_keys' ? 'active' : ''}`}
          onClick={() => setActiveTab('api_keys')}
        >
          {t('integrations.tab.api_keys')}
        </button>
        <button
          className={`report-tab-btn ${activeTab === 'accounting' ? 'active' : ''}`}
          onClick={() => setActiveTab('accounting')}
        >
          {t('integrations.tab.accounting')}
        </button>
      </div>

      {loading ? (
        <div className="state-panel">{t('common.loading')}</div>
      ) : error ? (
        <div className="state-panel error">{error}</div>
      ) : (
        <div className="dashboard-content">
          {activeTab === 'webhooks' && (
            <div className="table-responsive">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('integrations.webhooks.name')}</th>
                    <th>{t('integrations.webhooks.url')}</th>
                    <th>{t('integrations.webhooks.events')}</th>
                    <th>{t('integrations.webhooks.deliveries')}</th>
                    <th>{t('sales.col.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {webhooks.length > 0 ? (
                    webhooks.map((w) => (
                      <tr key={w.id}>
                        <td><strong>{w.name}</strong></td>
                        <td className="font-mono">{w.url}</td>
                        <td>
                          {w.events && w.events.map((ev) => (
                            <span key={ev} className="status-badge" style={{ marginRight: '0.25rem' }}>
                              {ev}
                            </span>
                          ))}
                        </td>
                        <td>{w.deliveries_count ?? 0}</td>
                        <td>
                          <button
                            className="btn-action"
                            style={{ marginRight: '0.5rem' }}
                            onClick={() => handleTestPing(w.id)}
                          >
                            {t('integrations.webhooks.test_ping')}
                          </button>
                          <button
                            className="btn-action"
                            style={{ marginRight: '0.5rem' }}
                            onClick={() => handleViewLogs(w)}
                          >
                            {t('integrations.webhooks.view_logs')}
                          </button>
                          <button
                            className="btn-action danger"
                            onClick={() => handleDeleteWebhook(w.id)}
                          >
                            {t('integrations.webhooks.delete')}
                          </button>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan="5" className="empty-message">
                        {t('integrations.webhooks.empty')}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'api_keys' && (
            <div className="table-responsive">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>{t('integrations.keys.name')}</th>
                    <th>{t('integrations.keys.prefix')}</th>
                    <th>{t('integrations.keys.preview')}</th>
                    <th>{t('integrations.keys.created')}</th>
                    <th>{t('integrations.keys.last_used')}</th>
                    <th>{t('sales.col.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {apiKeys.length > 0 ? (
                    apiKeys.map((k) => (
                      <tr key={k.id}>
                        <td><strong>{k.name}</strong></td>
                        <td className="font-mono">{k.prefix}</td>
                        <td className="font-mono">{k.secret_preview}</td>
                        <td>{k.created_at ? new Date(k.created_at).toLocaleDateString() : ''}</td>
                        <td>{k.last_used_at ? new Date(k.last_used_at).toLocaleString() : ''}</td>
                        <td>
                          <button
                            className="btn-action danger"
                            onClick={() => handleRevokeApiKey(k.id)}
                          >
                            {t('integrations.keys.revoke')}
                          </button>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan="6" className="empty-message">
                        {t('integrations.keys.empty')}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'accounting' && (
            <div>
              <div className="filter-bar" style={{ marginBottom: '1.5rem', display: 'flex', gap: '1rem', alignItems: 'center' }}>
                <label style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                  <span>{t('integrations.accounting.from')}:</span>
                  <input
                    type="date"
                    value={exportFrom}
                    onChange={(e) => setExportFrom(e.target.value)}
                  />
                </label>
                <label style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                  <span>{t('integrations.accounting.to')}:</span>
                  <input
                    type="date"
                    value={exportTo}
                    onChange={(e) => setExportTo(e.target.value)}
                  />
                </label>
              </div>

              <div className="metrics-grid">
                <div className="metric-card primary">
                  <div className="metric-label">{t('integrations.accounting.sales_title')}</div>
                  <p style={{ fontSize: '0.875rem', color: '#64748b', margin: '0.5rem 0 1rem 0' }}>
                    {t('integrations.accounting.sales_desc')}
                  </p>
                  <button
                    className="btn-primary"
                    disabled={exportingType === 'sales'}
                    onClick={() => handleDownloadExport('sales')}
                  >
                    {exportingType === 'sales' ? t('integrations.accounting.exporting') : t('integrations.accounting.download')}
                  </button>
                </div>

                <div className="metric-card success">
                  <div className="metric-label">{t('integrations.accounting.ledger_title')}</div>
                  <p style={{ fontSize: '0.875rem', color: '#64748b', margin: '0.5rem 0 1rem 0' }}>
                    {t('integrations.accounting.ledger_desc')}
                  </p>
                  <button
                    className="btn-primary"
                    disabled={exportingType === 'ledger'}
                    onClick={() => handleDownloadExport('ledger')}
                  >
                    {exportingType === 'ledger' ? t('integrations.accounting.exporting') : t('integrations.accounting.download')}
                  </button>
                </div>

                <div className="metric-card info">
                  <div className="metric-label">{t('integrations.accounting.inventory_title')}</div>
                  <p style={{ fontSize: '0.875rem', color: '#64748b', margin: '0.5rem 0 1rem 0' }}>
                    {t('integrations.accounting.inventory_desc')}
                  </p>
                  <button
                    className="btn-primary"
                    disabled={exportingType === 'inventory'}
                    onClick={() => handleDownloadExport('inventory')}
                  >
                    {exportingType === 'inventory' ? t('integrations.accounting.exporting') : t('integrations.accounting.download')}
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Register Webhook Modal */}
      {showAddWebhookModal && (
        <div className="modal-backdrop">
          <div className="modal-dialog">
            <div className="modal-header">
              <h3>{t('integrations.webhooks.add')}</h3>
              <button className="btn-close" onClick={() => setShowAddWebhookModal(false)}>×</button>
            </div>
            <form onSubmit={handleSaveWebhook}>
              <div className="modal-body">
                <div className="form-group">
                  <label>{t('integrations.webhooks.name')}</label>
                  <input
                    type="text"
                    required
                    value={webhookForm.name}
                    onChange={(e) => setWebhookForm({ ...webhookForm, name: e.target.value })}
                  />
                </div>
                <div className="form-group">
                  <label>{t('integrations.webhooks.url')}</label>
                  <input
                    type="url"
                    required
                    value={webhookForm.url}
                    onChange={(e) => setWebhookForm({ ...webhookForm, url: e.target.value })}
                  />
                </div>
                <div className="form-group">
                  <label>{t('integrations.webhooks.secret')}</label>
                  <input
                    type="text"
                    value={webhookForm.secret}
                    onChange={(e) => setWebhookForm({ ...webhookForm, secret: e.target.value })}
                  />
                </div>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn-secondary" onClick={() => setShowAddWebhookModal(false)}>
                  {t('integrations.webhooks.cancel')}
                </button>
                <button type="submit" className="btn-primary">
                  {t('integrations.webhooks.save')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Webhook Delivery Logs Modal */}
      {selectedWebhookLogs && (
        <div className="modal-backdrop">
          <div className="modal-dialog" style={{ maxWidth: '800px' }}>
            <div className="modal-header">
              <h3>{t('integrations.webhooks.logs_title')}</h3>
              <button className="btn-close" onClick={() => setSelectedWebhookLogs(null)}>×</button>
            </div>
            <div className="modal-body">
              {logsLoading ? (
                <div>{t('common.loading')}</div>
              ) : (
                <div className="table-responsive">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>{t('integrations.webhooks.log_event')}</th>
                        <th>{t('integrations.webhooks.log_status')}</th>
                        <th>{t('integrations.webhooks.log_response')}</th>
                        <th>{t('integrations.webhooks.log_attempt')}</th>
                        <th>{t('integrations.webhooks.log_sent')}</th>
                        <th>{t('sales.col.actions')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {deliveryLogs.length > 0 ? (
                        deliveryLogs.map((log) => (
                          <tr key={log.id}>
                            <td><strong>{log.event}</strong></td>
                            <td>
                              <span className={`status-badge ${log.status === 'delivered' ? 'completed' : 'cancelled'}`}>
                                {log.status}
                              </span>
                            </td>
                            <td>{log.response_status ?? 0}</td>
                            <td>{log.attempt}</td>
                            <td>{log.sent_at ? new Date(log.sent_at).toLocaleString() : ''}</td>
                            <td>
                              <button
                                className="btn-action"
                                onClick={() => handleRetryDelivery(log.id)}
                              >
                                {t('integrations.webhooks.retry')}
                              </button>
                            </td>
                          </tr>
                        ))
                      ) : (
                        <tr>
                          <td colSpan="6" className="empty-message">
                            {t('reports.no_data')}
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setSelectedWebhookLogs(null)}>
                {t('integrations.webhooks.close')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Generate API Key Modal */}
      {showAddKeyModal && (
        <div className="modal-backdrop">
          <div className="modal-dialog">
            <div className="modal-header">
              <h3>{t('integrations.keys.add')}</h3>
              <button className="btn-close" onClick={() => setShowAddKeyModal(false)}>×</button>
            </div>
            <form onSubmit={handleCreateApiKey}>
              <div className="modal-body">
                <div className="form-group">
                  <label>{t('integrations.keys.name')}</label>
                  <input
                    type="text"
                    required
                    value={keyName}
                    onChange={(e) => setKeyName(e.target.value)}
                  />
                </div>
              </div>
              <div className="modal-footer">
                <button type="button" className="btn-secondary" onClick={() => setShowAddKeyModal(false)}>
                  {t('integrations.webhooks.cancel')}
                </button>
                <button type="submit" className="btn-primary">
                  {t('integrations.keys.save')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Display Newly Created Key Modal */}
      {newlyCreatedKey && (
        <div className="modal-backdrop">
          <div className="modal-dialog">
            <div className="modal-header">
              <h3>{t('integrations.keys.generated_title')}</h3>
              <button className="btn-close" onClick={() => setNewlyCreatedKey(null)}>×</button>
            </div>
            <div className="modal-body">
              <p style={{ color: '#dc2626', fontWeight: 600, marginBottom: '1rem' }}>
                {t('integrations.keys.copy_warning')}
              </p>
              <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem' }}>
                <input
                  type="text"
                  readOnly
                  value={newlyCreatedKey}
                  style={{ fontFamily: 'monospace', flex: 1 }}
                />
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => {
                    navigator.clipboard.writeText(newlyCreatedKey);
                    setCopiedKey(true);
                  }}
                >
                  {copiedKey ? t('integrations.keys.copied') : t('integrations.keys.copy')}
                </button>
              </div>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-primary" onClick={() => setNewlyCreatedKey(null)}>
                {t('integrations.keys.done')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

