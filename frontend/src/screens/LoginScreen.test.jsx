import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginScreen from './LoginScreen';
import { AuthProvider } from '../auth/AuthContext';

beforeEach(() => {
  window.localStorage.clear();
  vi.restoreAllMocks();
});

function renderLoginScreen(onSuccess = vi.fn()) {
  return render(
    <AuthProvider>
      <LoginScreen onSuccess={onSuccess} />
    </AuthProvider>,
  );
}

async function fillAndSubmit() {
  fireEvent.input(screen.getByLabelText('Shop code'), { target: { value: 'SHOP-1' } });
  fireEvent.input(screen.getByLabelText('Username'), { target: { value: 'owner' } });
  fireEvent.input(screen.getByLabelText('Password'), { target: { value: 'secret' } });
  fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('LoginScreen', () => {
  it('calls onSuccess and stores the session after a successful login', async () => {
    const onSuccess = vi.fn();
    global.fetch = vi.fn().mockResolvedValue({
      json: async () => ({
        success: true,
        data: {
          access_token: 'tok',
          refresh_token: 'ref',
          user: { id: 1, username: 'owner' },
          tenant: { shop_code: 'SHOP-1', name: 'Test Shop', status: 'active' },
        },
      }),
    });

    renderLoginScreen(onSuccess);
    await fillAndSubmit();

    await waitFor(() => expect(onSuccess).toHaveBeenCalled());
    expect(window.localStorage.getItem('pos.auth.session')).toContain('SHOP-1');
  });

  it('shows a generic error message on invalid credentials, without saying which field was wrong', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      status: 401,
      json: async () => ({
        success: false,
        error: { code: 'UNAUTHENTICATED', message: 'Invalid credentials.' },
      }),
    });

    renderLoginScreen();
    await fillAndSubmit();

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Invalid shop code, username, or password.',
    );
  });

  it('shows a lockout-specific message when the account is locked (403)', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      status: 403,
      json: async () => ({ success: false, error: { code: 'FORBIDDEN', message: 'Locked.' } }),
    });

    renderLoginScreen();
    await fillAndSubmit();

    expect(await screen.findByRole('alert')).toHaveTextContent('temporarily locked');
  });
});
