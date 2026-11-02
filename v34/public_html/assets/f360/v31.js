/* Finance 360 V31 — Inter-branch transfers (same restaurant). Stock: send → in transit → received once at the other branch.
   Cash: a transfer on both sides (never a sale or an expense). Shown only in multi-branch (platform) mode and only with the
   matching permissions; the server re-checks everything (same restaurant, branch membership, permissions). */
route(/^\/transfers$/, 'transfers', () => !!F.me?.platform?.platform && canAny('stock.issue', 'stock.receive', 'cash.transfer'));
NAV[2][1].push(['#/transfers', '⇄', 'Branch transfers', () => !!F.me?.platform?.platform && canAny('stock.issue', 'stock.receive', 'cash.transfer')]);
F.pages.transfers = async (_, q, tk) => {
  title('Branch transfers');
  const [r, br] = await Promise.all([api('transfers'), api('transfer-branches')]); if (stale(tk)) return;
  const me = F.me.platform.branch.id; const opts = br.branches.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
  const st = s => chip({ in_transit: 'In transit', received: 'Received', cancelled: 'Cancelled' }[s] || s, s === 'in_transit' ? 'warn' : s === 'received' ? 'ok' : '');
  const what = x => x.kind === 'cash' ? 'Cash ' + R(x.amount_paise) : x.lines.map(l => `${esc(l.item)} ${qd(l.qty_milli / 1000)} ${esc(l.unit)}`).join(', ') + ` <span class="xs muted">(${R(x.value_paise)})</span>`;
  const act = x => x.status !== 'in_transit' ? '' : x.to_branch === me && canAny('stock.receive', 'cash.transfer') ? `<button class="btn sm" data-a="xfer-recv" data-id="${x.id}">Receive</button>`
    : x.from_branch === me ? `<button class="btn sm ghost" data-a="xfer-cancel" data-id="${x.id}">Cancel</button>` : '';
  const rows = r.transfers.map(x => `<tr><td><b>${esc(x.no)}</b><br><span class="xs muted">${D(x.date_sent)}</span></td><td>${x.from_branch === me ? 'To ' + esc(x.to_name) : 'From ' + esc(x.from_name)}</td><td>${what(x)}</td><td>${st(x.status)}${x.date_received ? `<br><span class="xs">${D(x.date_received)}</span>` : ''}</td><td>${act(x)}</td></tr>`).join('');
  const list = card('Transfers' + (r.incoming_pending ? ` · ${r.incoming_pending} to receive` : ''), rows ? `<table class="t"><thead><tr><th>No.</th><th>Branch</th><th>What</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table>` : empty('No transfers yet'));
  if (!br.branches.length) return list + card('', '<p class="muted">Add another branch in the Owner portal to move stock or cash between branches.</p>');
  const stock = can('stock.issue') ? card('Send stock to another branch', `<form class="bd" data-form="xferstock"><label class="f">To branch<select name="to_branch_id" required>${opts}</select></label>
     <label class="f">Item<select name="item_id" required id="xfitem"><option value="">Loading…</option></select></label>${field('Quantity', 'qty', { type: 'number', req: true, attrs: 'step="0.001" min="0.001" inputmode="decimal"' })}${field('Note', 'note')}
     <p class="xs muted">Valued at this branch's average cost. Stock leaves now and is added at the other branch when they press Receive.</p><button class="btn" type="submit">Send stock</button></form>`) : '';
  const cash = can('cash.transfer') ? card('Send cash to another branch', `<form class="bd" data-form="xfercash"><label class="f">To branch<select name="to_branch_id" required>${opts}</select></label>
     ${field('Amount (₹)', 'amount', { type: 'number', req: true, attrs: 'step="0.01" min="1" inputmode="decimal"' })}${field('Note', 'note')}<p class="xs muted">Recorded as a transfer, not as an expense or a sale.</p><button class="btn" type="submit">Send cash</button></form>`) : '';
  setTimeout(async () => { const s = document.getElementById('xfitem'); if (!s) return; const it = await api('items', { status: 'active', limit: 500 }); s.innerHTML = '<option value="">Choose…</option>' + it.items.filter(i => i.is_stock !== false && i.is_stock !== 0).map(i => `<option value="${i.id}">${esc(i.name)} (${esc(i.unit || '')})</option>`).join(''); }, 0);
  return list + stock + cash;
};
F.forms.xferstock = async (f, d) => { await api('transfer-stock-send', {}, { to_branch_id: Number(d.to_branch_id), lines: [{ item_id: Number(d.item_id), qty: d.qty }], note: d.note, client_key: 'xs' + Date.now() + Math.random().toString(36).slice(2, 8) }); toast('Stock sent — waiting for the other branch to receive it.'); render(); };
F.forms.xfercash = async (f, d) => { await api('transfer-cash-send', {}, { to_branch_id: Number(d.to_branch_id), amount: d.amount, note: d.note, client_key: 'xc' + Date.now() + Math.random().toString(36).slice(2, 8) }); toast('Cash sent.'); render(); };
F.A['xfer-recv'] = async a => { if (!confirm('Receive this transfer now? It will be added to this branch.')) return; await api('transfer-receive', {}, { id: Number(a.dataset.id), client_key: 'xr' + a.dataset.id }); toast('Received.'); render(); };
F.A['xfer-cancel'] = async a => { const why = prompt('Reason for cancelling this transfer?'); if (!why) return; await api('transfer-cancel', {}, { id: Number(a.dataset.id), reason: why, client_key: 'xk' + a.dataset.id }); toast('Cancelled — stock / cash returned.'); render(); };
