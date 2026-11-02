import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

String _prevMonth(String businessDate) {
  final d = DateTime.tryParse(businessDate) ?? DateTime.now();
  final p = DateTime(d.year, d.month - 1, 1);
  return '${p.year}-${p.month.toString().padLeft(2, '0')}';
}

class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key, required this.session});
  final Session session;
  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  late String month = _prevMonth(widget.session.permissions!.businessDate);

  void _shift(int by) {
    final p = month.split('-');
    final d = DateTime(int.parse(p[0]), int.parse(p[1]) + by, 1);
    setState(() => month = '${d.year}-${d.month.toString().padLeft(2, '0')}');
  }

  @override
  Widget build(BuildContext context) {
    final canGl = widget.session.permissions!.can('accounts.view');
    return Column(children: [
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: Row(children: [
          IconButton(tooltip: 'Previous month', onPressed: () => _shift(-1), icon: const Icon(Icons.chevron_left)),
          Expanded(child: Text(month, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium)),
          IconButton(tooltip: 'Next month', onPressed: () => _shift(1), icon: const Icon(Icons.chevron_right)),
        ]),
      ),
      Expanded(
        child: Loader<List<Map<String, dynamic>>>(
          key: ValueKey(month),
          load: () async {
            final m = await widget.session.api.get('reports/monthly', {'month': month});
            Map<String, dynamic> gl = const {};
            if (canGl) {
              try {
                final last = DateTime(int.parse(month.split('-')[0]), int.parse(month.split('-')[1]) + 1, 0).day;
                gl = await widget.session.api.get('reports/gl', {'what': 'statements', 'from': '$month-01', 'to': '$month-$last'});
              } catch (_) {}
            }
            return [m, gl];
          },
          builder: (context, d, _) {
            final m = d[0];
            final pl = (m['pl'] as Map?) ?? const {};
            final not = (pl['not_in_pl'] as Map?) ?? const {};
            final glPnl = (d[1]['pnl'] as Map?);
            final bs = (d[1]['balance_sheet'] as Map?);
            return ListView(padding: const EdgeInsets.all(16), children: [
              SectionCard(title: 'Profit & Loss (cash & purchases view)', children: [
                SumRow('Sales', rupees(pl['sales'])),
                SumRow('Other income', rupees(pl['other_income'])),
                SumRow('− Purchases', rupees(pl['purchases'])),
                SumRow('− Staff cost', rupees(pl['staff_cost'])),
                SumRow('− Other expenses', rupees(pl['other_expenses'])),
                SumRow('Profit', rupees(pl['profit']), bold: true),
                if (pl['food_cost_pct'] != null) SumRow('Food cost % (${pl['food_cost_basis'] ?? ''})', '${pl['food_cost_pct']}%'),
                const SizedBox(height: 6),
                Text((pl['basis'] ?? '').toString(), style: const TextStyle(fontSize: 12)),
              ]),
              SectionCard(title: 'Not counted in profit', children: [
                SumRow('Cash Acess', rupees(not['cash_access'])),
                SumRow('Transfers', rupees(not['transfers'])),
                SumRow('Owner money in / drawings', '${rupees(not['owner_in'])} / ${rupees(not['owner_draw'])}'),
              ]),
              SectionCard(title: 'Expenses by category', children: [
                for (final c in (m['expense_categories'] as List? ?? const [])) SumRow('${(c as Map)['category']} (${c['n']})', rupees(c['amount'])),
                if ((m['expense_categories'] as List? ?? const []).isEmpty) const Text('No expenses.'),
              ]),
              SectionCard(title: 'Cash', children: [
                SumRow('Opening', rupees((m['cash'] as Map?)?['opening'])),
                SumRow('Closing', rupees((m['cash'] as Map?)?['closing'])),
              ]),
              if (glPnl != null)
                SectionCard(title: 'Double-entry ledger (GL)', children: [
                  SumRow('Revenue', rupees(glPnl['revenue'])),
                  SumRow('Expenses', rupees(glPnl['expenses'])),
                  SumRow('Profit (GL)', rupees(glPnl['profit']), bold: true),
                  if (bs != null) ...[
                    const Divider(),
                    SumRow('Assets', rupees(bs['assets'])),
                    SumRow('Liabilities', rupees(bs['liabilities'])),
                    SumRow('Equity', rupees(bs['equity'])),
                    SumRow('Balanced', bs['balanced'] == true ? 'Yes' : 'NO — check ledger', color: bs['balanced'] == true ? null : const Color(0xFFB3261E)),
                  ],
                ]),
            ]);
          },
        ),
      ),
    ]);
  }
}
