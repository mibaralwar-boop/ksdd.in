import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Stock (`GET stock`, `GET items`, `GET stock/moves`) with issue / wastage / adjustment entries (`POST stock/move`).
/// No kitchen KOT or menu: this is inventory only. Goods are received through Purchase.
class StockScreen extends StatefulWidget {
  const StockScreen({super.key, required this.session});
  final Session session;
  @override
  State<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends State<StockScreen> {
  String q = '';
  bool lowOnly = false;
  bool moves = false;

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    final canMove = (p.can('stock.issue') || p.can('stock.adjust')) && !p.readOnly;
    return Loader<List<Map<String, dynamic>>>(
      load: () async => [
        await widget.session.api.get('stock'),
        await widget.session.api.get('items'),
        await _moves(p),
      ],
      builder: (context, d, reload) {
        final st = d[0];
        final it = d[1];
        final mv = [for (final x in (d[2]['rows'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        final items = [for (final x in (it['items'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        final low = items.where((x) => x['low'] == true || x['out'] == true).toList();
        final shown =
            (lowOnly ? low : items).where((x) => '${x['name']} ${x['category'] ?? ''}'.toLowerCase().contains(q.toLowerCase())).toList();
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          HeroCard(
            title: 'Stock value',
            value: rupees(st['value'], showPaise: false),
            icon: Icons.inventory_2_outlined,
            parts: [
              ('Items', '${st['items_in_stock'] ?? items.length}'),
              ('Low / out', '${low.length}'),
              ('Goods pending', '${(st['pending_receipts'] as List?)?.length ?? 0}'),
            ],
          ),
          SectionCard(children: [
            SumRow('Stock value', rupees(st['value']), bold: true),
            SumRow('Last physical count', '${(st['last_count'] as Map?)?['date'] ?? st['last_count'] ?? 'none'}'),
            SumRow('Inventory method', '${st['model'] ?? ''}'),
          ]),
          SegmentedButton<bool>(
            showSelectedIcon: false,
            segments: const [ButtonSegment(value: false, label: Text('Items')), ButtonSegment(value: true, label: Text('Movements'))],
            selected: {moves},
            onSelectionChanged: (v) => setState(() => moves = v.first),
          ),
          const SizedBox(height: 10),
          if (!moves) ...[
            SearchBox(hint: 'Search item or category', onChanged: (v) => setState(() => q = v)),
            const SizedBox(height: 8),
            Wrap(spacing: 8, children: [
              ChoiceChip(label: Text('All (${items.length})'), selected: !lowOnly, onSelected: (_) => setState(() => lowOnly = false)),
              ChoiceChip(label: Text('Low stock (${low.length})'), selected: lowOnly, onSelected: (_) => setState(() => lowOnly = true)),
            ]),
            if (shown.isEmpty)
              const EmptyState(icon: Icons.inventory_2_outlined, title: 'No items', subtitle: 'Nothing matches this filter.'),
            for (final x in shown)
              Card(
                child: ListTile(
                  title: Text(x['name'].toString(), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${x['category'] ?? ''} · ${x['location'] ?? ''} · avg ${rupees(x['avg_cost'])}/${x['unit']}'),
                  trailing: TrailingStack(children: [
                    Text('${x['qty']} ${x['unit']}',
                        style: TextStyle(fontWeight: FontWeight.w800, color: x['low'] == true || x['out'] == true ? brandRed : null)),
                    Text(rupees(x['value']), style: const TextStyle(fontSize: 12)),
                  ]),
                  onTap: canMove
                      ? () async {
                          final ok = await Navigator.push<bool>(
                              context, MaterialPageRoute(builder: (_) => StockMoveForm(session: widget.session, item: x)));
                          if (ok == true) await reload();
                        }
                      : null,
                ),
              ),
            if (canMove)
              Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('Tap an item to record use, wastage or a correction.', style: Theme.of(context).textTheme.bodySmall)),
          ] else ...[
            if (mv.isEmpty)
              const EmptyState(
                  icon: Icons.swap_vert_rounded, title: 'No movements this month', subtitle: 'Receipts, issues and counts appear here.'),
            for (final m in mv.reversed)
              Card(
                child: ListTile(
                  title: Text('${m['date']} · ${_kind(m['kind'].toString())}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(
                      '${items.where((i) => i['id'] == m['item_id']).firstOrNull?['name'] ?? 'Item ${m['item_id']}'} · ${m['location'] ?? ''}'
                      '${m['reason'] != null ? ' · ${m['reason']}' : ''}',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis),
                  trailing: Text('${((m['qty'] as num?) ?? 0) > 0 ? '+' : ''}${m['qty']}',
                      style: TextStyle(fontWeight: FontWeight.w800, color: ((m['qty'] as num?) ?? 0) >= 0 ? brandGreen : brandRed)),
                ),
              ),
          ],
          const SizedBox(height: 8),
          const Text('Goods are received through Purchase. Physical stock counts are done on the website in this version.',
              style: TextStyle(fontSize: 12, color: brandMuted)),
        ]);
      },
    );
  }

  Future<Map<String, dynamic>> _moves(Permissions p) async {
    if (!p.can('stock.view')) return const {};
    try {
      return await widget.session.api.get('stock/moves');
    } on ApiError catch (e) {
      if (e.isForbidden || e.status == 404) return const {};
      rethrow;
    }
  }

  static String _kind(String k) => switch (k) {
        'opening' => 'Opening',
        'receive' || 'purchase' => 'Received',
        'issue' => 'Issued / used',
        'wastage' => 'Wastage',
        'damage' => 'Damage',
        'expired' => 'Expired',
        'adjust_in' => 'Correction +',
        'adjust_out' => 'Correction −',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'count' => 'Physical count',
        _ => k,
      };
}

class StockMoveForm extends StatefulWidget {
  const StockMoveForm({super.key, required this.session, required this.item});
  final Session session;
  final Map<String, dynamic> item;
  @override
  State<StockMoveForm> createState() => _StockMoveFormState();
}

class _StockMoveFormState extends State<StockMoveForm> {
  late final kinds = [
    if (widget.session.permissions!.can('stock.issue')) ...const [
      ('issue', 'Used / issued'),
      ('wastage', 'Wastage'),
      ('damage', 'Damage'),
      ('expired', 'Expired')
    ],
    if (widget.session.permissions!.can('stock.adjust')) ...const [('adjust_in', 'Correction +'), ('adjust_out', 'Correction −')],
  ];
  late String kind = kinds.first.$1;
  final qty = TextEditingController();
  final reason = TextEditingController();
  bool busy = false;
  final String _key = randomKey();

  @override
  Widget build(BuildContext context) {
    final i = widget.item;
    return Scaffold(
      appBar: AppBar(title: Text(i['name'].toString())),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Banner2('In stock: ${i['qty']} ${i['unit']} · ${i['location'] ?? 'Main Store'}'),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final k in kinds) ChoiceChip(label: Text(k.$2), selected: kind == k.$1, onSelected: (_) => setState(() => kind = k.$1)),
        ]),
        const SizedBox(height: 12),
        NumberField(controller: qty, label: 'Quantity (${i['unit']})'),
        const SizedBox(height: 12),
        TextField(controller: reason, decoration: const InputDecoration(labelText: 'Reason *')),
        const SizedBox(height: 16),
        FilledButton(onPressed: busy ? null : _save, child: const Text('Save movement')),
      ]),
    );
  }

  Future<void> _save() async {
    if (qty.text.trim().isEmpty || reason.text.trim().length < 3) {
      showMessage(context, 'Enter the quantity and a reason.', error: true);
      return;
    }
    setState(() => busy = true);
    try {
      await widget.session.api.post(
          'stock/move',
          {
            'kind': kind,
            'item_id': widget.item['id'],
            'qty': qty.text.trim(),
            'unit': widget.item['unit'],
            'location': widget.item['location'] ?? 'Main Store',
            'reason': reason.text.trim(),
            'client_key': _key,
          },
          idem: _key);
      if (mounted) {
        showMessage(context, 'Saved.');
        Navigator.pop(context, true);
      }
    } on ApiError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }
}
