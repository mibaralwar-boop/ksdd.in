import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Staff advances, penalties and paid overtime (`GET staff-entries`, `POST advances|penalties|overtime`).
/// Paid overtime is settled immediately and is information-only in monthly salary (never added twice).
class StaffPaymentsScreen extends StatefulWidget {
  const StaffPaymentsScreen({super.key, required this.session});
  final Session session;
  @override
  State<StaffPaymentsScreen> createState() => _StaffPaymentsScreenState();
}

class _StaffPaymentsScreenState extends State<StaffPaymentsScreen> {
  String kind = '';

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    return Loader<Map<String, dynamic>>(
      key: ValueKey(kind),
      load: () => widget.session.api.get('staff-entries', {if (kind.isNotEmpty) 'kind': kind}),
      builder: (context, d, reload) {
        final rows = [for (final x in (d['rows'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          if (p.can('stafftxn.create') && !p.readOnly)
            FilledButton.icon(
              icon: const Icon(Icons.add_rounded),
              label: const Text('Add advance / penalty / overtime'),
              onPressed: () async {
                final ok = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => StaffTxnForm(session: widget.session)));
                if (ok == true) await reload();
              },
            ),
          const SizedBox(height: 10),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(children: [
              for (final k in const [
                ('', 'All'),
                ('advance', 'Advances'),
                ('penalty', 'Penalties'),
                ('overtime', 'Overtime'),
                ('extra_now', 'Extra paid')
              ])
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(label: Text(k.$2), selected: kind == k.$1, onSelected: (_) => setState(() => kind = k.$1)),
                ),
            ]),
          ),
          if (rows.isEmpty)
            const EmptyState(
              icon: Icons.currency_rupee_rounded,
              title: 'No staff entries',
              subtitle: 'Advances, approved penalties and paid overtime appear here with their status.',
            ),
          for (final r in rows)
            Card(
              child: ListTile(
                title: Text('${r['emp_name']} · ${r['kind_label']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text([r['date'], r['mode'], r['reason']].where((x) => x != null && x != '').join(' · '),
                    maxLines: 2, overflow: TextOverflow.ellipsis),
                trailing: TrailingStack(children: [
                  Text(rupees(r['amount']), style: const TextStyle(fontWeight: FontWeight.w900)),
                  StatusChip(r['status'] as String?),
                ]),
              ),
            ),
        ]);
      },
    );
  }
}

class StaffTxnForm extends StatefulWidget {
  const StaffTxnForm({super.key, required this.session});
  final Session session;
  @override
  State<StaffTxnForm> createState() => _StaffTxnFormState();
}

class _StaffTxnFormState extends State<StaffTxnForm> {
  String kind = 'advance';
  int? employee;
  final amount = TextEditingController();
  final reason = TextEditingController();
  String mode = 'Cash';
  bool otPaid = true;
  bool busy = false;
  final String _key = randomKey();

  bool get paidNow => kind == 'advance' || kind == 'extra_now' || (kind == 'overtime' && otPaid);

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    return Scaffold(
      appBar: AppBar(title: const Text('Staff entry')),
      body: Loader<Map<String, dynamic>>(
        load: () => widget.session.api.get('employees'),
        builder: (context, d, _) {
          final staff = [
            for (final x in (d['staff'] as List? ?? const []))
              if ((x as Map)['status'] == 'active') x.cast<String, dynamic>()
          ];
          final emp = staff.where((e) => e['id'] == employee).firstOrNull;
          return ListView(padding: const EdgeInsets.all(16), children: [
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final k in const [
                ('advance', 'Advance'),
                ('penalty', 'Penalty'),
                ('overtime', 'Overtime'),
                ('extra_now', 'Extra paid now')
              ])
                ChoiceChip(label: Text(k.$2), selected: kind == k.$1, onSelected: (_) => setState(() => kind = k.$1)),
            ]),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              initialValue: employee,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Staff member'),
              items: [
                for (final e in staff)
                  DropdownMenuItem(value: (e['id'] as num).toInt(), child: Text('${e['name']} · ${e['code'] ?? ''} · ${e['dept'] ?? ''}')),
              ],
              onChanged: (v) => setState(() => employee = v),
            ),
            if (emp != null && ((emp['advance_outstanding'] as num?) ?? 0) != 0)
              Banner2('Advance outstanding: ${rupees(emp['advance_outstanding'])}'),
            const SizedBox(height: 12),
            MoneyField(controller: amount, label: 'Amount', big: true),
            const SizedBox(height: 12),
            TextField(controller: reason, decoration: const InputDecoration(labelText: 'Reason *')),
            if (kind == 'overtime')
              SwitchListTile(
                value: otPaid,
                onChanged: (v) => setState(() => otPaid = v),
                title: const Text('Paid now'),
                subtitle: const Text('Paid overtime is never added to the monthly salary again.'),
              ),
            if (paidNow) ...[
              const SizedBox(height: 12),
              Wrap(spacing: 8, children: [
                for (final m in const ['Cash', 'UPI', 'Bank'])
                  if (p.can('pay.${m.toLowerCase()}'))
                    ChoiceChip(label: Text(m), selected: mode == m, onSelected: (_) => setState(() => mode = m)),
              ]),
            ],
            if (kind == 'penalty') const Banner2('A penalty is deducted in salary only after it is approved.'),
            const SizedBox(height: 16),
            FilledButton(onPressed: busy ? null : _save, child: const Text('Save')),
          ]);
        },
      ),
    );
  }

  Future<void> _save() async {
    final paise = parseRupees(amount.text);
    if (employee == null || paise == null || paise <= 0 || reason.text.trim().length < 3) {
      showMessage(context, 'Choose the staff member, enter the amount and a reason.', error: true);
      return;
    }
    setState(() => busy = true);
    final path = switch (kind) { 'advance' => 'advances', 'penalty' => 'penalties', 'overtime' => 'overtime', _ => 'f360/staff-txn' };
    try {
      final r = await widget.session.api.post(
          path,
          {
            'kind': kind,
            'employee_id': employee,
            'amount': rupeeInput(paise),
            'reason': reason.text.trim(),
            'date': widget.session.permissions!.businessDate,
            if (paidNow) 'mode': mode,
            if (kind == 'overtime') 'paid': otPaid ? '1' : '0',
            'client_key': _key,
          },
          idem: _key);
      if (mounted) {
        showMessage(context, 'Saved${r['status'] != null ? ' · ${r['status']}' : ''}.');
        Navigator.pop(context, true);
      }
    } on ApiError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }
}

/// Imported old salary months (`GET salary/history`) — read-only, never used for new salary or payments.
class SalaryHistoryScreen extends StatefulWidget {
  const SalaryHistoryScreen({super.key, required this.session});
  final Session session;
  @override
  State<SalaryHistoryScreen> createState() => _SalaryHistoryScreenState();
}

class _SalaryHistoryScreenState extends State<SalaryHistoryScreen> {
  String? period;
  String q = '';

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        key: ValueKey(period),
        load: () => widget.session.api.get('salary/history', {if (period != null) 'period': period!}),
        builder: (context, d, _) {
          final months = [for (final x in (d['months'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          final rows = [
            for (final x in (d['rows'] as List? ?? const []))
              if ('${(x as Map)['name']} ${x['hr_code'] ?? ''}'.toLowerCase().contains(q.toLowerCase())) x.cast<String, dynamic>()
          ];
          final cur = months.where((m) => m['period'] == d['period']).firstOrNull;
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            Banner2((d['note'] ?? '').toString(), icon: Icons.history_rounded),
            if (months.isEmpty)
              const EmptyState(
                icon: Icons.history_rounded,
                title: 'No imported salary months',
                subtitle: 'Old salary sheets appear here after the office imports them on the website.',
              )
            else ...[
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(children: [
                  for (final m in months)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        label: Text(m['period'].toString()),
                        selected: d['period'] == m['period'],
                        onSelected: (_) => setState(() => period = m['period'].toString()),
                      ),
                    ),
                ]),
              ),
              if (cur != null)
                HeroCard(
                  title: 'Net salary · ${cur['period']} (${cur['n']} rows)',
                  value: rupees(cur['net'], showPaise: false),
                  icon: Icons.calendar_month_outlined,
                  parts: [('Paid', rupees(cur['paid'], showPaise: false)), ('Balance', rupees(cur['balance'], showPaise: false))],
                ),
              SearchBox(hint: 'Search name or HR code', onChanged: (v) => setState(() => q = v)),
              for (final r in rows)
                Card(
                  child: ListTile(
                    title: Text('${r['name']}${r['hr_code'] != null ? ' · ${r['hr_code']}' : ''}',
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text([
                      r['section'],
                      r['designation'],
                      if (r['present'] != null) 'present ${r['present']}',
                      r['employee_id'] == null ? 'not linked to a staff record (unverified)' : 'linked: ${r['staff_name']}',
                      r['pay_status'],
                    ].where((x) => x != null && x != '').join(' · ')),
                    trailing: TrailingStack(children: [
                      Text(rupees(r['net_salary'], showPaise: false), style: const TextStyle(fontWeight: FontWeight.w900)),
                      Text('Paid ${rupees(r['paid'], showPaise: false)}', style: Theme.of(context).textTheme.bodySmall),
                    ]),
                  ),
                ),
            ],
          ]);
        },
      );
}
