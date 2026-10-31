import { useEffect, useState } from 'react';
import { useAuth } from '../auth/AuthContext';
import { apiRequest } from '../api/client';
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
    <div className="app-shell">
      <header className="app-shell-header">
        <span>{session?.tenant?.name}</span>
        <button type="button" onClick={logout}>
          {t('app.logout')}
        </button>
      </header>
      <nav className="app-shell-menu" aria-label="Main menu">
        {config.menu.length === 0 && <p>{t('app.menu.empty')}</p>}
        <ul>
          {config.menu.map((item) => (
            <li key={item.code}>
              <a href={item.path}>{t(item.label_key)}</a>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
