import { useEffect, useState } from 'react';
import { Link, Outlet } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { apiRequest } from '../api/client';
import { OfflineProvider, useOffline } from '../offline/OfflineContext';
import { t } from '../i18n';
import './AppShell.css';

/**
 * section 11 "Schema-driven UI rules": "menu built only from GET
 * /tenant/config (no static menu)". There is deliberately no hardcoded
 * list of menu items anywhere in this component - disabling a module on
 * the backend removes it from `config.menu` and it disappears here with
 * no frontend deployment (proven server-side in
 * tests/Feature/Configuration/ModuleDisabledTest.php).
 */
export default function AppShell() {
  const { session, logout } = useAuth();
  const [config, setConfig] = useState(null);
  const [state, setState] = useState('loading'); // loading | ready | error

  useEffect(() => {
    let cancelled = false;

    apiRequest('/tenant/config')
      .then((data) => {
        if (!cancelled) {
          setConfig(data);
          setState('ready');
        }
      })
      .catch(() => {
        if (!cancelled) {
          setState('error');
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  if (state === 'loading') {
    return <p className="app-shell-status">{t('app.boot.subtitle')}</p>;
  }

  if (state === 'error') {
    return (
      <p className="app-shell-status" role="alert">
        {t('auth.login.failed')}
      </p>
    );
  }

  return (
    <OfflineProvider deviceId={session?.deviceId} userId={session?.user?.id}>
      <div className="app-shell">
        <header className="app-shell-header">
          <span>{session?.tenant?.name}</span>
          <ConnectionBadge />
          <button type="button" onClick={logout}>
            {t('app.logout')}
          </button>
        </header>
        <nav className="app-shell-menu" aria-label="Main menu">
          {config.menu.length === 0 && <p>{t('app.menu.empty')}</p>}
          <ul>
            {config.menu.map((item) => (
              <li key={item.code}>
                <Link to={item.path}>{t(item.label_key)}</Link>
              </li>
            ))}
          </ul>
        </nav>
        <main className="app-shell-content">
          <Outlet context={config} />
        </main>
      </div>
    </OfflineProvider>
  );
}

/**
 * section 9 "Connection badge (Online / Offline / Syncing / Sync Error)
 * with pending count on every screen" - lives in the shell (every routed
 * screen renders inside it via <Outlet>), not duplicated per screen.
 */
function ConnectionBadge() {
  const { status, flush } = useOffline();
  const isOnline = typeof navigator === 'undefined' ? true : navigator.onLine;

  const label = !isOnline
    ? t('sync.badge.offline')
    : status.state === 'syncing'
      ? t('sync.badge.syncing')
      : status.state === 'error'
        ? t('sync.badge.error')
        : status.pending > 0
          ? t('sync.badge.pending')
          : t('sync.badge.online');

  return (
    <div className={`connection-badge connection-badge--${status.state}`}>
      <span>{label}</span>
      {status.pending > 0 && <span className="connection-badge-count">{status.pending}</span>}
      <button type="button" onClick={flush}>
        {t('sync.badge.sync_now')}
      </button>
    </div>
  );
}
