import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Outlet, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PosScreen from './PosScreen';
import { OfflineProvider } from '../offline/OfflineContext';

const BASE_CONFIG = {
  branches: [{ id: 1, name: 'Main Branch' }],
  warehouses: [
    { id: 1, branch_id: 1, code: 'WH1', name: 'Main Warehouse', is_default_sales: true },
  ],
  registers: [{ id: 1, branch_id: 1, warehouse_id: 1, code: 'REG1', name: 'Register 1' }],
  price_lists: [{ id: 1, code: 'retail', name: 'Retail', is_default: true }],
  payment_methods: [
    { id: 1, code: 'cash', name: 'Cash', confirmation_mode: 'instant', allows_overpayment: true },
  ],
  units: [{ id: 1, code: 'pcs', name: 'Pieces' }],
  permissions: ['pos.sales.create', 'pos.shift.open', 'pos.shift.close'],
  // Not a real ISO code on purpose - scripts/check-dynamic-only.js's
  // currency-literal-frontend rule flags real currency codes/symbols
  // anywhere in frontend/src, test fixtures included; PosScreen only ever
  // treats this value as an opaque display string from the config snapshot.
  settings: { 'locale.currency_code': 'TCUR', 'sales.shift_required': false },
};

function renderPosScreen(config) {
  return render(
    <MemoryRouter initialEntries={['/pos']}>
      <OfflineProvider deviceId="DEV-1" userId={1}>
        <Routes>
          <Route path="/" element={<Outlet context={config} />}>
            <Route path="pos" element={<PosScreen />} />
          </Route>
        </Routes>
      </OfflineProvider>
    </MemoryRouter>,
  );
}

function mockFetch(overrides = {}) {
  global.fetch = vi.fn((url, options) => {
    const u = String(url);

    if (u.includes('/shifts/current')) {
      return Promise.resolve({
        json: async () => ({ success: true, data: overrides.shift ?? null }),
      });
    }
    if (u.includes('/products')) {
      return Promise.resolve({
        json: async () => ({
          success: true,
          data: overrides.products ?? [
            {
              id: 1,
              sku: 'SKU-APPLE',
              name: 'Apple',
              base_unit_id: 1,
              barcodes: [],
              prices: [{ unit_id: 1, variant_key: 0, price: '30.0000' }],
              stock_quantity: '10.0000',
            },
          ],
          meta: { page: 1, per_page: 10, total: 1, last_page: 1 },
        }),
      });
    }
    if (u.includes('/customers')) {
      return Promise.resolve({ json: async () => ({ success: true, data: [] }) });
    }
    if (u.includes('/shifts/open')) {
      return Promise.resolve({
        json: async () => ({
          success: true,
          data: { id: 99, opening_cash: '200.0000', opened_at: '2026-01-01T00:00:00Z' },
        }),
      });
    }
    if (u.match(/\/shifts\/\d+\/close/)) {
      return Promise.resolve({
        json: async () => ({
          success: true,
          data: {
            id: 99,
            expected_cash: '200.0000',
            counted_cash: '200.0000',
            cash_difference: '0.0000',
          },
        }),
      });
    }
    if (u.includes('/sync/push')) {
      const body = JSON.parse(options.body);
      const op = body.operations[0];
      return Promise.resolve({
        json: async () => ({
          success: true,
          data: {
            results: [
              {
                operation_uuid: op.operation_uuid,
                status: 'accepted',
                reject_reason: null,
                message: null,
                result: {
                  sale_id: 1,
                  uuid: 'sale-uuid-1',
                  invoice: 'INV-0001',
                  grand_total: '30.0000',
                },
                replay: false,
              },
            ],
          },
        }),
      });
    }

    return Promise.resolve({
      json: async () => ({ success: false, error: { code: 'NOT_FOUND' } }),
    });
  });
}

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('PosScreen', () => {
  it('searches products, adds one to the cart, and completes a sale through the offline outbox', async () => {
    mockFetch();
    renderPosScreen(BASE_CONFIG);

    fireEvent.input(screen.getByLabelText('Search products'), { target: { value: 'apple' } });
    await waitFor(() => expect(screen.getByText('Apple')).toBeInTheDocument(), { timeout: 2000 });

    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(await screen.findByDisplayValue('1.0000')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Charge' }));

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('INV-0001'), {
      timeout: 2000,
    });
    expect(screen.getByText('Cart is empty. Search for a product to add it.')).toBeInTheDocument();
  });

  it('blocks checkout until a shift is opened when sales.shift_required is on, then allows it', async () => {
    mockFetch();
    renderPosScreen({
      ...BASE_CONFIG,
      settings: { ...BASE_CONFIG.settings, 'sales.shift_required': true },
    });

    fireEvent.input(screen.getByLabelText('Search products'), { target: { value: 'apple' } });
    await waitFor(() => expect(screen.getByText('Apple')).toBeInTheDocument(), { timeout: 2000 });
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    await screen.findByDisplayValue('1.0000');

    fireEvent.click(screen.getByRole('button', { name: 'Charge' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Open a shift before billing.');

    fireEvent.click(screen.getByRole('button', { name: 'Open shift' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Close shift' })).toBeInTheDocument(),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Charge' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('INV-0001'), {
      timeout: 2000,
    });
  });

  it('shows the "closed" confirmation right after closing, and clears it once a new shift opens', async () => {
    mockFetch();
    renderPosScreen(BASE_CONFIG);

    fireEvent.click(await screen.findByRole('button', { name: 'Open shift' }));
    await screen.findByRole('button', { name: 'Close shift' });

    fireEvent.click(screen.getByRole('button', { name: 'Close shift' }));
    expect(await screen.findByText(/Shift closed\. Cash difference/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open shift' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Open shift' }));
    await screen.findByRole('button', { name: 'Close shift' });

    expect(screen.queryByText(/Shift closed\. Cash difference/)).not.toBeInTheDocument();
  });
});
