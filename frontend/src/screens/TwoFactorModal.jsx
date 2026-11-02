import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import './ManagementScreens.css';

export function TwoFactorModal({ isOpen, onClose }) {
  const { session } = useAuth();
  const [enabled, setEnabled] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);

  // Setup state
  const [setupData, setSetupData] = useState(null);
  const [confirmCode, setConfirmCode] = useState('');
  const [confirming, setConfirming] = useState(false);

  // Disable state
  const [showDisableForm, setShowDisableForm] = useState(false);
  const [password, setPassword] = useState('');
  const [disabling, setDisabling] = useState(false);

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1';

  useEffect(() => {
    let cancelled = false;
    const fetchStatus = async () => {
      if (!isOpen) return;
      setLoading(true);
      setError(null);
      try {
        const res = await fetch(`${apiBase}/auth/me`, {
          headers: {
            Authorization: `Bearer ${session?.tokens?.access_token || session?.accessToken}`,
            Accept: 'application/json',
          },
        });
        const data = await res.json();
        if (!cancelled && data.success) {
          setEnabled(Boolean(data.data.two_factor_enabled));
        }
      } catch (err) {
        if (!cancelled) setError(err.message);
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    fetchStatus();

    return () => {
      cancelled = true;
    };
  }, [isOpen, apiBase, session?.tokens?.access_token, session?.accessToken]);

  if (!isOpen) return null;

  const handleStartSetup = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch(`${apiBase}/auth/2fa/setup`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token || session?.accessToken}`,
          Accept: 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        setSetupData(data.data);
      } else {
        setError(data.error?.message || t('common.error'));
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleConfirmSetup = async (e) => {
    e.preventDefault();
    setConfirming(true);
    setError(null);
    try {
      const res = await fetch(`${apiBase}/auth/2fa/confirm`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token || session?.accessToken}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ code: confirmCode }),
      });
      const data = await res.json();
      if (data.success) {
        setEnabled(true);
        setSetupData(null);
        setConfirmCode('');
        setSuccess(t('auth.2fa.enabled'));
      } else {
        setError(data.error?.message || t('auth.2fa.invalid_code'));
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setConfirming(false);
    }
  };

  const handleDisable = async (e) => {
    e.preventDefault();
    setDisabling(true);
    setError(null);
    try {
      const res = await fetch(`${apiBase}/auth/2fa/disable`, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session?.tokens?.access_token || session?.accessToken}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ password }),
      });
      const data = await res.json();
      if (data.success) {
        setEnabled(false);
        setShowDisableForm(false);
        setPassword('');
        setSuccess(t('auth.2fa.disabled'));
      } else {
        setError(data.error?.message || t('auth.login.failed'));
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setDisabling(false);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h3>{t('auth.2fa.title')}</h3>
          <button
            type="button"
            className="btn-close"
            onClick={onClose}
            aria-label={t('common.close')}
          >
            &times;
          </button>
        </div>

        <div className="modal-body">
          {error && <div className="state-panel error mb-3">{error}</div>}
          {success && <div className="alert-success mb-3">{success}</div>}

          {loading ? (
            <div className="state-panel">{t('common.loading')}</div>
          ) : setupData ? (
            <div>
              <p className="mb-2 font-bold">{t('auth.2fa.setup_title')}</p>
              <p className="mb-2">{t('auth.2fa.manual_secret')}</p>
              <div className="font-mono p-2 bg-neutral mb-3">{setupData.secret}</div>

              {setupData.recovery_codes?.length > 0 && (
                <div className="mb-4">
                  <p className="font-bold mb-1">{t('auth.2fa.recovery_warning')}</p>
                  <div className="badge-list">
                    {setupData.recovery_codes.map((rc) => (
                      <span key={rc} className="badge-neutral font-mono mr-1 mb-1">
                        {rc}
                      </span>
                    ))}
                  </div>
                </div>
              )}

              <form onSubmit={handleConfirmSetup}>
                <div className="form-group">
                  <label htmlFor="confirm_2fa_code">{t('auth.2fa.enter_code_confirm')}</label>
                  <input
                    id="confirm_2fa_code"
                    type="text"
                    required
                    value={confirmCode}
                    onChange={(e) => setConfirmCode(e.target.value)}
                    placeholder="123456"
                  />
                </div>
                <div className="modal-actions">
                  <button type="submit" className="btn-primary" disabled={confirming}>
                    {confirming ? t('auth.2fa.verifying') : t('auth.2fa.confirm')}
                  </button>
                  <button
                    type="button"
                    className="btn-secondary"
                    onClick={() => setSetupData(null)}
                  >
                    {t('common.cancel')}
                  </button>
                </div>
              </form>
            </div>
          ) : enabled ? (
            <div>
              <div className="alert-success mb-3">{t('auth.2fa.enabled')}</div>

              {showDisableForm ? (
                <form onSubmit={handleDisable}>
                  <div className="form-group">
                    <label htmlFor="disable_2fa_pw">{t('auth.2fa.confirm_password')}</label>
                    <input
                      id="disable_2fa_pw"
                      type="password"
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                    />
                  </div>
                  <div className="modal-actions">
                    <button type="submit" className="btn-danger" disabled={disabling}>
                      {disabling ? t('common.loading') : t('auth.2fa.disable')}
                    </button>
                    <button
                      type="button"
                      className="btn-secondary"
                      onClick={() => setShowDisableForm(false)}
                    >
                      {t('common.cancel')}
                    </button>
                  </div>
                </form>
              ) : (
                <button
                  type="button"
                  className="btn-danger"
                  onClick={() => setShowDisableForm(true)}
                >
                  {t('auth.2fa.disable_button')}
                </button>
              )}
            </div>
          ) : (
            <div>
              <p className="mb-3">{t('auth.2fa.disabled')}</p>
              <button
                type="button"
                className="btn-primary"
                onClick={handleStartSetup}
              >
                {t('auth.2fa.enable_button')}
              </button>
            </div>
          )}
        </div>

        <div className="modal-actions">
          <button type="button" className="btn-secondary" onClick={onClose}>
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  );
}

