import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppShell from './AppShell';
import { AuthProvider } from '../auth/AuthContext';

beforeEach(() => {
  window.localStorage.setItem(
    'pos.auth.session',
    JSON.stringify({
      accessToken: 'tok',
      refreshToken: 'ref',
      user: { username: 'owner' },
      tenant: { name: 'Test Shop' },
    }),
  );
  vi.restoreAllMocks();
});

describe('AppShell', () => {
  it('renders the menu entries returned by GET /tenant/config and nothing hardcoded', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      json: async () => ({
        success: true,
        data: {
          modules: ['pos'],
          menu: [{ code: 'pos', label_key: 'menu.pos', path: '/pos' }],
          branches: [],
          permissions: [],
          settings: {},
        },
      }),
    });

    render(
      <AuthProvider>
        <AppShell />
      </AuthProvider>,
    );

    await waitFor(() => expect(screen.getByRole('link')).toHaveAttribute('href', '/pos'));
  });

  it('shows the empty-menu guidance when no modules are enabled, rather than a blank screen', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      json: async () => ({
        success: true,
        data: { modules: [], menu: [], branches: [], permissions: [], settings: {} },
      }),
    });

    render(
      <AuthProvider>
        <AppShell />
      </AuthProvider>,
    );

    expect(
      await screen.findByText('No menu items are enabled for this account yet.'),
    ).toBeInTheDocument();
  });
});
