import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import { ApiError } from '../api/client';
import './LoginScreen.css';

export default function LoginScreen({ onSuccess }) {
  const { login, verifyTwoFactor } = useAuth();
  const { register, handleSubmit, formState } = useForm();
  const [errorMessage, setErrorMessage] = useState(null);
  const [twoFactorToken, setTwoFactorToken] = useState(null);
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [verifyingTwoFactor, setVerifyingTwoFactor] = useState(false);

  const onSubmit = async (values) => {
    setErrorMessage(null);
    try {
      const result = await login(values.shop_code, values.username, values.password);
      if (result?.twoFactorRequired) {
        setTwoFactorToken(result.twoFactorToken);
        return;
      }
      onSuccess?.();
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setErrorMessage(t('auth.login.locked'));
      } else {
        setErrorMessage(t('auth.login.failed'));
      }
    }
  };

  const onVerifyTwoFactor = async (e) => {
    e.preventDefault();
    setErrorMessage(null);
    setVerifyingTwoFactor(true);
    try {
      await verifyTwoFactor(twoFactorToken, twoFactorCode);
      onSuccess?.();
    } catch {
      setErrorMessage(t('auth.2fa.invalid_code'));
    } finally {
      setVerifyingTwoFactor(false);
    }
  };

  if (twoFactorToken) {
    return (
      <main className="login-screen">
        <form className="login-form" onSubmit={onVerifyTwoFactor}>
          <h1>{t('auth.2fa.title')}</h1>
          <p className="login-prompt">{t('auth.2fa.prompt')}</p>

          <label htmlFor="two_factor_code">{t('auth.2fa.code')}</label>
          <input
            id="two_factor_code"
            type="text"
            required
            autoComplete="one-time-code"
            value={twoFactorCode}
            onChange={(e) => setTwoFactorCode(e.target.value)}
          />

          {errorMessage && (
            <p role="alert" className="login-error">
              {errorMessage}
            </p>
          )}

          <button type="submit" disabled={verifyingTwoFactor}>
            {verifyingTwoFactor ? t('auth.2fa.verifying') : t('auth.2fa.verify')}
          </button>

          <button
            type="button"
            className="btn-secondary mt-2"
            onClick={() => {
              setTwoFactorToken(null);
              setErrorMessage(null);
            }}
          >
            {t('common.cancel')}
          </button>
        </form>
      </main>
    );
  }

  return (
    <main className="login-screen">
      <form className="login-form" onSubmit={handleSubmit(onSubmit)}>
        <h1>{t('auth.login.title')}</h1>

        <label htmlFor="shop_code">{t('auth.login.shop_code')}</label>
        <input
          id="shop_code"
          type="text"
          autoComplete="off"
          {...register('shop_code', { required: true })}
        />

        <label htmlFor="username">{t('auth.login.username')}</label>
        <input
          id="username"
          type="text"
          autoComplete="username"
          {...register('username', { required: true })}
        />

        <label htmlFor="password">{t('auth.login.password')}</label>
        <input
          id="password"
          type="password"
          autoComplete="current-password"
          {...register('password', { required: true })}
        />

        {errorMessage && (
          <p role="alert" className="login-error">
            {errorMessage}
          </p>
        )}

        <button type="submit" disabled={formState.isSubmitting}>
          {t('auth.login.submit')}
        </button>
      </form>
    </main>
  );
}
