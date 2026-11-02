import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SalesScreen } from './SalesScreen';
import { ProductsScreen } from './ProductsScreen';
import { InventoryScreen } from './InventoryScreen';
import { CustomersScreen } from './CustomersScreen';
import { ReportsScreen } from './ReportsScreen';
import { TransfersScreen } from './TransfersScreen';
import { ReconciliationsScreen } from './ReconciliationsScreen';
import { TemplatesScreen } from './TemplatesScreen';
import { AuthProvider } from '../auth/AuthContext';
import { t } from '../i18n';

beforeEach(() => {
  window.localStorage.setItem(
    'pos.auth.session',
    JSON.stringify({
      accessToken: 'test-token',
      refreshToken: 'test-refresh',
      user: { id: 1, username: 'owner' },
      tenant: { name: 'Test Shop' },
      deviceId: 'DEV-1',
    }),
  );
  vi.restoreAllMocks();
});

describe('Management & Operational Dashboard Screens', () => {
  describe('SalesScreen', () => {
    it('fetches and displays sales list and opens receipt modal', async () => {
      global.fetch = vi.fn().mockImplementation((url) => {
        if (url.includes('/sales/101')) {
          return Promise.resolve({
            json: async () => ({
              success: true,
              data: {
                id: 101,
                invoice_number: 'INV-101',
                created_at: '2026-11-01T10:00:00Z',
                cashier: 'Alice',
                customer: { name: 'Bob', phone: '1234567890' },
                items: [
                  { id: 1, name: 'Widget A', quantity: '2.0000', unit_price: '50.0000', line_total: '100.0000' },
                ],
                line_subtotal: '100.0000',
                tax_total: '0.0000',
                discount_total: '0.0000',
                grand_total: '100.0000',
              },
            }),
          });
        }
        return Promise.resolve({
          json: async () => ({
            success: true,
            data: [
              {
                id: 101,
                invoice_number: 'INV-101',
                created_at: '2026-11-01T10:00:00Z',
                cashier: 'Alice',
                customer: { name: 'Bob' },
                grand_total: '100.0000',
                status: 'completed',
              },
            ],
          }),
        });
      });

      render(
        <AuthProvider>
          <SalesScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('INV-101')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
      });

      // Click view receipt
      fireEvent.click(screen.getByText(t('sales.action.view')));

      await waitFor(() => {
        expect(screen.getByText(t('receipt.title'))).toBeInTheDocument();
        expect(screen.getByText('Widget A')).toBeInTheDocument();
      });
    });
  });

  describe('ProductsScreen', () => {
    it('fetches and renders product catalogue', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        json: async () => ({
          success: true,
          data: [
            {
              id: 1,
              sku: 'SKU-001',
              name: 'Sample Product',
              category: 'General',
              unit: 'pcs',
              default_price: '25.5000',
              stock_on_hand: '150.0000',
            },
          ],
        }),
      });

      render(
        <AuthProvider>
          <ProductsScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('SKU-001')).toBeInTheDocument();
        expect(screen.getByText('Sample Product')).toBeInTheDocument();
        expect(screen.getByText('150.0000')).toBeInTheDocument();
      });

      // Open new product modal
      fireEvent.click(screen.getByText(`+ ${t('products.add')}`));
      expect(screen.getByText(t('products.form.title'))).toBeInTheDocument();
    });
  });

  describe('InventoryScreen', () => {
    it('fetches stock balances and highlights low-stock items', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        json: async () => ({
          success: true,
          data: [
            {
              id: 1,
              product_id: 10,
              warehouse_name: 'Main Warehouse',
              product_name: 'Critical Widget',
              sku: 'WIDGET-01',
              unit: 'pcs',
              quantity: '2.0000',
              average_cost: '10.0000',
              total_value: '20.0000',
              is_low_stock: true,
            },
          ],
        }),
      });

      render(
        <AuthProvider>
          <InventoryScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('Critical Widget')).toBeInTheDocument();
        expect(screen.getByText('WIDGET-01')).toBeInTheDocument();
        expect(screen.getByText(t('inventory.low_stock'))).toBeInTheDocument();
      });

      // Open adjustment modal
      fireEvent.click(screen.getByText(`+ ${t('inventory.adjust')}`));
      expect(screen.getByText(t('inventory.adjust.title'))).toBeInTheDocument();
    });

    it('opens cost layers modal and displays active FIFO layers', async () => {
      global.fetch = vi.fn().mockImplementation((url) => {
        if (url.includes('/inventory/cost-layers')) {
          return Promise.resolve({
            json: async () => ({
              success: true,
              data: [
                {
                  id: 42,
                  warehouse_id: 1,
                  product_id: 10,
                  quantity_remaining: '8.0000',
                  unit_cost: '12.5000',
                  total_value: '100.0000',
                  received_at: '2026-11-01T10:00:00.000Z',
                },
              ],
            }),
          });
        }
        return Promise.resolve({
          json: async () => ({
            success: true,
            data: [
              {
                id: 1,
                product_id: 10,
                warehouse_id: 1,
                warehouse_name: 'Main Warehouse',
                product_name: 'FIFO Item',
                sku: 'FIFO-01',
                unit: 'pcs',
                quantity: '8.0000',
                average_cost: '12.5000',
                total_value: '100.0000',
                is_low_stock: false,
              },
            ],
          }),
        });
      });

      render(
        <AuthProvider>
          <InventoryScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('FIFO Item')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByText(t('inventory.action.view_layers')));

      await waitFor(() => {
        expect(screen.getByText(/FIFO Cost Layers/i)).toBeInTheDocument();
        expect(screen.getByText('8.0000')).toBeInTheDocument();
        expect(screen.getByText('12.5000')).toBeInTheDocument();
      });
    });
  });

  describe('CustomersScreen', () => {
    it('renders customer list and opens ledger modal on demand', async () => {
      global.fetch = vi.fn().mockImplementation((url) => {
        if (url.includes('/ledger')) {
          return Promise.resolve({
            json: async () => ({
              success: true,
              data: [
                {
                  id: 1,
                  occurred_at: '2026-11-01T11:00:00Z',
                  source_type: 'sale',
                  direction: 'debit',
                  amount: '500.0000',
                  balance_after: '500.0000',
                },
              ],
            }),
          });
        }
        return Promise.resolve({
          json: async () => ({
            success: true,
            data: [
              {
                id: 1,
                code: 'CUST-001',
                name: 'ACME Corp',
                phone: '9876543210',
                credit_limit: '5000.0000',
                current_balance: '500.0000',
              },
            ],
          }),
        });
      });

      render(
        <AuthProvider>
          <CustomersScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('CUST-001')).toBeInTheDocument();
        expect(screen.getByText('ACME Corp')).toBeInTheDocument();
      });

      // Open ledger
      fireEvent.click(screen.getByText(t('customers.ledger')));

      await waitFor(() => {
        expect(screen.getByText('DEBIT')).toBeInTheDocument();
        expect(screen.getAllByText('500.0000').length).toBeGreaterThanOrEqual(2);
      });
    });
  });

  describe('ReportsScreen', () => {
    it('fetches summary and displays all 7 operational financial metrics', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        json: async () => ({
          success: true,
          data: {
            gross_sales: '12500.0000',
            net_sales: '12000.0000',
            tax_total: '500.0000',
            cogs: '8000.0000',
            gross_profit: '4000.0000',
            stock_valuation: '45000.0000',
            outstanding_balance: '3500.0000',
          },
        }),
      });

      render(
        <AuthProvider>
          <ReportsScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('12500.0000')).toBeInTheDocument();
        expect(screen.getByText('12000.0000')).toBeInTheDocument();
        expect(screen.getByText('4000.0000')).toBeInTheDocument();
        expect(screen.getByText('45000.0000')).toBeInTheDocument();
        expect(screen.getByText('3500.0000')).toBeInTheDocument();
      });
    });

    it('switches between tabs and displays financial reports', async () => {
      global.fetch = vi.fn().mockImplementation((url) => {
        if (url.includes('/reports/tax-breakdown')) {
          return Promise.resolve({
            json: async () => ({
              success: true,
              data: {
                components: [
                  {
                    tax_code: 'GST18',
                    taxable_amount: '1000.0000',
                    collected_amount: '180.0000',
                    refunded_amount: '0.0000',
                    net_amount: '180.0000',
                  },
                ],
                totals: {
                  taxable_amount: '1000.0000',
                  collected_amount: '180.0000',
                  refunded_amount: '0.0000',
                  net_amount: '180.0000',
                },
              },
            }),
          });
        }
        return Promise.resolve({
          json: async () => ({
            success: true,
            data: {
              gross_sales: '1000.0000',
              net_sales: '1000.0000',
              tax_total: '180.0000',
              cogs: '600.0000',
              gross_profit: '400.0000',
              stock_valuation: '5000.0000',
              outstanding_balance: '0.0000',
            },
          }),
        });
      });

      render(
        <AuthProvider>
          <ReportsScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getAllByText('1000.0000').length).toBeGreaterThanOrEqual(1);
      });

      fireEvent.click(screen.getByText(t('reports.tab.tax')));

      await waitFor(() => {
        expect(screen.getByText('GST18')).toBeInTheDocument();
        expect(screen.getAllByText('180.0000').length).toBeGreaterThanOrEqual(1);
      });
    });
  });

  describe('TransfersScreen', () => {
    it('fetches and renders transfers and allows creating a transfer', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        json: async () => ({
          success: true,
          data: [
            {
              id: 1,
              transfer_number: 'TRF-TEST-01',
              source_warehouse: 'Central Warehouse',
              
              destination_warehouse: 'Outlet 1',
              status: 'dispatched',
              dispatched_at: '2026-11-01T10:00:00Z',
            },
          ],
        }),
      });

      render(
        <AuthProvider>
          <TransfersScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('TRF-TEST-01')).toBeInTheDocument();
        expect(screen.getByText('Central Warehouse')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByText(`+ ${t('transfers.create')}`));
      expect(screen.getByText(t('transfers.modal.create_title'))).toBeInTheDocument();
    });
  });

  describe('ReconciliationsScreen', () => {
    it('fetches and renders reconciliations list and opens start modal', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        json: async () => ({
          success: true,
          data: [
            {
              id: 1,
              reference_number: 'REC-TEST-01',
              warehouse: 'Central Warehouse',
              status: 'in_progress',
            },
          ],
        }),
      });

      render(
        <AuthProvider>
          <ReconciliationsScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('REC-TEST-01')).toBeInTheDocument();
      });

      fireEvent.click(screen.getByText(`+ ${t('reconciliations.start')}`));
      expect(screen.getByText(t('reconciliations.modal.start_title'))).toBeInTheDocument();
    });
  });

  describe('TemplatesScreen', () => {
    it('fetches and renders available business templates and opens details modal', async () => {
      global.fetch = vi.fn().mockImplementation((url) => {
        if (url.includes('/template/update-preview')) {
          return Promise.resolve({
            json: async () => ({
              success: true,
              data: {
                current_version: 1,
                target_version: 2,
                added_modules: ['variants'],
                added_units: [{ code: 'mtr', name: 'Meters' }],
                added_categories: [{ code: 'FABRIC', name: 'Fabrics' }],
                added_custom_fields: [{ code: 'fabric', label: 'Fabric / Material' }],
              },
            }),
          });
        }
        return Promise.resolve({
          json: async () => ({
            success: true,
            data: [
              {
                id: 1,
                code: 'textile',
                name: 'Textile & Apparel',
                description: 'Apparel, footwear, fabrics',
                version: 1,
                modules: ['variants', 'catalog'],
                units: [{ code: 'mtr', name: 'Meters' }],
                categories: [{ code: 'MEN', name: "Men's Apparel" }],
                custom_fields: [{ code: 'fabric', label: 'Fabric / Material', type: 'text' }],
                lookups: [{ code: 'season', name: 'Season', values: [{ code: 'summer', label: 'Summer' }] }],
              },
            ],
          }),
        });
      });

      render(
        <AuthProvider>
          <TemplatesScreen />
        </AuthProvider>,
      );

      await waitFor(() => {
        expect(screen.getByText('Textile & Apparel')).toBeInTheDocument();
        expect(screen.getByText('textile')).toBeInTheDocument();
      });

      // Open details modal
      fireEvent.click(screen.getByText(t('templates.action.view')));
      await waitFor(() => {
        expect(screen.getByRole('heading', { name: new RegExp(t('templates.details.title'), 'i') })).toBeInTheDocument();
        expect(screen.getByText(/Fabric \/ Material/i)).toBeInTheDocument();
      });

      // Close modal
      fireEvent.click(screen.getByText(t('common.close')));

      // Check updates preview
      fireEvent.click(screen.getByText(t('templates.action.check_updates')));
      await waitFor(() => {
        expect(screen.getByText(t('templates.update_preview'))).toBeInTheDocument();
      });
    });
  });
});
