import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

const deptOrder = ['Management', 'UT', 'Kitchen', 'Waiter'];

class StaffScreen extends StatefulWidget {
  const StaffScreen({super.key, required this.session});
  final Session session;
  @override
  State<StaffScreen> createState() => _StaffScreenState();
}

class _StaffScreenState extends State<StaffScreen> {
  String q = '';
  String status = 'active';

  final Set<String> _open = {};

  @override
  Widget build(BuildContext context) => Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
          child: Column(children: [
            SearchBox(hint: 'Search staff, code or designation', onChanged: (v) => setState(() => q = v.toLowerCase())),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: Wrap(spacing: 8, children: [
                for (final o in const [('active', 'Active'), ('inactive', 'Inactive'), ('all', 'All')])
                  ChoiceChip(label: Text(o.$2), selected: status == o.$1, onSelected: (_) => setState(() => status = o.$1)),
              ]),
            ),
          ]),
        ),
        Expanded(
          child: Loader<Map<String, dynamic>>(
            key: ValueKey(status),
            load: () => widget.session.api.get('employees', {if (status != 'all') 'status': status}),
            builder: (context, d, _) {
              final p = widget.session.permissions!;
              final showPay = p.can('payroll.view') || p.can('staff.manage');
              final staff = [for (final s in (d['staff'] as List? ?? const [])) (s as Map).cast<String, dynamic>()]
                  .where((s) => '${s['name']} ${s['code']} ${s['designation']}'.toLowerCase().contains(q))
                  .toList();
              final groups = <String, List<Map<String, dynamic>>>{};
              for (final s in staff) {
                groups.putIfAbsent((s['dept'] ?? 'No department').toString(), () => []).add(s);
              }
              final keys = [...deptOrder.where(groups.containsKey), ...groups.keys.where((k) => !deptOrder.contains(k))];
              return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
                if (staff.isEmpty)
                  const EmptyState(icon: Icons.groups_outlined, title: 'No staff found', subtitle: 'Try another search or status.'),
                for (final k in keys)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: SoftCard(
                      padding: EdgeInsets.zero,
                      child: ExpansionTile(
                        key: PageStorageKey('dept-$k'),
                        shape: const Border(),
                        initiallyExpanded: q.isNotEmpty || keys.length == 1 || _open.contains(k),
                        onExpansionChanged: (v) => v ? _open.add(k) : _open.remove(k),
                        leading: const CircleAvatar(backgroundColor: brandPeach, child: Icon(Icons.groups_outlined, color: brandBurnt)),
                        title: Text('$k (${groups[k]!.length})', style: const TextStyle(fontWeight: FontWeight.w800)),
                        children: [
                          for (final s in groups[k]!)
                            ListTile(
                              leading: CircleAvatar(
                                radius: 18,
                                backgroundColor: brandCream,
                                child: Text(_initials('${s['name']}'),
                                    style: const TextStyle(fontSize: 12, color: brandBurnt, fontWeight: FontWeight.w800)),
                              ),
                              title: Text('${s['name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                              subtitle: Text('${s['code'] ?? ''} · ${s['designation'] ?? ''}'
                                  '${s['joined_on'] != null ? ' · joined ${s['joined_on']}' : ''}'
                                  '${s['status'] != 'active' ? ' · ${s['status']}' : ''}'),
                              trailing: TrailingStack(children: [
                                if (showPay && s['monthly'] != null)
                                  Text(rupees(s['monthly'], showPaise: false), style: const TextStyle(fontWeight: FontWeight.w800)),
                                if ((s['advance_outstanding'] as num? ?? 0) > 0)
                                  Text('adv ${rupees(s['advance_outstanding'])}', style: const TextStyle(fontSize: 12, color: brandBurnt)),
                              ]),
                            ),
                        ],
                      ),
                    ),
                  ),
              ]);
            },
          ),
        ),
      ]);
}

String _initials(String n) {
  final parts = n.replaceAll(RegExp(r'^DEMO\s+'), '').split(RegExp(r'\s+')).where((x) => x.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  return (parts.first[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
}
