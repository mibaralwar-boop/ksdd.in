import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Alerts the server raised for THIS user's permissions (`GET notifications`); "Remind me later" snoozes per user.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.session});
  final Session session;
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  bool all = false;

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        key: ValueKey(all),
        load: () => widget.session.api.get('notifications', {if (all) 'all': '1'}),
        builder: (context, d, reload) {
          final items = [for (final x in (d['items'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final corr = [for (final x in (d['corrections'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            Wrap(spacing: 8, children: [
              ChoiceChip(label: const Text('Open'), selected: !all, onSelected: (_) => setState(() => all = false)),
              ChoiceChip(label: const Text('All'), selected: all, onSelected: (_) => setState(() => all = true)),
            ]),
            if (items.isEmpty)
              const EmptyState(icon: Icons.notifications_off_outlined, title: 'All clear', subtitle: 'No open alerts for you right now.'),
            for (final n in items)
              Card(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 12, 8, 6),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Icon(_icon(n['kind'].toString()), color: brandOrange),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(n['title'].toString(), style: const TextStyle(fontWeight: FontWeight.w800)),
                          if ((n['body'] ?? '') != '') Text(n['body'].toString(), style: Theme.of(context).textTheme.bodySmall),
                          Text((n['created_at'] ?? '').toString(), style: Theme.of(context).textTheme.bodySmall),
                        ]),
                      ),
                      if (n['status'] != 'open')
                        const StatusPill('Done', tone: PillTone.good)
                      else if (n['snoozed'] == true)
                        const StatusPill('Snoozed', tone: PillTone.info)
                      else
                        const StatusPill('New'),
                    ]),
                    if (n['status'] == 'open' && n['snoozed'] != true)
                      Align(
                        alignment: Alignment.centerRight,
                        child: TextButton(
                          onPressed: () async {
                            try {
                              await widget.session.api.post('f360/snooze', {'key': n['dedupe_key'], 'minutes': 240});
                              await reload();
                            } on ApiError catch (e) {
                              if (context.mounted) showMessage(context, e.message, error: true);
                            }
                          },
                          child: const Text('Remind me later'),
                        ),
                      ),
                  ]),
                ),
              ),
            if (corr.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text('Closing correction requests', style: Theme.of(context).textTheme.titleLarge),
              for (final c in corr)
                Card(
                  child: ListTile(
                    title: Text('${c['business_date'] ?? c['date'] ?? ''} · ${c['requested_by_name'] ?? ''}'),
                    subtitle: Text((c['reason'] ?? '').toString(), maxLines: 3, overflow: TextOverflow.ellipsis),
                    trailing: StatusPill((c['status'] ?? '').toString(), tone: c['status'] == 'open' ? PillTone.neutral : PillTone.info),
                  ),
                ),
              const Banner2('Approve or reject corrections on the website (Daily Closing → Corrections).'),
            ],
          ]);
        },
      );

  static IconData _icon(String k) {
    if (k.contains('closing')) return Icons.point_of_sale_rounded;
    if (k.contains('handover')) return Icons.swap_horiz_rounded;
    if (k.contains('doc')) return Icons.folder_outlined;
    if (k.contains('stock')) return Icons.inventory_2_outlined;
    if (k.contains('salary') || k.contains('payroll')) return Icons.calendar_month_outlined;
    if (k.contains('supplier') || k.contains('bill')) return Icons.local_shipping_outlined;
    return Icons.notifications_active_outlined;
  }
}
