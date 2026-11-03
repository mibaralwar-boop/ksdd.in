import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Native Sales history (`GET sales?from&to`): daily Cash / Card / UPI totals from the books and closings.
/// Read-only history — not POS billing, KOT, tables or menu.
class SalesScreen extends StatefulWidget {
  const SalesScreen({super.key, required this.session});
  final Session session;
  @override
  State<SalesScreen> createState() => _SalesScreenState();
}

class _SalesScreenState extends State<SalesScreen> {
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
      key: ValueKey('$from|$to'),
      load: () => widget.session.api.get('sales', {'from': from, 'to': to}),
      builder: (context, d, _) {
        final t = (d['totals'] as Map?) ?? const {};
        final days = [for (final x in (d['days'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          PeriodBar(
            from: from,
            to: to,
            businessDate: p.businessDate,
            onChanged: (f, tt) => setState(() {
              from = f;
              to = tt;
            }),
          ),
          HeroCard(
            title: 'Total sale · ${d['from']} to ${d['to']}',
            value: rupees(t['total'], showPaise: false),
            parts: [
              ('Cash', rupees(t['cash'], showPaise: false)),
              ('Card', rupees(t['card'], showPaise: false)),
              ('UPI', rupees(t['upi'], showPaise: false)),
            ],
          ),
          SectionCard(children: [
            SumRow('Expenses in this period', rupees(t['expenses'])),
            if ((t['other'] ?? 0) != 0) SumRow('Other sale accounts', rupees(t['other'])),
            if ((t['cash_acess'] ?? 0) != 0) SumRow('Cash Acess (not a sale)', rupees(t['cash_acess'])),
            if ((t['cash_short'] ?? 0) != 0) SumRow('Cash Short', rupees(t['cash_short']), color: brandRed),
            SumRow('Days with sales', '${days.where((x) => (x['total'] ?? 0) != 0).length}'),
          ]),
          if (p.can('reports.export') && days.isNotEmpty)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                icon: const Icon(Icons.copy_all_rounded),
                label: const Text('Copy as table'),
                onPressed: () async {
                  final csv = StringBuffer('Date,Cash,Card,UPI,Other,Total,Expenses,Status\n');
                  for (final x in days) {
                    csv.writeln([
                      x['date'],
                      rupeeInput(x['cash']),
                      rupeeInput(x['card']),
                      rupeeInput(x['upi']),
                      rupeeInput(x['other']),
                      rupeeInput(x['total']),
                      rupeeInput(x['expenses']),
                      x['status'] ?? x['source'] ?? '',
                    ].join(','));
                  }
                  await Clipboard.setData(ClipboardData(text: csv.toString()));
                  if (context.mounted) showMessage(context, 'Copied ${days.length} days. Paste into WhatsApp or a spreadsheet.');
                },
              ),
            ),
          const SizedBox(height: 6),
          Text('Days', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 6),
          if (days.isEmpty)
            const EmptyState(
              icon: Icons.bar_chart_rounded,
              title: 'No sales in this period',
              subtitle: 'Sales appear here after the Daily Closing of each business day.',
            ),
          for (final x in days)
            Card(
              child: ListTile(
                onTap: () => _detail(context, x, p),
                title: Text(x['date'].toString(), style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text(
                    'Cash ${rupees(x['cash'], showPaise: false)} · Card ${rupees(x['card'], showPaise: false)} · UPI ${rupees(x['upi'], showPaise: false)}'),
                trailing: TrailingStack(children: [
                  Text(rupees(x['total'], showPaise: false), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                  const SizedBox(height: 3),
                  _statusPill(x),
                ]),
              ),
            ),
        ]);
      },
    );
  }

  static Widget _statusPill(Map<String, dynamic> x) {
    final s = (x['status'] ?? '').toString();
    return switch (s) {
      'approved' => const StatusPill('Final', tone: PillTone.good),
      'submitted' => const StatusPill('Submitted', tone: PillTone.info),
      'draft' => const StatusPill('Draft'),
      'legacy' => const StatusPill('Books', tone: PillTone.info),
      _ => StatusPill(x['source'] == 'closing' ? 'Closing' : 'Books', tone: PillTone.info),
    };
  }

  void _detail(BuildContext context, Map<String, dynamic> x, Permissions p) => showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (c) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Expanded(child: Text('Business day ${x['date']}', style: Theme.of(c).textTheme.titleLarge)),
                _statusPill(x),
              ]),
              const SizedBox(height: 12),
              SumRow('Cash Sale', rupees(x['cash'])),
              SumRow('Card Sale', rupees(x['card'])),
              SumRow('UPI Sale', rupees(x['upi'])),
              if ((x['other'] ?? 0) != 0) SumRow('Other', rupees(x['other'])),
              const Divider(),
              SumRow('Total Sale', rupees(x['total']), bold: true),
              SumRow('Expenses (${x['expense_count'] ?? 0})', rupees(x['expenses'])),
              if (x['actual_cash'] != null) SumRow('Actual Cash', rupees(x['actual_cash'])),
              if (x['operational_diff'] != null)
                SumRow(((x['operational_diff'] as num) >= 0) ? 'Cash Acess' : 'Cash Short', rupees((x['operational_diff'] as num).abs()),
                    color: ((x['operational_diff'] as num) >= 0) ? brandGreen : brandRed),
              if (x['note'] != null) Banner2(x['note'].toString()),
              const SizedBox(height: 6),
              Text(
                x['source'] == 'closing'
                    ? 'From the Daily Closing of this day.'
                    : 'From the account books (imported or entered before Daily Closing was used).',
                style: Theme.of(c).textTheme.bodySmall,
              ),
            ]),
          ),
        ),
      );
}
