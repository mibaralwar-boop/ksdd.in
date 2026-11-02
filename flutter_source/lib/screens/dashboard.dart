import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        load: () => session.api.get('dashboard'),
        builder: (context, d, _) {
          final cl = (d['closing'] as Map?) ?? const {};
          final lm = (d['last_month'] as Map?) ?? const {};
          final lmt = (lm['totals'] as Map?) ?? const {};
          final mtd = (d['mtd'] as Map?) ?? const {};
          final assist = ((d['assist'] as Map?)?['items'] as List?) ?? const [];
          return ListView(padding: const EdgeInsets.all(16), children: [
            Text('Business date ${d['business_date']}', style: Theme.of(context).textTheme.bodySmall),
            for (final a in assist)
              Banner2((a as Map)['msg'].toString(),
                  color: a['level'] == 'block' ? const Color(0xFFFDE2E1) : const Color(0xFFFFE9D6),
                  icon: a['level'] == 'block' ? Icons.priority_high : Icons.info_outline),
            SectionCard(title: 'Closing for ${cl['date'] ?? d['target_date']}', children: [
              Row(children: [const Text('Status'), const Spacer(), StatusChip(cl['status'] as String?)]),
              SumRow('Total Sale', rupees((cl['sales'] as Map?)?['total'])),
              SumRow('Expenses', rupees(cl['expenses_total'])),
              SumRow('Cash in hand (books)', rupees(d['cash_in_hand'])),
            ]),
            SectionCard(title: 'This month so far', children: [
              SumRow('Cash', rupees(mtd['cash'])),
              SumRow('Card', rupees(mtd['card'])),
              SumRow('UPI', rupees(mtd['upi'])),
              SumRow('Total Sale', rupees(mtd['total']), bold: true),
              SumRow('Expenses', rupees(mtd['expenses'])),
            ]),
            SectionCard(title: 'Last month ${lm['month'] ?? ''}', children: [
              SumRow('Total Sale', rupees(lmt['total']), bold: true),
              SumRow('Cash / Card / UPI', '${rupees(lmt['cash'], showPaise: false)} / ${rupees(lmt['card'], showPaise: false)} / ${rupees(lmt['upi'], showPaise: false)}'),
              SumRow('Expenses', rupees(lmt['expenses'])),
            ]),
            SectionCard(title: 'Position', children: [
              SumRow('Supplier payable', rupees(d['supplier_payable'])),
              SumRow('Supplier overdue', rupees(d['supplier_overdue'])),
              SumRow('Staff advances outstanding', rupees(d['staff_advance'])),
              SumRow('Stock value', rupees(d['stock_value'])),
              SumRow('Low-stock items', '${d['low_stock'] ?? 0}'),
              SumRow('Active staff', '${d['staff_count'] ?? 0}'),
            ]),
            if ((d['accounts'] as List?)?.isNotEmpty ?? false)
              SectionCard(title: 'Accounts', children: [
                for (final a in d['accounts'] as List) SumRow((a as Map)['account'].toString(), rupees(a['balance'])),
              ]),
          ]);
        },
      );
}
