import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Owner / manager home. Every figure comes from `GET dashboard` (server ledger, integer paise) — nothing is sample data.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key, required this.session, this.open});
  final Session session;
  final void Function(String)? open;
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _lastMonth = false;

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    final open = widget.open;
    void go(String k) => open?.call(k);
    return Loader<Map<String, dynamic>>(
      load: () => widget.session.api.get('dashboard'),
      builder: (context, d, _) {
        final cl = (d['closing'] as Map?) ?? const {};
        final lm = (d['last_month'] as Map?) ?? const {};
        final lmt = (lm['totals'] as Map?) ?? const {};
        final mtd = (d['mtd'] as Map?) ?? const {};
        final assist = ((d['assist'] as Map?)?['items'] as List?) ?? const [];
        final t = _lastMonth ? lmt : mtd;
        final accounts = (d['accounts'] as List?) ?? const [];
        final status = (cl['status'] ?? '').toString();
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          Text('Business date ${d['business_date']}', style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 6),
          SizedBox(
            width: double.infinity,
            child: SegmentedButton<bool>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: false, label: Text('This month')),
                ButtonSegment(value: true, label: Text('Last month')),
              ],
              selected: {_lastMonth},
              onSelectionChanged: (v) => setState(() => _lastMonth = v.first),
            ),
          ),
          HeroCard(
            title: _lastMonth ? 'Total sale · ${lm['month'] ?? 'last month'}' : 'Total sale · this month so far',
            value: rupees(t['total'], showPaise: false),
            parts: [
              ('Cash', rupees(t['cash'], showPaise: false)),
              ('Card', rupees(t['card'], showPaise: false)),
              ('UPI', rupees(t['upi'], showPaise: false)),
            ],
            onTap: p.can('sales.view') && open != null ? () => go('sales') : null,
          ),
          Row(children: [
            Expanded(
              child: MetricTile(
                label: 'Expenses',
                value: rupees(t['expenses'], showPaise: false),
                icon: Icons.receipt_long_outlined,
                onTap: p.can('expenses.view') && open != null ? () => go('expenses') : null,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MetricTile(
                label: 'Supplier payable',
                value: rupees(d['supplier_payable'], showPaise: false),
                icon: Icons.local_shipping_outlined,
                sub: (d['supplier_overdue'] ?? 0) != 0 ? 'Overdue ${rupees(d['supplier_overdue'], showPaise: false)}' : null,
                onTap: p.can('suppliers.view') && open != null ? () => go('suppliers') : null,
              ),
            ),
          ]),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
              child: MetricTile(
                label: 'Stock value',
                value: rupees(d['stock_value'], showPaise: false),
                icon: Icons.inventory_2_outlined,
                sub: (d['low_stock'] ?? 0) != 0 ? '${d['low_stock']} low-stock items' : null,
                onTap: p.can('stock.view') && open != null ? () => go('stock') : null,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: MetricTile(
                label: 'Active staff',
                value: '${d['staff_count'] ?? 0}',
                icon: Icons.groups_outlined,
                onTap: p.can('staff.view') && open != null ? () => go('staff') : null,
              ),
            ),
          ]),
          if (assist.isNotEmpty) ...[
            const SizedBox(height: 18),
            Text('Action center', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 6),
            for (final a in assist)
              Banner2((a as Map)['msg'].toString(),
                  color: a['level'] == 'block' ? brandRedBg : brandPeach,
                  icon: a['level'] == 'block' ? Icons.priority_high_rounded : Icons.info_outline_rounded),
          ],
          const SizedBox(height: 8),
          SectionCard(
            title: 'Closing for ${cl['date'] ?? d['target_date']}',
            icon: status == 'approved' ? Icons.lock_outline_rounded : Icons.point_of_sale_rounded,
            trailing: StatusChip(cl['status'] as String?),
            children: [
              SumRow('Total Sale', rupees((cl['sales'] as Map?)?['total'])),
              SumRow('Expenses', rupees(cl['expenses_total'])),
              SumRow('Cash in hand (books)', rupees(d['cash_in_hand'])),
              if (p.can('closing.view') && open != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => go('closing'),
                    icon: const Icon(Icons.arrow_forward_rounded),
                    label: Text(status == 'approved' ? 'View closing' : 'Open Daily Closing'),
                  ),
                ),
            ],
          ),
          SectionCard(title: 'Position', icon: Icons.layers_outlined, children: [
            SumRow('Supplier payable', rupees(d['supplier_payable'])),
            SumRow('Supplier overdue', rupees(d['supplier_overdue'])),
            SumRow('Staff advances outstanding', rupees(d['staff_advance'])),
            SumRow('Stock value', rupees(d['stock_value'])),
            SumRow('Low-stock items', '${d['low_stock'] ?? 0}'),
            SumRow('Active staff', '${d['staff_count'] ?? 0}'),
          ]),
          if (accounts.isNotEmpty)
            SectionCard(title: 'Accounts', icon: Icons.account_balance_outlined, children: [
              for (final a in accounts) SumRow((a as Map)['account'].toString(), rupees(a['balance'])),
              if (p.can('accounts.view') && open != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => go('accounts'),
                    icon: const Icon(Icons.arrow_forward_rounded),
                    label: const Text('Account books'),
                  ),
                ),
            ]),
          if (!_lastMonth)
            SectionCard(title: 'Last month ${lm['month'] ?? ''}', icon: Icons.calendar_today_outlined, children: [
              SumRow('Total Sale', rupees(lmt['total']), bold: true),
              SumRow('Cash / Card / UPI',
                  '${rupees(lmt['cash'], showPaise: false)} / ${rupees(lmt['card'], showPaise: false)} / ${rupees(lmt['upi'], showPaise: false)}'),
              SumRow('Expenses', rupees(lmt['expenses'])),
            ]),
        ]);
      },
    );
  }
}
