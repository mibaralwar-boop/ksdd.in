import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Purchase bill history (`GET purchase-bills`) and one bill (`GET purchase-bills/one`). New bills are entered in Purchase.
class PurchaseBillsScreen extends StatefulWidget {
  const PurchaseBillsScreen({super.key, required this.session});
  final Session session;
  @override
  State<PurchaseBillsScreen> createState() => _PurchaseBillsScreenState();
}

class _PurchaseBillsScreenState extends State<PurchaseBillsScreen> {
  String status = 'all';
  String pay = '';
  String q = '';
  final _search = TextEditingController();

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        key: ValueKey('$status|$pay|$q'),
        load: () => widget.session.api.get('purchase-bills', {'status': status, if (pay.isNotEmpty) 'pay': pay, if (q.isNotEmpty) 'q': q}),
        builder: (context, d, _) {
          final bills = [for (final x in (d['bills'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final month = (d['month'] as Map?) ?? const {};
          final counts = (d['counts'] as Map?) ?? const {};
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            HeroCard(
              title: 'Posted this month',
              value: rupees(month['total'], showPaise: false),
              icon: Icons.receipt_outlined,
              parts: [
                ('Bills', '${month['count'] ?? 0}'),
                ('In review', '${counts['review'] ?? 0}'),
                ('Goods pending', '${counts['pending'] ?? 0}'),
              ],
            ),
            TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search_rounded), hintText: 'Bill no, invoice, supplier or item'),
              onSubmitted: (v) => setState(() => q = v.trim()),
            ),
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(children: [
                for (final o in const [
                  ('all', '', 'All'),
                  ('drafts', '', 'My drafts'),
                  ('review', '', 'In review'),
                  ('pending_receipt', '', 'Goods pending'),
                  ('all', 'unpaid', 'Unpaid'),
                  ('all', 'overdue', 'Overdue')
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(o.$3),
                      selected: status == o.$1 && pay == o.$2,
                      onSelected: (_) => setState(() {
                        status = o.$1;
                        pay = o.$2;
                      }),
                    ),
                  ),
              ]),
            ),
            if (bills.isEmpty)
              const EmptyState(
                  icon: Icons.receipt_long_outlined, title: 'No bills here', subtitle: 'Bills entered in Purchase appear in this list.'),
            for (final b in bills)
              Card(
                child: ListTile(
                  onTap: () => Navigator.push(
                      context, MaterialPageRoute<void>(builder: (_) => BillDetail(session: widget.session, id: (b['id'] as num).toInt()))),
                  title: Text('${b['supplier'] ?? 'No supplier'}', style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text([
                    b['bill_no'],
                    b['post_date'] ?? b['invoice_date'],
                    '${b['n_lines']} items',
                    if ((b['n_files'] ?? 0) == 0) 'no photo',
                    if (((b['overdue_days'] as num?) ?? 0) > 0) 'overdue ${b['overdue_days']} days',
                  ].where((x) => x != null).join(' · ')),
                  trailing: TrailingStack(children: [
                    Text(rupees(b['grand_total'], showPaise: false), style: const TextStyle(fontWeight: FontWeight.w900)),
                    StatusChip((b['payment_status'] ?? b['status']) as String?),
                  ]),
                ),
              ),
          ]);
        },
      );
}

class BillDetail extends StatelessWidget {
  const BillDetail({super.key, required this.session, required this.id});
  final Session session;
  final int id;
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Purchase bill')),
        body: Loader<Map<String, dynamic>>(
          load: () => session.api.get('purchase-bills/one', {'id': '$id'}),
          builder: (context, d, _) {
            final b = (d['bill'] as Map).cast<String, dynamic>();
            final s = (d['supplier'] as Map?)?.cast<String, dynamic>();
            final lines = [for (final x in (d['lines'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
            final files = (d['files'] as List?) ?? const [];
            final audit = [for (final x in (d['audit'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
            return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
              PageTitle('${b['bill_no'] ?? 'Draft'}',
                  subtitle: '${s?['name'] ?? ''} · ${b['post_date'] ?? b['invoice_date'] ?? ''}',
                  trailing: StatusChip((b['payment_status'] ?? b['status']) as String?)),
              SectionCard(title: 'Items', icon: Icons.shopping_basket_outlined, children: [
                for (final l in lines)
                  SumRow(
                      '${l['item_name'] ?? l['item'] ?? ''}  ${l['qty']} ${l['unit'] ?? ''} × ${rupees(l['rate'])}', rupees(l['amount'])),
                const Divider(),
                SumRow('Bill total', rupees(b['grand_total']), bold: true),
                if (b['paid'] != null) SumRow('Paid', rupees(b['paid'])),
                if (b['outstanding'] != null) SumRow('Outstanding', rupees(b['outstanding']), color: brandRed),
              ]),
              SectionCard(title: 'Details', children: [
                if (b['invoice_no'] != null) SumRow('Supplier invoice no.', '${b['invoice_no']}'),
                if (b['due_date'] != null) SumRow('Due date', '${b['due_date']}'),
                SumRow('Goods', '${b['receipt_status'] ?? '—'}'),
                SumRow('Bill photos', '${files.length}'),
              ]),
              if (files.isEmpty && b['status'] != 'draft')
                const Banner2('No bill photo attached. Add it from Purchase → open the bill on the website.', color: brandRedBg),
              if (audit.isNotEmpty)
                SectionCard(title: 'History', icon: Icons.history_rounded, children: [
                  for (final a in audit.take(10))
                    Text('${a['at'] ?? a['created_at'] ?? ''} · ${a['user'] ?? a['by'] ?? ''} · ${a['action'] ?? ''}',
                        style: Theme.of(context).textTheme.bodySmall),
                ]),
            ]);
          },
        ),
      );
}
