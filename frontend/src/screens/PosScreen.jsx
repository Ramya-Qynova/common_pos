import Decimal from 'decimal.js';
import { useEffect, useMemo, useState } from 'react';
import { useOutletContext } from 'react-router-dom';
import { apiRequest } from '../api/client';
import { useOffline } from '../offline/OfflineContext';
import { t } from '../i18n';
import './PosScreen.css';

/**
 * POS-01 (billing screen, section 11's POS-xx group). Everything it reads
 * (branches/registers/warehouses/price lists/payment methods/units,
 * permissions, settings) comes from GET /tenant/config via the shell's
 * <Outlet context> - section 11 "no screen has hardcoded/mock business
 * data." Every write goes through the Phase 7 offline outbox
 * (`useOffline().enqueueSale`) and is flushed immediately when online, so
 * online and offline billing are the exact same code path (section 9).
 */
const EMPTY_LIST = [];

export default function PosScreen() {
  const config = useOutletContext();
  const { enqueueSale } = useOffline();

  const branches = config.branches ?? EMPTY_LIST;
  const registers = config.registers ?? EMPTY_LIST;
  const priceLists = config.price_lists ?? EMPTY_LIST;
  const paymentMethods = config.payment_methods ?? EMPTY_LIST;
  const permissions = config.permissions ?? EMPTY_LIST;
  const currencyCode = config.settings?.['locale.currency_code'] ?? '';
  const shiftRequired = Boolean(config.settings?.['sales.shift_required']);

  const [branchId, setBranchId] = useState(branches[0]?.id ?? null);
  const branchRegisters = useMemo(
    () => registers.filter((r) => r.branch_id === branchId),
    [registers, branchId],
  );
  // Derived, not stored: avoids resetting state inside an effect every time
  // the branch changes - when the previously picked register no longer
  // belongs to this branch, the first register of the new branch is used
  // automatically until the cashier explicitly picks another one.
  const [pickedRegisterId, setPickedRegisterId] = useState(null);
  const registerId = branchRegisters.some((r) => r.id === pickedRegisterId)
    ? pickedRegisterId
    : (branchRegisters[0]?.id ?? null);
  const register = registers.find((r) => r.id === registerId);
  const warehouseId = register?.warehouse_id ?? null;

  const [priceListId, setPriceListId] = useState(
    priceLists.find((p) => p.is_default)?.id ?? priceLists[0]?.id ?? null,
  );
  const [paymentMethodId, setPaymentMethodId] = useState(
    paymentMethods.find((m) => m.code === 'cash')?.id ?? paymentMethods[0]?.id ?? null,
  );

  const shift = useShift(registerId);
  const [search, setSearch] = useState('');
  const products = useProductSearch(search, warehouseId, priceListId);
  const [cart, setCart] = useState([]);
  const customer = useCustomerPicker();

  const subtotal = useMemo(
    () =>
      cart
        .reduce(
          (sum, line) => sum.plus(new Decimal(line.unitPrice).mul(line.quantity)),
          new Decimal(0),
        )
        .toFixed(4),
    [cart],
  );

  const [checkout, setCheckout] = useState({ state: 'idle', message: null, lastResult: null });

  function addToCart(product) {
    const price = product.prices?.[0];
    if (!price) {
      return;
    }
    setCart((prev) => {
      const existing = prev.find((l) => l.productId === product.id && l.unitId === price.unit_id);
      if (existing) {
        return prev.map((l) =>
          l === existing ? { ...l, quantity: new Decimal(l.quantity).plus(1).toFixed(4) } : l,
        );
      }
      return [
        ...prev,
        {
          productId: product.id,
          sku: product.sku,
          name: product.name,
          unitId: price.unit_id,
          unitPrice: price.price,
          quantity: '1.0000',
        },
      ];
    });
  }

  function changeQuantity(index, quantity) {
    setCart((prev) => prev.map((l, i) => (i === index ? { ...l, quantity } : l)));
  }

  function removeLine(index) {
    setCart((prev) => prev.filter((_, i) => i !== index));
  }

  async function handleCheckout() {
    if (cart.length === 0 || !registerId || !priceListId || !paymentMethodId) {
      return;
    }
    if (shiftRequired && shift.state !== 'open') {
      setCheckout({ state: 'error', message: t('pos.checkout.shift_required'), lastResult: null });
      return;
    }

    setCheckout({ state: 'submitting', message: null, lastResult: null });

    const payload = {
      branch_id: branchId,
      warehouse_id: warehouseId,
      register_id: registerId,
      cashier_shift_id: shift.state === 'open' ? shift.shift.id : null,
      customer_id: customer.selected?.id ?? null,
      price_list_id: priceListId,
      uuid: crypto.randomUUID(),
      lines: cart.map((line) => ({
        product_id: line.productId,
        unit_id: line.unitId,
        quantity: line.quantity,
      })),
      payments: [{ payment_method_id: paymentMethodId, amount: subtotal }],
      device_grand_total: subtotal,
      has_discount_permission: permissions.includes('pos.sales.discount'),
    };

    try {
      const settled = await enqueueSale(payload, { branchId, registerId });

      if (settled.status === 'synced') {
        setCheckout({
          state: 'success',
          message: t('pos.checkout.success', { invoice: settled.result?.invoice }),
          lastResult: settled.result,
        });
        setCart([]);
        customer.clear();
      } else if (settled.status === 'rejected') {
        setCheckout({
          state: 'error',
          message: settled.last_error ?? t('pos.checkout.rejected'),
          lastResult: null,
        });
      } else {
        // Still pending/failed for a transient reason (offline, network error) -
        // the sale IS durably queued (section 9's outbox), so it's safe to clear
        // the cart; it will sync on its own via the connection badge's retry.
        setCheckout({ state: 'success', message: t('pos.checkout.queued'), lastResult: null });
        setCart([]);
        customer.clear();
      }
    } catch (error) {
      setCheckout({ state: 'error', message: error.message, lastResult: null });
    }
  }

  if (branches.length === 0 || registers.length === 0) {
    return (
      <p className="pos-status" role="alert">
        {t('pos.no_branches')}
      </p>
    );
  }

  return (
    <div className="pos-screen">
      <div className="pos-column pos-column--search">
        <h2>{t('pos.title')}</h2>

        <div className="pos-selectors">
          <label>
            {t('pos.branch')}
            <select value={branchId ?? ''} onChange={(e) => setBranchId(Number(e.target.value))}>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            {t('pos.register')}
            <select
              value={registerId ?? ''}
              onChange={(e) => setPickedRegisterId(Number(e.target.value))}
            >
              {branchRegisters.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            {t('pos.price_list')}
            <select
              value={priceListId ?? ''}
              onChange={(e) => setPriceListId(Number(e.target.value))}
            >
              {priceLists.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
        </div>

        <ShiftPanel shift={shift} />

        <label className="pos-search-input">
          {t('pos.search.label')}
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('pos.search.placeholder')}
          />
        </label>

        <ProductResults products={products} onAdd={addToCart} currencyCode={currencyCode} />
      </div>

      <div className="pos-column pos-column--cart">
        <CustomerPicker customer={customer} />

        <Cart
          lines={cart}
          currencyCode={currencyCode}
          onChangeQuantity={changeQuantity}
          onRemove={removeLine}
        />

        <div className="pos-subtotal">
          <span>{t('pos.cart.subtotal')}</span>
          <strong>
            {currencyCode} {subtotal}
          </strong>
        </div>

        <label>
          {t('pos.payment_method')}
          <select
            value={paymentMethodId ?? ''}
            onChange={(e) => setPaymentMethodId(Number(e.target.value))}
          >
            {paymentMethods.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </label>

        <button
          type="button"
          disabled={cart.length === 0 || checkout.state === 'submitting'}
          onClick={handleCheckout}
        >
          {t('pos.checkout.button')}
        </button>

        {checkout.message && (
          <p
            role={checkout.state === 'error' ? 'alert' : 'status'}
            className={`pos-checkout-${checkout.state}`}
          >
            {checkout.message}
          </p>
        )}
      </div>
    </div>
  );
}

function useShift(registerId) {
  // `fetched.registerId` tracks which register the last completed request was
  // for; while it doesn't match the current `registerId`, 'loading' is a
  // DERIVED status rather than something set synchronously inside the effect
  // below (which would need an extra render-triggering setState at the top
  // of the effect body on every registerId/version change).
  const [fetched, setFetched] = useState({ registerId: null, state: 'none', shift: null });
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (!registerId) {
      return;
    }
    let cancelled = false;

    apiRequest(`/shifts/current?register_id=${registerId}`)
      .then((data) => {
        if (!cancelled) setFetched({ registerId, state: data ? 'open' : 'none', shift: data });
      })
      .catch(() => {
        if (!cancelled) setFetched({ registerId, state: 'error', shift: null });
      });

    return () => {
      cancelled = true;
    };
  }, [registerId, version]);

  const state = !registerId || fetched.registerId !== registerId ? 'loading' : fetched.state;
  const shift = fetched.registerId === registerId ? fetched.shift : null;

  return {
    state,
    shift,
    reload: () => setVersion((v) => v + 1),
    async open(openingCash) {
      const opened = await apiRequest('/shifts/open', {
        method: 'POST',
        body: { register_id: registerId, opening_cash: openingCash },
      });
      setFetched({ registerId, state: 'open', shift: opened });
    },
    async close(countedCash) {
      const closed = await apiRequest(`/shifts/${shift.id}/close`, {
        method: 'POST',
        body: { counted_cash: countedCash },
      });
      setFetched({ registerId, state: 'none', shift: null });
      return closed;
    },
  };
}

function ShiftPanel({ shift }) {
  const [openingCash, setOpeningCash] = useState('0.0000');
  const [countedCash, setCountedCash] = useState('0.0000');
  const [closeResult, setCloseResult] = useState(null);
  const [error, setError] = useState(null);

  if (shift.state === 'loading') {
    return <p className="pos-shift-panel">{t('pos.shift.loading')}</p>;
  }

  if (shift.state === 'error') {
    return (
      <p className="pos-shift-panel" role="alert">
        {t('pos.shift.error')}
      </p>
    );
  }

  if (shift.state === 'none') {
    return (
      <div className="pos-shift-panel">
        {/* `shift.close()` moves state straight to 'none' in the same
            update as `setCloseResult` below, so this is the ONLY branch
            that ever actually renders while `closeResult` is set - the
            confirmation belongs here (describing the shift that just
            ended), not in the 'open' branch (which only ever reflects the
            NEXT shift, never the one `closeResult` is about). */}
        {closeResult && (
          <p role="status">{t('pos.shift.closed', { difference: closeResult.cash_difference })}</p>
        )}
        <label>
          {t('pos.shift.opening_cash')}
          <input value={openingCash} onChange={(e) => setOpeningCash(e.target.value)} />
        </label>
        <button
          type="button"
          onClick={async () => {
            setError(null);
            setCloseResult(null);
            try {
              await shift.open(openingCash);
            } catch (e) {
              setError(e.message);
            }
          }}
        >
          {t('pos.shift.open_button')}
        </button>
        {error && <p role="alert">{error}</p>}
      </div>
    );
  }

  return (
    <div className="pos-shift-panel">
      <span>{t('pos.shift.open_since', { time: shift.shift?.opened_at })}</span>
      <label>
        {t('pos.shift.counted_cash')}
        <input value={countedCash} onChange={(e) => setCountedCash(e.target.value)} />
      </label>
      <button
        type="button"
        onClick={async () => {
          setError(null);
          try {
            const closed = await shift.close(countedCash);
            setCloseResult(closed);
          } catch (e) {
            setError(e.message);
          }
        }}
      >
        {t('pos.shift.close_button')}
      </button>
      {error && <p role="alert">{error}</p>}
    </div>
  );
}

function useProductSearch(search, warehouseId, priceListId) {
  // Same derived-status approach as useShift: `fetched.key` identifies which
  // search this result belongs to, so a key mismatch (new search typed,
  // still debouncing/in flight) reads as 'loading' without an effect calling
  // setState synchronously before its async work starts.
  const key = `${search}|${warehouseId}|${priceListId}`;
  const [fetched, setFetched] = useState({ key: null, status: 'ready', items: EMPTY_LIST });

  useEffect(() => {
    if (!search.trim()) {
      return;
    }
    let cancelled = false;
    const handle = setTimeout(() => {
      const params = new URLSearchParams({ search, per_page: '10' });
      if (warehouseId) params.set('warehouse_id', warehouseId);
      if (priceListId) params.set('price_list_id', priceListId);

      apiRequest(`/products?${params.toString()}`)
        .then((items) => {
          if (!cancelled) setFetched({ key, status: 'ready', items });
        })
        .catch(() => {
          if (!cancelled) setFetched({ key, status: 'error', items: EMPTY_LIST });
        });
    }, 300);

    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [search, warehouseId, priceListId, key]);

  if (!search.trim()) {
    return { status: 'idle', items: EMPTY_LIST };
  }
  if (fetched.key !== key) {
    return { status: 'loading', items: EMPTY_LIST };
  }
  return fetched;
}

function ProductResults({ products, onAdd, currencyCode }) {
  if (products.status === 'idle') {
    return <p className="pos-status">{t('pos.search.idle')}</p>;
  }
  if (products.status === 'loading') {
    return <p className="pos-status">{t('pos.search.loading')}</p>;
  }
  if (products.status === 'error') {
    return (
      <p className="pos-status" role="alert">
        {t('pos.search.error')}
      </p>
    );
  }
  if (products.items.length === 0) {
    return <p className="pos-status">{t('pos.search.empty')}</p>;
  }

  return (
    <ul className="pos-product-list">
      {products.items.map((product) => {
        const price = product.prices?.[0];
        return (
          <li key={product.id}>
            <span className="pos-product-name">{product.name}</span>
            <span className="pos-product-sku">{product.sku}</span>
            <span>{price ? `${currencyCode} ${price.price}` : t('pos.search.no_price')}</span>
            <span>{product.stock_quantity ?? '-'}</span>
            <button type="button" disabled={!price} onClick={() => onAdd(product)}>
              {t('pos.search.add')}
            </button>
          </li>
        );
      })}
    </ul>
  );
}

function useCustomerPicker() {
  const [query, setQuery] = useState('');
  const [fetched, setFetched] = useState({ key: null, items: EMPTY_LIST });
  const [selected, setSelected] = useState(null);

  useEffect(() => {
    if (!query.trim()) {
      return;
    }
    let cancelled = false;
    const handle = setTimeout(() => {
      apiRequest(`/customers?${new URLSearchParams({ search: query, per_page: '5' })}`)
        .then((items) => {
          if (!cancelled) setFetched({ key: query, items });
        })
        .catch(() => {
          if (!cancelled) setFetched({ key: query, items: EMPTY_LIST });
        });
    }, 300);
    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [query]);

  const results = query.trim() && fetched.key === query ? fetched.items : EMPTY_LIST;

  return {
    query,
    setQuery,
    results,
    selected,
    select: (customer) => {
      setSelected(customer);
      setQuery('');
    },
    clear: () => setSelected(null),
  };
}

function CustomerPicker({ customer }) {
  return (
    <div className="pos-customer-picker">
      {customer.selected ? (
        <p>
          {t('pos.customer.selected', { name: customer.selected.name })}{' '}
          <button type="button" onClick={customer.clear}>
            {t('pos.customer.clear')}
          </button>
        </p>
      ) : (
        <>
          <label>
            {t('pos.customer.label')}
            <input
              type="search"
              value={customer.query}
              onChange={(e) => customer.setQuery(e.target.value)}
              placeholder={t('pos.customer.placeholder')}
            />
          </label>
          {customer.results.length > 0 && (
            <ul className="pos-customer-results">
              {customer.results.map((c) => (
                <li key={c.id}>
                  <button type="button" onClick={() => customer.select(c)}>
                    {c.name} ({c.phone})
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  );
}

function Cart({ lines, currencyCode, onChangeQuantity, onRemove }) {
  if (lines.length === 0) {
    return <p className="pos-status">{t('pos.cart.empty')}</p>;
  }

  return (
    <ul className="pos-cart">
      {lines.map((line, index) => (
        <li key={`${line.productId}-${line.unitId}`}>
          <span>{line.name}</span>
          <input value={line.quantity} onChange={(e) => onChangeQuantity(index, e.target.value)} />
          <span>
            {currencyCode} {new Decimal(line.unitPrice).mul(line.quantity || 0).toFixed(4)}
          </span>
          <button type="button" onClick={() => onRemove(index)}>
            {t('pos.cart.remove')}
          </button>
        </li>
      ))}
    </ul>
  );
}
