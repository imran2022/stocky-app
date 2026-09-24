/**
 * Modern Dashboard: the movable sections.
 *
 * `id`s must stay in sync with App\Support\Dashboard\DashboardPreferences::SECTIONS (the server validates the saved
 * layout against that list). `span` = columns of the 12-column desktop grid; smaller screens re-flow in modern.css.
 * `title` is the English fallback used in the "Customize" editor (translated through tt()).
 */
export const SECTIONS = [
  { id: 'kpis', span: 12, title: 'Headline numbers', key: 'Headline_numbers' },
  { id: 'insights', span: 12, title: 'Business insights', key: 'Business_insights' },
  { id: 'attention', span: 12, title: 'Needs attention', key: 'Needs_attention' },
  { id: 'sales_purchases', span: 8, title: 'Sales & Purchases', key: 'Sales_And_Purchases' },
  { id: 'top_products_donut', span: 4, title: 'Top selling products (chart)', key: 'Top_products_chart' },
  { id: 'sales_by_payment', span: 6, title: 'Sales by payment', key: 'Sales_by_Payment' },
  { id: 'stock_value', span: 6, title: 'Stock value', key: 'Stock_Value' },
  { id: 'quick_actions', span: 4, title: 'Quick actions', key: 'Quick_Actions' },
  { id: 'payments_chart', span: 8, title: 'Payment sent & received', key: 'Payment_Sent_Received' },
  { id: 'top_customers', span: 6, title: 'Top customers', key: 'Top_Customers' },
  { id: 'stock_alert', span: 6, title: 'Stock alert', key: 'StockAlert' },
  { id: 'top_products_list', span: 6, title: 'Top selling products (list)', key: 'Top_products_list' },
  { id: 'recent_activity', span: 6, title: 'Recent activity', key: 'Recent_activity' },
  { id: 'hourly_sales', span: 8, title: "Today's sales by hour", key: 'Hourly_Sales_Today' },
  { id: 'sales_by_warehouse', span: 4, title: 'Sales by warehouse', key: 'Sales_by_Warehouse' },
  { id: 'sales_map', span: 12, title: 'Sales map', key: 'Sales_map' },
  { id: 'recent_sales', span: 12, title: 'Recent sales', key: 'Recent_Sales' },
];

export const SECTION_IDS = SECTIONS.map(s => s.id);
export const SECTION_BY_ID = Object.fromEntries(SECTIONS.map(s => [s.id, s]));

/** Same rule as the server: drop unknown / repeated ids, append any section the saved order does not know yet. */
export function normaliseLayout(layout) {
  const clean = ids => {
    const out = [];
    (Array.isArray(ids) ? ids : []).forEach(id => {
      if (SECTION_BY_ID[id] && !out.includes(id)) out.push(id);
    });
    return out;
  };
  const order = clean(layout?.order);
  SECTION_IDS.forEach(id => { if (!order.includes(id)) order.push(id); });
  return { order, hidden: clean(layout?.hidden) };
}
