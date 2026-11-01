import PropTypes from 'prop-types';
import { t } from '../i18n';
import './ManagementScreens.css';

export function ReceiptModal({ sale, onClose }) {
  if (!sale) return null;

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="receipt-modal" onClick={(e) => e.stopPropagation()}>
        <div className="receipt-header">
          <h3>{t('receipt.title')}</h3>
          <button className="btn-close" onClick={onClose} aria-label={t('common.close')}>
            ×
          </button>
        </div>
        <div className="receipt-paper" id="printable-receipt">
          <div className="receipt-shop-title">{t('receipt.shop')}</div>
          <div className="receipt-meta">
            {t('receipt.invoice')}: {sale.invoice_number || 'N/A'}
          </div>
          <div className="receipt-meta">
            {t('receipt.date')}: {new Date(sale.created_at).toLocaleString()}
          </div>
          <div className="receipt-meta">
            {t('receipt.cashier')}: {sale.cashier || 'Cashier'}
          </div>
          {sale.customer && (
            <div className="receipt-meta">
              {t('receipt.customer')}: {sale.customer.name} ({sale.customer.phone || ''})
            </div>
          )}
          <hr className="receipt-divider" />
          <table className="receipt-items-table">
            <thead>
              <tr>
                <th>{t('receipt.item')}</th>
                <th>{t('receipt.qty')}</th>
                <th>{t('receipt.price')}</th>
                <th>{t('receipt.total')}</th>
              </tr>
            </thead>
            <tbody>
              {(sale.items || []).map((item) => (
                <tr key={item.id}>
                  <td>{item.name}</td>
                  <td>{item.quantity}</td>
                  <td>{item.unit_price}</td>
                  <td>{item.line_total}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <hr className="receipt-divider" />
          <div className="receipt-summary">
            <div className="receipt-row">
              <span>{t('receipt.subtotal')}:</span>
              <span>{sale.line_subtotal}</span>
            </div>
            {Number(sale.tax_total) > 0 && (
              <div className="receipt-row">
                <span>{t('receipt.tax')}:</span>
                <span>{sale.tax_total}</span>
              </div>
            )}
            {Number(sale.discount_total) > 0 && (
              <div className="receipt-row">
                <span>{t('receipt.discount')}:</span>
                <span>-{sale.discount_total}</span>
              </div>
            )}
            <div className="receipt-row grand-total">
              <strong>{t('receipt.grand_total')}:</strong>
              <strong>{sale.grand_total}</strong>
            </div>
          </div>
          <hr className="receipt-divider" />
          <div className="receipt-footer">{t('receipt.thank_you')}</div>
        </div>
        <div className="receipt-actions">
          <button className="btn-print" onClick={() => window.print()}>
            {t('receipt.print')}
          </button>
          <button className="btn-secondary" onClick={onClose}>
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  );
}

ReceiptModal.propTypes = {
  sale: PropTypes.shape({
    id: PropTypes.number,
    invoice_number: PropTypes.string,
    created_at: PropTypes.string,
    cashier: PropTypes.string,
    customer: PropTypes.shape({
      name: PropTypes.string,
      phone: PropTypes.string,
    }),
    items: PropTypes.arrayOf(
      PropTypes.shape({
        id: PropTypes.number,
        name: PropTypes.string,
        quantity: PropTypes.string,
        unit_price: PropTypes.string,
        line_total: PropTypes.string,
      }),
    ),
    line_subtotal: PropTypes.string,
    tax_total: PropTypes.string,
    discount_total: PropTypes.string,
    grand_total: PropTypes.string,
  }),
  onClose: PropTypes.func.isRequired,
};
