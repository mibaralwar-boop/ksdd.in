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

  @override
  Widget build(BuildContext context) => Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Row(children: [
            Expanded(
              child: TextField(
                decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Search staff'),
                onChanged: (v) => setState(() => q = v.toLowerCase()),
              ),
            ),
            const SizedBox(width: 8),
            DropdownButton<String>(
              value: status,
              items: const [
                DropdownMenuItem(value: 'active', child: Text('Active')),
                DropdownMenuItem(value: 'inactive', child: Text('Inactive')),
                DropdownMenuItem(value: 'all', child: Text('All')),
              ],
              onChanged: (v) => setState(() => status = v ?? 'active'),
            ),
          ]),
        ),
        Expanded(
          child: Loader<Map<String, dynamic>>(
            key: ValueKey(status),
            load: () => widget.session.api.get('employees', {if (status != 'all') 'status': status}),
            builder: (context, d, _) {
              final staff = [for (final s in (d['staff'] as List? ?? const [])) (s as Map).cast<String, dynamic>()]
                  .where((s) => '${s['name']} ${s['code']} ${s['designation']}'.toLowerCase().contains(q))
                  .toList();
              final groups = <String, List<Map<String, dynamic>>>{};
              for (final s in staff) {
                groups.putIfAbsent((s['dept'] ?? 'No department').toString(), () => []).add(s);
              }
              final keys = [...deptOrder.where(groups.containsKey), ...groups.keys.where((k) => !deptOrder.contains(k))];
              return ListView(padding: const EdgeInsets.all(16), children: [
                if (staff.isEmpty) const Text('No staff found.'),
                for (final k in keys)
                  SectionCard(title: '$k (${groups[k]!.length})', children: [
                    for (final s in groups[k]!)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text('${s['name']}'),
                        subtitle: Text('${s['code'] ?? ''} · ${s['designation'] ?? ''} · joined ${s['joined_on'] ?? 'unknown'}'),
                        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                          Text(rupees(s['monthly'], showPaise: false)),
                          if ((s['advance_outstanding'] as num? ?? 0) > 0)
                            Text('adv ${rupees(s['advance_outstanding'])}', style: const TextStyle(fontSize: 12, color: Color(0xFFB26A00))),
                        ]),
                      ),
                  ]),
              ]);
            },
          ),
        ),
      ]);
}
