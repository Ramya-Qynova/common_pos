import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n';
import { ApiError } from '../api/client';
import './LoginScreen.css';

export default function LoginScreen({ onSuccess }) {
  const { login } = useAuth();
  const { register, handleSubmit, formState } = useForm();
  const [errorMessage, setErrorMessage] = useState(null);

  const onSubmit = async (values) => {
    setErrorMessage(null);
    try {
      await login(values.shop_code, values.username, values.password);
      onSuccess?.();
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) {
        setErrorMessage(t('auth.login.locked'));
      } else {
        setErrorMessage(t('auth.login.failed'));
      }
    }
  };

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
