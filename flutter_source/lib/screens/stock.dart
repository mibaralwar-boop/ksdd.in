import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class StockScreen extends StatelessWidget {
  const StockScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) => Loader<List<Map<String, dynamic>>>(
        load: () async => [await session.api.get('stock'), await session.api.get('items')],
        builder: (context, d, _) {
          final st = d[0];
          final it = d[1];
          final items = it['items'] as List? ?? const [];
          final low = items.where((x) => (x as Map)['low'] == true || x['out'] == true).toList();
          return ListView(padding: const EdgeInsets.all(16), children: [
            SectionCard(title: 'Stock summary', children: [
              SumRow('Stock value', rupees(st['value']), bold: true),
              SumRow('Items in stock', '${st['items_in_stock'] ?? 0}'),
              SumRow('Low / out of stock', '${low.length}'),
              SumRow('Pending goods receipts', '${(st['pending_receipts'] as List?)?.length ?? 0}'),
              SumRow('Last physical count', '${(st['last_count'] as Map?)?['date'] ?? st['last_count'] ?? 'none'}'),
              SumRow('Inventory method', '${st['model'] ?? ''}'),
            ]),
            if (low.isNotEmpty)
              SectionCard(title: 'Reorder', children: [
                for (final x in low) SumRow((x as Map)['name'].toString(), '${x['qty']} ${x['unit']}', color: const Color(0xFFB3261E)),
              ]),
            SectionCard(title: 'Items (${items.length})', children: [
              for (final x in items)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text((x as Map)['name'].toString()),
                  subtitle: Text('${x['category'] ?? ''} · ${x['location'] ?? ''} · avg ${rupees(x['avg_cost'])}/${x['unit']}'),
                  trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text('${x['qty']} ${x['unit']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(rupees(x['value']), style: const TextStyle(fontSize: 12)),
                  ]),
                ),
            ]),
            const Text('Receiving, consumption and physical counts are done on the website in this version.', style: TextStyle(fontSize: 12)),
          ]);
        },
      );
}
