import 'package:flutter/material.dart';

import '../core/api.dart';
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
  int _rev = 0;

  Future<void> _openDetail(Map<String, dynamic> s) async {
    await Navigator.push(
        context, MaterialPageRoute<void>(builder: (_) => StaffDetailScreen(session: widget.session, id: (s['id'] as num).toInt())));
    setState(() => _rev++);
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
          child: Column(children: [
            if (widget.session.permissions!.can('staff.manage') && !widget.session.permissions!.readOnly)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    icon: const Icon(Icons.person_add_alt_1_rounded),
                    label: const Text('Add staff'),
                    onPressed: () async {
                      final ok = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => StaffForm(session: widget.session)));
                      if (ok == true) setState(() => _rev++);
                    },
                  ),
                ),
              ),
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
            key: ValueKey('$status-$_rev'),
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
                              onTap: () => _openDetail(s),
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

/// Staff profile with balances and history (`GET employees/one`). Mark Left / Rejoin need `staff.manage`.
class StaffDetailScreen extends StatelessWidget {
  const StaffDetailScreen({super.key, required this.session, required this.id});
  final Session session;
  final int id;
  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    final manage = p.can('staff.manage') && !p.readOnly;
    return Scaffold(
      appBar: AppBar(title: const Text('Staff')),
      body: Loader<Map<String, dynamic>>(
        load: () => session.api.get('employees/one', {'id': '$id'}),
        builder: (context, d, reload) {
          final s = ((d['staff'] as Map?) ?? const {}).cast<String, dynamic>();
          final b = ((d['balances'] as Map?) ?? const {}).cast<String, dynamic>();
          final left = s['status'] == 'left' || s['active'] == 0;
          final showPay = p.can('payroll.view') || p.can('staff.manage');
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            PageTitle('${s['name']}',
                subtitle: '${s['code'] ?? ''} · ${s['dept'] ?? ''} · ${s['designation'] ?? ''}',
                trailing: left ? const StatusPill('Left', tone: PillTone.bad) : const StatusPill('Active', tone: PillTone.good)),
            SectionCard(title: 'Employment', icon: Icons.badge_outlined, children: [
              SumRow('Joined', '${s['joined_on'] ?? 'not recorded'}'),
              if (left) SumRow('Last working day', '${s['left_on'] ?? ''}'),
              if (left && s['exit_reason'] != null) SumRow('Reason', '${s['exit_reason']}'),
              if (showPay) SumRow('Basic monthly salary', rupees(s['monthly'])),
              SumRow('Paid by', '${s['pay_mode'] ?? ''}'),
              if (s['phone'] != null) SumRow('Phone', '${s['phone']}'),
              if (p.can('staff.bank') && s['account'] != null) SumRow('Bank account', '${s['bank_name'] ?? ''} ${s['account']}'),
            ]),
            SectionCard(title: 'Balances', icon: Icons.account_balance_wallet_outlined, children: [
              SumRow('Advance outstanding (employee owes)', rupees(b['advance_outstanding']),
                  color: ((b['advance_outstanding'] as num?) ?? 0) > 0 ? brandRed : null),
              SumRow('Penalties approved, not yet deducted', rupees(b['penalty_approved_unapplied'])),
              SumRow('Penalties waiting for approval', rupees(b['penalty_pending_approval'])),
              SumRow('Overtime not yet paid', rupees(b['ot_unpaid'])),
            ]),
            if (manage && !left)
              OutlinedButton.icon(
                icon: const Icon(Icons.edit_outlined),
                label: const Text('Edit details'),
                onPressed: () async {
                  final ok =
                      await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => StaffForm(session: session, existing: s)));
                  if (ok == true) await reload();
                },
              ),
            const SizedBox(height: 8),
            if (manage && !left)
              OutlinedButton.icon(
                icon: const Icon(Icons.logout_rounded),
                label: const Text('Mark left'),
                onPressed: () async {
                  final ok =
                      await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => MarkLeftScreen(session: session, employee: s)));
                  if (ok == true) await reload();
                },
              ),
            if (manage && left)
              FilledButton.icon(
                icon: const Icon(Icons.restart_alt_rounded),
                label: const Text('Rejoin (make active again)'),
                onPressed: () async {
                  if (!await confirmDialog(
                      context,
                      'Rejoin ${s['name']}?',
                      'The same staff record and code are reactivated; all old salary months and advances stay as they are. '
                          'Check the joining date afterwards.',
                      ok: 'Rejoin')) {
                    return;
                  }
                  try {
                    await session.api.post('f360/staff-exit', {'id': id, 'rejoin': 1});
                    await reload();
                  } on ApiError catch (e) {
                    if (context.mounted) showMessage(context, e.message, error: true);
                  }
                },
              ),
            const SizedBox(height: 8),
            const Banner2('Staff are never deleted. Leaving keeps the record, salary months, advances and payments.'),
          ]);
        },
      ),
    );
  }
}

/// Mark Left: last working day and reason are mandatory. Status change only — history is kept.
class MarkLeftScreen extends StatefulWidget {
  const MarkLeftScreen({super.key, required this.session, required this.employee});
  final Session session;
  final Map<String, dynamic> employee;
  @override
  State<MarkLeftScreen> createState() => _MarkLeftScreenState();
}

class _MarkLeftScreenState extends State<MarkLeftScreen> {
  String? date;
  final reason = TextEditingController();
  final remarks = TextEditingController();
  bool busy = false;
  String? error;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('Mark ${widget.employee['name']} as left')),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          OutlinedButton.icon(
            icon: const Icon(Icons.event_outlined),
            label: Text(date == null ? 'Last working day *' : 'Last working day: $date'),
            onPressed: () async {
              final bd = parseYmd(widget.session.permissions!.businessDate);
              final d = await showDatePicker(context: context, firstDate: DateTime(2020), lastDate: bd, initialDate: bd);
              if (d != null) setState(() => date = ymd(d));
            },
          ),
          const SizedBox(height: 12),
          TextField(controller: reason, decoration: const InputDecoration(labelText: 'Reason for leaving *')),
          const SizedBox(height: 12),
          TextField(controller: remarks, decoration: const InputDecoration(labelText: 'Remarks (optional)')),
          const SizedBox(height: 12),
          const Banner2('The salary for days worked up to the last working day, unpaid arrears and any advance still owed '
              'stay on the record for the final settlement. This is not a delete.'),
          if (error != null) Banner2(error!, color: brandRedBg, icon: Icons.error_outline_rounded),
          const SizedBox(height: 12),
          FilledButton(onPressed: busy ? null : _save, child: const Text('Mark left')),
        ]),
      );

  Future<void> _save() async {
    if (date == null || reason.text.trim().length < 3) {
      setState(() => error = 'Choose the last working day and write the reason.');
      return;
    }
    final sure =
        await confirmDialog(context, 'Mark left from $date?', '${widget.employee['name']}: ${reason.text.trim()}', ok: 'Mark left');
    if (!sure) return;
    setState(() => busy = true);
    try {
      final r = await widget.session.api.post('f360/staff-exit', {
        'id': widget.employee['id'],
        'left_on': date,
        'reason': [reason.text.trim(), if (remarks.text.trim().isNotEmpty) remarks.text.trim()].join(' — '),
      });
      if (!mounted) return;
      if (r['warning'] != null) {
        await showDialog<void>(
            context: context,
            builder: (c) => AlertDialog(title: const Text('Saved'), content: Text(r['warning'].toString()), actions: [
                  FilledButton(onPressed: () => Navigator.pop(c), child: const Text('OK')),
                ]));
      }
      if (mounted) Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }
}

/// Add / edit staff (`POST employees`). Staff code is unique on the server (duplicates are refused, never merged).
class StaffForm extends StatefulWidget {
  const StaffForm({super.key, required this.session, this.existing});
  final Session session;
  final Map<String, dynamic>? existing;
  @override
  State<StaffForm> createState() => _StaffFormState();
}

class _StaffFormState extends State<StaffForm> {
  late final e = widget.existing;
  late final name = TextEditingController(text: e?['name']?.toString() ?? '');
  late final code = TextEditingController(text: e?['code']?.toString() ?? '');
  late final monthly = TextEditingController(text: e == null ? '' : rupeeInput(e!['monthly']));
  late final phone = TextEditingController(text: e?['phone']?.toString() ?? '');
  late int? dept = (e?['dept_id'] as num?)?.toInt();
  late int? desig = (e?['desig_id'] as num?)?.toInt();
  late String? joined = e?['joined_on']?.toString();
  late String payMode = (e?['pay_mode'] ?? 'Cash').toString();
  bool busy = false;
  String? error;
  final String _key = randomKey();

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(e == null ? 'Add staff' : 'Edit ${e!['name']}')),
        body: Loader<Map<String, dynamic>>(
          load: () => widget.session.api.get('departments'),
          builder: (context, d, _) {
            final depts = [for (final x in (d['departments'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
            final desigs = [
              for (final x in (depts.where((x) => x['id'] == dept).firstOrNull?['designations'] as List? ?? const []))
                if ((x as Map)['active'] == 1 || x['id'] == desig) x.cast<String, dynamic>()
            ];
            return ListView(padding: const EdgeInsets.all(16), children: [
              TextField(controller: name, decoration: const InputDecoration(labelText: 'Full name *')),
              const SizedBox(height: 12),
              TextField(controller: code, decoration: const InputDecoration(labelText: 'Staff / HR code (empty = next free code)')),
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                initialValue: dept,
                decoration: const InputDecoration(labelText: 'Department *'),
                items: [for (final x in depts) DropdownMenuItem(value: (x['id'] as num).toInt(), child: Text(x['name'].toString()))],
                onChanged: (v) => setState(() {
                  dept = v;
                  desig = null;
                }),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                key: ValueKey('desig-$dept'),
                initialValue: desigs.any((x) => x['id'] == desig) ? desig : null,
                decoration: const InputDecoration(labelText: 'Designation * (Guard is under Waiter)'),
                items: [for (final x in desigs) DropdownMenuItem(value: (x['id'] as num).toInt(), child: Text(x['name'].toString()))],
                onChanged: (v) => setState(() => desig = v),
              ),
              const SizedBox(height: 12),
              MoneyField(controller: monthly, label: 'Basic monthly salary *'),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                icon: const Icon(Icons.event_outlined),
                label: Text(joined == null ? 'Joining date *' : 'Joining date: $joined'),
                onPressed: () async {
                  final bd = parseYmd(widget.session.permissions!.businessDate);
                  final x = await showDatePicker(
                      context: context,
                      firstDate: DateTime(2000),
                      lastDate: bd.add(const Duration(days: 60)),
                      initialDate: joined == null ? bd : parseYmd(joined!));
                  if (x != null) setState(() => joined = ymd(x));
                },
              ),
              const SizedBox(height: 12),
              TextField(
                  controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone (optional)')),
              const SizedBox(height: 12),
              Wrap(spacing: 8, children: [
                for (final m in const ['Cash', 'Bank', 'UPI'])
                  ChoiceChip(label: Text('Salary by $m'), selected: payMode == m, onSelected: (_) => setState(() => payMode = m)),
              ]),
              if (error != null) Banner2(error!, color: brandRedBg, icon: Icons.error_outline_rounded),
              const SizedBox(height: 16),
              FilledButton(onPressed: busy ? null : _save, child: Text(e == null ? 'Add staff' : 'Save')),
            ]);
          },
        ),
      );

  Future<void> _save() async {
    final m = parseRupees(monthly.text);
    if (name.text.trim().length < 2 || dept == null || desig == null || m == null || m <= 0 || joined == null) {
      setState(() => error = 'Fill name, department, designation, basic salary and joining date.');
      return;
    }
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.session.api.post(
          'employees',
          {
            if (e != null) 'id': e!['id'],
            'name': name.text.trim(),
            'code': code.text.trim(),
            'dept_id': dept,
            'desig_id': desig,
            'monthly': rupeeInput(m),
            'joined_on': joined,
            'phone': phone.text.trim(),
            'pay_mode': payMode,
            'client_key': _key,
          },
          idem: _key);
      if (mounted) {
        showMessage(context, e == null ? 'Staff added.' : 'Saved.');
        Navigator.pop(context, true);
      }
    } on ApiError catch (x) {
      if (mounted) setState(() => error = x.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }
}
