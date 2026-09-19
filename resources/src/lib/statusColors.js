/**
 * Ant tag colours for the legacy status vocabularies, shared by the sales/
 * purchases documents and their reports.
 */

/** Document status: completed/received, pending, ordered, cancelled. */
export function docStatusColor(status) {
    const s = String(status || '').toLowerCase();
    if (s.includes('partially_received') || s.includes('partial')) return 'warning';
    if (s.includes('complet') || s.includes('received')) return 'success';
    if (s.includes('draft')) return 'default';
    if (s.includes('pending')) return 'warning';
    if (s.includes('ordered') || s.includes('sent')) return 'processing';
    if (s.includes('cancel')) return 'error';
    return 'default';
}

/** Payment status: paid, partial, unpaid. */
export function payStatusColor(status) {
    const s = String(status || '').toLowerCase();
    if (s.includes('paid') && !s.includes('unpaid')) return 'success';
    if (s.includes('partial')) return 'warning';
    if (s.includes('unpaid')) return 'error';
    return 'default';
}

/**
 * Receivables status (Invoice Receivables Report): the 4-state derived
 * value from ReportController::Report_InvoiceReceivables — paid, partial,
 * due, overdue. Separate from payStatusColor() above since "due" and
 * "overdue" don't exist in the plain 3-state payment_statut vocabulary.
 */
export function receivableStatusColor(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'paid') return 'success';
    if (s === 'partial') return 'warning';
    if (s === 'overdue') return 'error';
    return 'default'; // 'due'
}
