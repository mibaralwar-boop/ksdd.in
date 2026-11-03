import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Account balances (`GET accounts`). Tapping an account opens its book.
class AccountsScreen extends StatelessWidget {
  const AccountsScreen({super.key, required this.session});
  final Session session;
  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        load: () => session.api.get('accounts'),
        builder: (context, d, _) {
          final rows = [for (final x in (d['accounts'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final total = rows.fold<int>(0, (a, x) => a + ((x['balance'] as num?)?.toInt() ?? 0));
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            HeroCard(
                title: 'All accounts · as of ${d['as_of']}', value: rupees(total, showPaise: false), icon: Icons.account_balance_outlined),
            for (final a in rows)
              Card(
                child: ListTile(
                  leading: CircleAvatar(backgroundColor: brandPeach, child: Icon(_icon(a['account'].toString()), color: brandBurnt)),
                  title: Text(a['account'].toString(), style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text('Today in ${rupees(a['today_in'], showPaise: false)} · out ${rupees(a['today_out'], showPaise: false)}'),
                  trailing: Text(rupees(a['balance']), style: const TextStyle(fontWeight: FontWeight.w900)),
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(
                      builder: (_) => Scaffold(
                        appBar: AppBar(title: Text(a['account'].toString())),
                        body: CashBookScreen(session: session, account: a['account'].toString()),
                      ),
                    ),
                  ),
                ),
              ),
            const SizedBox(height: 8),
            const Banner2('Transfers between accounts (for example cash deposited in the bank) are not sales. '
                'They move money between these accounts only.'),
          ]);
        },
      );

  static IconData _icon(String a) {
    final s = a.toLowerCase();
    if (s.contains('cash')) return Icons.payments_outlined;
    if (s.contains('upi') || s.contains('paytm')) return Icons.qr_code_2_rounded;
    if (s.contains('card')) return Icons.credit_card_rounded;
    if (s.contains('owner')) return Icons.home_outlined;
    return Icons.account_balance_outlined;
  }
}

/// Day book of one account (`GET cashbook?account&from&to`): opening, every entry with running balance, closing.
class CashBookScreen extends StatefulWidget {
  const CashBookScreen({super.key, required this.session, this.account});
  final Session session;
  final String? account;
  @override
  State<CashBookScreen> createState() => _CashBookScreenState();
}

class _CashBookScreenState extends State<CashBookScreen> {
  late String from;
  late String to;

  @override
  void initState() {
    super.initState();
    final bd = parseYmd(widget.session.permissions!.businessDate);
    from = ymd(DateTime(bd.year, bd.month, 1));
    to = ymd(bd);
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    return Loader<Map<String, dynamic>>(
      key: ValueKey('${widget.account}|$from|$to'),
      load: () => widget.session.api.get('cashbook', {if (widget.account != null) 'account': widget.account!, 'from': from, 'to': to}),
      builder: (context, d, _) {
        final txns = [for (final x in (d['txns'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        final days = [for (final x in (d['days'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        final byDate = <String, List<Map<String, dynamic>>>{};
        for (final t in txns) {
          byDate.putIfAbsent(t['date'].toString(), () => []).add(t);
        }
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          PeriodBar(
            from: from,
            to: to,
            businessDate: p.businessDate,
            onChanged: (f, t) => setState(() {
              from = f;
              to = t;
            }),
          ),
          HeroCard(
            title: '${d['account']} · closing balance',
            value: rupees(d['closing']),
            icon: Icons.account_balance_wallet_outlined,
            parts: [('Opening ${d['from']}', rupees(d['opening'], showPaise: false))],
          ),
          if (days.isEmpty)
            const EmptyState(
              icon: Icons.menu_book_outlined,
              title: 'No entries in this period',
              subtitle: 'Choose another period above. The balance is unchanged.',
            ),
          for (final day in days.reversed) ...[
            const SizedBox(height: 10),
            Row(children: [
              Expanded(child: Text(day['date'].toString(), style: Theme.of(context).textTheme.titleMedium)),
              Text('Close ${rupees(day['close'], showPaise: false)}', style: const TextStyle(fontWeight: FontWeight.w800)),
            ]),
            SoftCard(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
              child: Column(children: [
                SumRow('Opening', rupees(day['open'])),
                for (final t in byDate[day['date']] ?? const <Map<String, dynamic>>[])
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(t['label'].toString(),
                              style:
                                  TextStyle(fontWeight: FontWeight.w700, decoration: t['voided'] == 1 ? TextDecoration.lineThrough : null)),
                          if ((t['description'] ?? t['party'] ?? t['category']) != null)
                            Text('${t['party'] ?? t['category'] ?? ''} ${t['description'] ?? ''}'.trim(),
                                maxLines: 2, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall),
                          if (t['voided'] == 1) const Text('Voided', style: TextStyle(color: brandRed, fontSize: 12)),
                        ]),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        '${((t['cash'] as num?) ?? 0) >= 0 ? '+' : ''}${rupees(t['cash'])}',
                        style: TextStyle(fontWeight: FontWeight.w800, color: ((t['cash'] as num?) ?? 0) >= 0 ? brandGreen : brandRed),
                      ),
                    ]),
                  ),
                const Divider(),
                SumRow('In ${rupees(day['in'], showPaise: false)} · Out ${rupees(day['out'], showPaise: false)}', rupees(day['close']),
                    bold: true),
              ]),
            ),
          ],
        ]);
      },
    );
  }
}

/// Cash handover between staff (`GET handover`, `POST handover`, `POST handover/respond`). A handover is a transfer,
/// never a sale. The receiver accepts, accepts part, or disputes; the server checks who may respond.
class HandoverScreen extends StatelessWidget {
  const HandoverScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    return Loader<Map<String, dynamic>>(
      load: () => session.api.get('handover'),
      builder: (context, d, reload) {
        final rows = [for (final x in (d['rows'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          if (p.can('handover.send') && !p.readOnly)
            FilledButton.icon(
              icon: const Icon(Icons.send_rounded),
              label: const Text('Hand over cash'),
              onPressed: () async {
                final ok =
                    await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => HandoverForm(session: session, options: d)));
                if (ok == true) await reload();
              },
            ),
          if ((d['mine_pending'] ?? 0) != 0)
            Banner2('${d['mine_pending']} handover(s) are waiting for you to count and confirm.', icon: Icons.pending_actions_rounded),
          if (rows.isEmpty)
            const EmptyState(
              icon: Icons.swap_horiz_rounded,
              title: 'No handovers yet',
              subtitle: 'When cash is handed to another person, it appears here until they confirm the amount.',
            ),
          for (final h in rows)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(children: [
                    Expanded(child: Text('${h['ho_no']} · ${h['date']}', style: const TextStyle(fontWeight: FontWeight.w800))),
                    _pill(h['status'].toString()),
                  ]),
                  const SizedBox(height: 6),
                  SumRow('${h['from_name'] ?? ''} → ${h['to_name'] ?? ''}', rupees(h['amount']), bold: true),
                  Text('From ${h['from_account']}${h['to_account'] != null ? ' to ${h['to_account']}' : ''}',
                      style: Theme.of(context).textTheme.bodySmall),
                  if (h['accepted_amount'] != null && h['accepted_amount'] != h['amount'])
                    SumRow('Accepted', rupees(h['accepted_amount']), color: brandRed),
                  if ((h['note'] ?? '') != '') Text('Note: ${h['note']}', style: Theme.of(context).textTheme.bodySmall),
                  if ((h['response_note'] ?? '') != '') Text('Reply: ${h['response_note']}', style: Theme.of(context).textTheme.bodySmall),
                  if (h['status'] == 'sent' && p.can('handover.receive') && !p.readOnly)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Wrap(spacing: 8, runSpacing: 8, children: [
                        FilledButton(onPressed: () => _respond(context, h, 'accept', d, reload), child: const Text('Accept')),
                        OutlinedButton(onPressed: () => _respond(context, h, 'partial', d, reload), child: const Text('Part received')),
                        OutlinedButton(onPressed: () => _respond(context, h, 'dispute', d, reload), child: const Text('Dispute')),
                      ]),
                    ),
                ]),
              ),
            ),
        ]);
      },
    );
  }

  static Widget _pill(String s) => switch (s) {
        'accepted' => const StatusPill('Accepted', tone: PillTone.good),
        'partial' => const StatusPill('Part accepted', tone: PillTone.bad),
        'disputed' => const StatusPill('Disputed', tone: PillTone.bad),
        'cancelled' => const StatusPill('Cancelled', tone: PillTone.info),
        _ => const StatusPill('Waiting'),
      };

  Future<void> _respond(
      BuildContext context, Map<String, dynamic> h, String act, Map<String, dynamic> d, Future<void> Function() reload) async {
    final body = <String, dynamic>{'id': h['id'], 'act': act};
    if (act == 'accept') {
      if (!await confirmDialog(context, 'Accept ${rupees(h['amount'])}?', 'Confirm only after you have counted the cash.', ok: 'Accept')) {
        return;
      }
    } else {
      if (act == 'partial') {
        final amt = await askText(context, 'Amount received', 'How much did you actually receive?', label: 'Amount (₹)');
        if (amt == null) return;
        final paise = parseRupees(amt);
        if (paise == null || !context.mounted) {
          if (context.mounted) showMessage(context, 'Enter a valid amount.', error: true);
          return;
        }
        body['accepted_amount'] = rupeeInput(paise);
      }
      if (!context.mounted) return;
      final why = await askText(context, act == 'dispute' ? 'Dispute handover' : 'Reason', 'Write what happened (at least 5 characters).');
      if (why == null) return;
      body['note'] = why;
    }
    try {
      await session.api.post('handover/respond', body);
      if (context.mounted) showMessage(context, 'Saved.');
      await reload();
    } on ApiError catch (e) {
      if (context.mounted) showMessage(context, e.message, error: true);
    }
  }
}

class HandoverForm extends StatefulWidget {
  const HandoverForm({super.key, required this.session, required this.options});
  final Session session;
  final Map<String, dynamic> options;
  @override
  State<HandoverForm> createState() => _HandoverFormState();
}

class _HandoverFormState extends State<HandoverForm> {
  final _form = GlobalKey<FormState>();
  final amount = TextEditingController();
  final note = TextEditingController();
  int? to;
  late String from = (widget.options['cash_account'] ?? 'Cash').toString();
  final String _key = randomKey();
  bool busy = false;

  @override
  Widget build(BuildContext context) {
    final receivers = [for (final x in (widget.options['receivers'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
    final accounts = [for (final x in (widget.options['accounts'] as List? ?? const [])) x.toString()];
    return Scaffold(
      appBar: AppBar(title: const Text('Hand over cash')),
      body: Form(
        key: _form,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          DropdownButtonFormField<int>(
            initialValue: to,
            decoration: const InputDecoration(labelText: 'Hand over to'),
            items: [for (final r in receivers) DropdownMenuItem(value: (r['id'] as num).toInt(), child: Text(r['name'].toString()))],
            onChanged: (v) => setState(() => to = v),
            validator: (v) => v == null ? 'Choose who receives the cash' : null,
          ),
          const SizedBox(height: 12),
          MoneyField(controller: amount, label: 'Amount', big: true),
          const SizedBox(height: 12),
          if (accounts.isNotEmpty)
            DropdownButtonFormField<String>(
              initialValue: accounts.contains(from) ? from : accounts.first,
              decoration: const InputDecoration(labelText: 'From account'),
              items: [for (final a in accounts) DropdownMenuItem(value: a, child: Text(a))],
              onChanged: (v) => setState(() => from = v ?? from),
            ),
          const SizedBox(height: 12),
          TextFormField(controller: note, decoration: const InputDecoration(labelText: 'Note (optional)')),
          const SizedBox(height: 12),
          const Banner2('The receiver must count and confirm. Nothing is counted as a sale.'),
          const SizedBox(height: 12),
          FilledButton.icon(
            icon: busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.send_rounded),
            label: const Text('Send handover'),
            onPressed: busy ? null : _send,
          ),
        ]),
      ),
    );
  }

  Future<void> _send() async {
    if (!_form.currentState!.validate()) return;
    final paise = parseRupees(amount.text);
    if (paise == null || paise <= 0) {
      showMessage(context, 'Enter the amount.', error: true);
      return;
    }
    setState(() => busy = true);
    try {
      await widget.session.api.post(
          'handover', {'to_user': to, 'amount': rupeeInput(paise), 'from_account': from, 'note': note.text.trim(), 'client_key': _key},
          idem: _key);
      if (mounted) Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }
}
