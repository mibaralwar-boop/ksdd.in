import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Suppliers with live payable / overdue (`GET suppliers`), ledger and aging (`GET suppliers/one`), payments
/// (`POST suppliers/pay`, allocated oldest-bill-first by the server).
class SuppliersScreen extends StatefulWidget {
  const SuppliersScreen({super.key, required this.session});
  final Session session;
  @override
  State<SuppliersScreen> createState() => _SuppliersScreenState();
}

class _SuppliersScreenState extends State<SuppliersScreen> {
  String q = '';
  bool dueOnly = false;

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        load: () => widget.session.api.get('suppliers'),
        builder: (context, d, reload) {
          final all = [for (final x in (d['suppliers'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final list = all
              .where((s) => s['active'] != 0)
              .where((s) => !dueOnly || ((s['balance'] as num?) ?? 0) > 0)
              .where((s) => '${s['name']} ${s['code'] ?? ''} ${s['city'] ?? ''}'.toLowerCase().contains(q.toLowerCase()))
              .toList()
            ..sort((a, b) => ((b['balance'] as num?) ?? 0).compareTo((a['balance'] as num?) ?? 0));
          final payable = all.fold<int>(0, (a, s) => a + (((s['balance'] as num?) ?? 0).toInt()));
          final overdue = all.fold<int>(0, (a, s) => a + (((s['overdue'] as num?) ?? 0).toInt()));
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            HeroCard(
              title: 'Supplier payable',
              value: rupees(payable, showPaise: false),
              icon: Icons.local_shipping_outlined,
              parts: [('Overdue', rupees(overdue, showPaise: false)), ('Suppliers', '${all.length}')],
            ),
            SearchBox(hint: 'Search supplier', onChanged: (v) => setState(() => q = v)),
            const SizedBox(height: 8),
            Wrap(spacing: 8, children: [
              ChoiceChip(label: const Text('All'), selected: !dueOnly, onSelected: (_) => setState(() => dueOnly = false)),
              ChoiceChip(label: const Text('Payable'), selected: dueOnly, onSelected: (_) => setState(() => dueOnly = true)),
            ]),
            if (list.isEmpty)
              const EmptyState(icon: Icons.local_shipping_outlined, title: 'No suppliers found', subtitle: 'Try another search.'),
            for (final s in list)
              Card(
                child: ListTile(
                  leading: CircleAvatar(
                    backgroundColor: brandPeach,
                    child: Text(_initials(s['name'].toString()), style: const TextStyle(color: brandBurnt, fontWeight: FontWeight.w800)),
                  ),
                  title: Text(s['name'].toString(), style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text('${s['code'] ?? ''} · last purchase ${s['last_purchase'] ?? '—'}'),
                  trailing: TrailingStack(children: [
                    Text(rupees(s['balance'], showPaise: false), style: const TextStyle(fontWeight: FontWeight.w900)),
                    if (((s['overdue'] as num?) ?? 0) > 0) const StatusPill('Overdue', tone: PillTone.bad),
                  ]),
                  onTap: () async {
                    await Navigator.push(context,
                        MaterialPageRoute<void>(builder: (_) => SupplierDetail(session: widget.session, id: (s['id'] as num).toInt())));
                    await reload();
                  },
                ),
              ),
          ]);
        },
      );
}

String _initials(String n) {
  final parts = n.replaceAll(RegExp(r'^DEMO\s+'), '').split(RegExp(r'\s+')).where((x) => x.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  return (parts.first[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
}

class SupplierDetail extends StatelessWidget {
  const SupplierDetail({super.key, required this.session, required this.id});
  final Session session;
  final int id;

  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    return Scaffold(
      appBar: AppBar(title: const Text('Supplier')),
      body: Loader<Map<String, dynamic>>(
        load: () => session.api.get('suppliers/one', {'id': '$id'}),
        builder: (context, d, reload) {
          final s = (d['supplier'] as Map).cast<String, dynamic>();
          final ledger = [for (final x in (d['ledger'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final open = [for (final x in (d['open_bills'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final aging = (d['aging'] as Map?) ?? const {};
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            PageTitle(s['name'].toString(), subtitle: [s['code'], s['city'], s['phone']].where((x) => x != null).join(' · ')),
            HeroCard(
              title: 'Balance payable',
              value: rupees(d['balance']),
              icon: Icons.account_balance_wallet_outlined,
              parts: [
                ('Purchases', rupees(d['purchases_total'], showPaise: false)),
                ('Returns', rupees(d['returns_total'], showPaise: false))
              ],
            ),
            if (p.can('supplier.pay') && !p.readOnly && ((d['balance'] as num?) ?? 0) > 0)
              FilledButton.icon(
                icon: const Icon(Icons.payments_outlined),
                label: const Text('Pay supplier'),
                onPressed: () async {
                  final ok = await Navigator.push<bool>(
                      context, MaterialPageRoute(builder: (_) => SupplierPayForm(session: session, supplier: s, balance: d['balance'])));
                  if (ok == true) await reload();
                },
              ),
            SectionCard(title: 'Aging of open bills', icon: Icons.schedule_rounded, children: [
              SumRow('Not due', rupees(aging['not_due'])),
              SumRow('1–7 days', rupees(aging['d1_7'])),
              SumRow('8–15 days', rupees(aging['d8_15'])),
              SumRow('16–30 days', rupees(aging['d16_30'])),
              SumRow('31–60 days', rupees(aging['d31_60'])),
              SumRow('60+ days', rupees(aging['d60']), color: ((aging['d60'] as num?) ?? 0) > 0 ? brandRed : null),
            ]),
            if (open.isNotEmpty)
              SectionCard(title: 'Open bills', children: [
                for (final b in open)
                  SumRow('${b['bill_no'] ?? ''} · ${b['post_date'] ?? b['invoice_date'] ?? ''}', rupees(b['outstanding'])),
              ]),
            const SizedBox(height: 8),
            Text('Ledger', style: Theme.of(context).textTheme.titleLarge),
            if (ledger.isEmpty)
              const EmptyState(icon: Icons.menu_book_outlined, title: 'No entries', subtitle: 'Bills and payments appear here.'),
            for (final l in ledger.reversed)
              Card(
                child: ListTile(
                  title: Text('${l['date']} · ${_type(l['type'].toString())}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([l['bill_no'], l['description']].where((x) => x != null && x != '').join(' · '),
                      maxLines: 2, overflow: TextOverflow.ellipsis),
                  trailing: TrailingStack(children: [
                    Text('${((l['effect'] as num?) ?? 0) >= 0 ? '+' : ''}${rupees(l['effect'])}',
                        style: TextStyle(fontWeight: FontWeight.w800, color: ((l['effect'] as num?) ?? 0) > 0 ? brandRed : brandGreen)),
                    Text('Bal ${rupees(l['balance'], showPaise: false)}', style: Theme.of(context).textTheme.bodySmall),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }

  static String _type(String t) => switch (t) {
        'purchase' => 'Purchase',
        'payment-out' || 'supplier_payment' => 'Payment',
        'return' => 'Return',
        _ => t[0].toUpperCase() + t.substring(1).replaceAll(RegExp('[_-]'), ' '),
      };
}

class SupplierPayForm extends StatefulWidget {
  const SupplierPayForm({super.key, required this.session, required this.supplier, required this.balance});
  final Session session;
  final Map<String, dynamic> supplier;
  final Object? balance;
  @override
  State<SupplierPayForm> createState() => _SupplierPayFormState();
}

class _SupplierPayFormState extends State<SupplierPayForm> {
  final amount = TextEditingController();
  final ref = TextEditingController();
  String mode = 'Cash';
  String _key = randomKey();
  bool busy = false;

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    final modes = [
      if (p.can('pay.cash')) 'Cash',
      if (p.can('pay.upi')) 'UPI',
      if (p.can('pay.bank')) 'Bank',
    ];
    if (modes.isNotEmpty && !modes.contains(mode)) mode = modes.first;
    return Scaffold(
      appBar: AppBar(title: Text('Pay ${widget.supplier['name']}')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Banner2('Payable now: ${rupees(widget.balance)}. The payment is applied to the oldest open bills first.'),
        const SizedBox(height: 8),
        MoneyField(controller: amount, label: 'Payment amount', big: true),
        const SizedBox(height: 12),
        if (modes.isEmpty) const Banner2('You do not have permission to pay from any account.', color: brandRedBg),
        Wrap(spacing: 8, children: [
          for (final m in modes) ChoiceChip(label: Text(m), selected: mode == m, onSelected: (_) => setState(() => mode = m)),
        ]),
        if (mode != 'Cash') ...[
          const SizedBox(height: 12),
          TextField(controller: ref, decoration: const InputDecoration(labelText: 'UTR / reference')),
        ],
        const SizedBox(height: 16),
        FilledButton(onPressed: busy || modes.isEmpty ? null : () => _pay(), child: const Text('Record payment')),
      ]),
    );
  }

  Future<void> _pay({bool refOk = false}) async {
    final paise = parseRupees(amount.text);
    if (paise == null || paise <= 0) {
      showMessage(context, 'Enter the payment amount.', error: true);
      return;
    }
    if (!refOk &&
        !await confirmDialog(context, 'Pay ${rupees(paise)}?', 'From $mode to ${widget.supplier['name']}. This is posted to the books.',
            ok: 'Pay')) {
      return;
    }
    setState(() => busy = true);
    try {
      await widget.session.api.post(
          'suppliers/pay',
          {
            'supplier_id': widget.supplier['id'],
            'amount': rupeeInput(paise),
            'mode': mode,
            'reference': ref.text.trim(),
            'client_key': _key,
            if (refOk) 'ref_ok': true,
          },
          idem: _key);
      if (mounted) {
        showMessage(context, 'Payment recorded.');
        Navigator.pop(context, true);
      }
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.isConflict &&
          e.data['needs'] == 'ref_ok' &&
          await confirmDialog(context, 'Reference already used', e.message, ok: 'Pay anyway')) {
        setState(() => busy = false);
        _key = randomKey(); // nothing was posted; the confirmed retry is a new request
        await _pay(refOk: true);
        return;
      }
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }
}
