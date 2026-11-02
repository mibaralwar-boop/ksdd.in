// Six-field salary wizard (V28 §7 / V29 §10). The server owns every calculation
// (Daily Rate = Monthly ÷ calendar days of the month); the app only collects inputs and shows the server's breakdown.
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class SalaryScreen extends StatelessWidget {
  const SalaryScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        load: () => session.api.get('salary/periods'),
        builder: (context, d, reload) {
          final months = (d['months'] as List? ?? const []);
          final due = d['due']?.toString();
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (due != null)
              SectionCard(title: 'Salary due: ${_monthName(due)}', children: [
                Row(children: [const Text('Status'), const Spacer(), StatusChip(d['due_status']?.toString())]),
                const SizedBox(height: 8),
                FilledButton(
                  onPressed: () => _open(context, due, reload),
                  child: Text(d['due_status'] == 'not_started' ? 'Make Salary Sheet' : 'Continue Pending Salary'),
                ),
              ]),
            SectionCard(title: 'Salary months', children: [
              if (months.isEmpty) const Text('No salary months yet.'),
              for (final m in months)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(_monthName((m as Map)['period'].toString())),
                  subtitle: Text('${m['days']} days · ${m['source'] == 'legacy_register' ? 'imported from old register' : 'Finance 360'}'),
                  trailing: StatusChip(m['status']?.toString()),
                  onTap: () => _open(context, m['period'].toString(), reload),
                ),
            ]),
            OutlinedButton.icon(
              icon: const Icon(Icons.history),
              label: const Text('Old salary sheets (imported history)'),
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SalaryHistoryScreen(api: session.api))),
            ),
          ]);
        },
      );

  void _open(BuildContext context, String period, Future<void> Function() reload) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => SalarySheetScreen(session: session, period: period)));
    await reload();
  }
}

const _months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
String _monthName(String p) {
  final parts = p.split('-');
  if (parts.length != 2) return p;
  final m = int.tryParse(parts[1]) ?? 0;
  return m >= 1 && m <= 12 ? '${_months[m - 1]} ${parts[0]}' : p;
}

/// Pending/draft first-to-last order inside one department, as the website's Save & Next does.
Map<String, dynamic>? nextPendingLine(List<Map<String, dynamic>> lines, int currentEmployee) {
  final cur = lines.indexWhere((l) => l['employee_id'] == currentEmployee);
  if (cur < 0) return null;
  final dept = lines[cur]['dept_id'];
  bool open(Map<String, dynamic> l) => l['dept_id'] == dept && l['employee_id'] != currentEmployee && const ['pending', 'draft'].contains(l['entry_status']);
  for (var i = cur + 1; i < lines.length; i++) {
    if (open(lines[i])) return lines[i];
  }
  for (var i = 0; i < cur; i++) {
    if (open(lines[i])) return lines[i];
  }
  return null;
}

class SalarySheetScreen extends StatefulWidget {
  const SalarySheetScreen({super.key, required this.session, required this.period});
  final Session session;
  final String period;
  @override
  State<SalarySheetScreen> createState() => _SalarySheetScreenState();
}

class _SalarySheetScreenState extends State<SalarySheetScreen> {
  Api get api => widget.session.api;
  Map<String, dynamic>? sheet;
  Object? err;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('salary/sheet', {'period': widget.period});
      setState(() {
        sheet = r;
        err = null;
      });
    } catch (e) {
      setState(() => err = e);
    }
  }

  Future<void> _post(String path, Map<String, dynamic> body, String ok) async {
    setState(() => busy = true);
    try {
      await api.post(path, body);
      if (mounted) showMessage(context, ok);
      await _load();
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  List<Map<String, dynamic>> get lines => [for (final l in (sheet?['lines'] as List? ?? const [])) (l as Map).cast<String, dynamic>()];

  Future<void> _edit(Map<String, dynamic> line) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => SalaryLineScreen(session: widget.session, period: widget.period, employeeId: line['employee_id'] as int, sheetLines: lines)));
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final can = (sheet?['can'] as Map?) ?? const {};
    return Scaffold(
      appBar: AppBar(title: Text('Salary · ${_monthName(widget.period)}')),
      body: err != null
          ? ErrorPanel(error: err!, onRetry: _load)
          : sheet == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(16), children: [
                    if (sheet!['exists'] != true) ...[
                      SectionCard(title: 'Salary sheet not started', children: [
                        Text('${sheet!['days']} calendar days · daily rate = monthly salary ÷ ${sheet!['days']}'),
                        for (final n in (sheet!['not_on_sheet'] as List? ?? const []).take(50))
                          Text('• ${(n as Map)['name']} (${n['dept']})', style: const TextStyle(fontSize: 13)),
                        const SizedBox(height: 8),
                        if (sheet!['can_prepare'] == true)
                          FilledButton(
                              onPressed: busy ? null : () => _post('salary/open', {'period': widget.period}, 'Salary sheet started.'),
                              child: const Text('Start salary sheet')),
                      ]),
                    ] else ...[
                      _summary(),
                      ..._departments(),
                      const SizedBox(height: 8),
                      if (can['prepare'] == true && sheet!['status'] == 'draft')
                        OutlinedButton(
                            onPressed: busy ? null : () => _post('salary/submit', {'period': widget.period}, 'Sent for approval.'),
                            child: const Text('Submit for approval')),
                      if (can['approve'] == true && sheet!['status'] == 'review')
                        FilledButton(
                            onPressed: busy
                                ? null
                                : () async {
                                    if (await confirmDialog(context, 'Approve salary', 'Approve ${_monthName(widget.period)}? Net total ${rupees((sheet!['totals'] as Map?)?['net'])}.',
                                        ok: 'Approve')) {
                                      await _post('salary/approve', {'period': widget.period, 'version': sheet!['version']}, 'Salary approved.');
                                    }
                                  },
                            child: const Text('Approve salary')),
                    ],
                  ]),
                ),
    );
  }

  Widget _summary() {
    final pr = (sheet!['progress'] as Map?) ?? const {};
    final tot = (sheet!['totals'] as Map?) ?? const {};
    final total = (pr['total'] as num?)?.toInt() ?? 0;
    final done = (pr['done'] as num?)?.toInt() ?? 0;
    return SectionCard(children: [
      Row(children: [Text('Sheet status'), const Spacer(), StatusChip(sheet!['status']?.toString())]),
      const SizedBox(height: 8),
      LinearProgressIndicator(value: total == 0 ? 0 : done / total),
      const SizedBox(height: 4),
      Text('$done of $total completed · ${pr['draft'] ?? 0} draft · ${pr['pending'] ?? 0} pending'),
      Text('Divisor: ${sheet!['divisor']} (${sheet!['divisor_mode']}) days'),
      const Divider(),
      SumRow('Net payable', rupees(tot['net']), bold: true),
      SumRow('Carried forward', rupees(tot['carried_forward'])),
      SumRow('Paid', rupees(tot['paid'])),
      SumRow('Balance', rupees(tot['balance'])),
    ]);
  }

  List<Widget> _departments() {
    final depts = [for (final d in (sheet!['departments'] as List? ?? const [])) (d as Map).cast<String, dynamic>()];
    final byDept = (sheet!['entry_by_dept'] as Map?) ?? const {};
    return [
      for (final d in depts)
        Builder(builder: (context) {
          final ls = lines.where((l) => l['dept_id'] == d['id']).toList();
          if (ls.isEmpty) return const SizedBox.shrink();
          final st = (byDept['${d['id']}'] as Map?) ?? const {};
          final desigs = [for (final g in (d['designations'] as List? ?? const [])) (g as Map).cast<String, dynamic>()];
          final groups = <String, List<Map<String, dynamic>>>{};
          for (final l in ls) {
            groups.putIfAbsent((l['designation'] ?? 'Other').toString(), () => []).add(l);
          }
          final order = <String>{for (final g in desigs) g['name'].toString(), ...groups.keys}.where(groups.containsKey);
          return SectionCard(title: '${d['name']} · ${st['completed'] ?? 0}/${st['total'] ?? ls.length} done', children: [
            for (final g in order) ...[
              Padding(padding: const EdgeInsets.only(top: 6), child: Text(g, style: const TextStyle(fontWeight: FontWeight.w600))),
              for (final l in groups[g]!)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${l['name']}'),
                  subtitle: Text('${l['code'] ?? ''} · net ${rupees(l['net_payable'])}${l['saved_at'] != null ? ' · saved ${l['saved_at']}' : ''}'),
                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                    StatusChip(l['entry_status']?.toString()),
                    const SizedBox(width: 6),
                    TextButton(
                      onPressed: () => _edit(l),
                      child: Text(switch (l['entry_status']) { 'pending' => 'Start', 'draft' => 'Resume', _ => 'Review' }),
                    ),
                  ]),
                ),
            ],
          ]);
        }),
    ];
  }
}

class SalaryLineScreen extends StatefulWidget {
  const SalaryLineScreen({super.key, required this.session, required this.period, required this.employeeId, required this.sheetLines});
  final Session session;
  final String period;
  final int employeeId;
  final List<Map<String, dynamic>> sheetLines;
  @override
  State<SalaryLineScreen> createState() => _SalaryLineScreenState();
}

class _SalaryLineScreenState extends State<SalaryLineScreen> {
  Api get api => widget.session.api;
  late int emp = widget.employeeId;
  late List<Map<String, dynamic>> sheetLines = widget.sheetLines;
  Map<String, dynamic>? line;
  Map<String, dynamic>? calc;
  Object? err;
  bool saving = false;
  String? saveError;
  Set<int> penalties = {};
  Timer? _debounce;
  int _previewSeq = 0;

  final present = TextEditingController();
  final absent = TextEditingController();
  final half = TextEditingController();
  final leave = TextEditingController();
  final advance = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  String _n(Object? v) => v == null ? '' : ((v as num) == v.roundToDouble() ? v.toInt().toString() : v.toString());

  Future<void> _load() async {
    setState(() {
      line = null;
      calc = null;
      err = null;
      saveError = null;
    });
    try {
      final r = await api.get('salary/drafts', {'period': widget.period, 'employee_id': '$emp'});
      final ctx = (r['context'] as Map?) ?? const {};
      present.text = _n(r['present']);
      absent.text = r['entry_status'] == 'pending' ? '' : _n(r['absent']);
      half.text = (r['half_days'] as num?) == 0 ? '' : _n(r['half_days']);
      leave.text = (r['paid_leave'] as num?) == 0 ? '' : _n(r['paid_leave']);
      advance.text = r['entry_status'] == 'pending' ? '' : rupeeInput(r['advance_recovery']);
      final ids = (r['penalty_ids'] ?? ctx['penalty_ids']) as List?;
      penalties = ids == null
          ? {for (final p in (ctx['penalty_items'] as List? ?? const [])) ((p as Map)['id'] as num).toInt()}
          : {for (final i in ids) (i as num).toInt()};
      setState(() {
        line = r;
        calc = (r['calc'] as Map?)?.cast<String, dynamic>();
      });
      _schedulePreview();
    } catch (e) {
      setState(() => err = e);
    }
  }

  Map<String, dynamic> _body(String action) => {
        'period': widget.period,
        'employee_id': emp,
        'action': action,
        'version': line!['version'],
        'present': present.text.trim(),
        'absent': absent.text.trim(),
        'half_days': half.text.trim(),
        'paid_leave': leave.text.trim(),
        'rest_days': '',
        'advance_recovery': advance.text.trim(),
        'penalty_ids': penalties.toList()..sort(),
      };

  void _schedulePreview() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 600), _preview);
  }

  /// Server-side preview. Older replies are ignored so a slow response cannot overwrite a newer one.
  Future<void> _preview() async {
    if (line == null || present.text.trim().isEmpty) return;
    final seq = ++_previewSeq;
    try {
      final r = await api.post('salary/drafts', _body('preview'));
      if (seq == _previewSeq && mounted) {
        setState(() {
          calc = (r['calc'] as Map?)?.cast<String, dynamic>();
          saveError = null;
        });
      }
    } on ApiError catch (e) {
      if (seq == _previewSeq && mounted) setState(() => saveError = e.message);
    } catch (_) {}
  }

  Future<bool> _save(String action) async {
    if (action == 'save' && present.text.trim().isEmpty) {
      setState(() => saveError = 'Enter Present days.');
      return false;
    }
    setState(() {
      saving = true;
      saveError = null;
    });
    try {
      final r = await api.post('salary/drafts', _body(action));
      final l = (r['line'] as Map?)?.cast<String, dynamic>();
      if (l != null) {
        line = {...line!, ...l};
        sheetLines = [for (final x in sheetLines) x['employee_id'] == emp ? {...x, ...l} : x];
      }
      if (r['calc'] != null) calc = (r['calc'] as Map).cast<String, dynamic>();
      setState(() {});
      return true;
    } on ApiError catch (e) {
      // Stay on this employee; nothing typed is lost.
      setState(() => saveError = e.isConflict ? '${e.message} Tap Reload.' : e.message);
      return false;
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  Future<void> _saveAndNext() async {
    if (!await _save('save')) return;
    final next = nextPendingLine(sheetLines, emp);
    if (!mounted) return;
    if (next == null) {
      showMessage(context, 'Saved. No more pending employees in this department.');
      Navigator.pop(context);
      return;
    }
    emp = next['employee_id'] as int;
    await _load();
  }

  Future<void> _later() async {
    if (!await _save('later')) return;
    final next = nextPendingLine(sheetLines, emp);
    if (!mounted) return;
    if (next == null) {
      Navigator.pop(context);
      return;
    }
    emp = next['employee_id'] as int;
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = line;
    final ctx = (l?['context'] as Map?) ?? const {};
    final c = calc ?? const {};
    final readOnly = !widget.session.permissions!.can('payroll.prepare') || widget.session.permissions!.readOnly;
    return Scaffold(
      appBar: AppBar(title: Text(l == null ? 'Salary' : '${l['name']}')),
      body: err != null
          ? ErrorPanel(error: err!, onRetry: _load)
          : l == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(padding: const EdgeInsets.all(16), children: [
                  SectionCard(children: [
                    Text('${l['code'] ?? ''} · ${l['designation'] ?? ''}'),
                    SumRow('Monthly basic', rupees(ctx['monthly'])),
                    SumRow('Daily rate (÷ ${ctx['divisor']} calendar days)', rupees(ctx['daily_rate'])),
                    SumRow('Eligible days', '${ctx['eligible_days']}'),
                    SumRow('Advance outstanding', rupees(ctx['advance_outstanding'])),
                    SumRow('Overtime already paid (reference only)', rupees(ctx['ot_paid'])),
                    if (l['joining_unverified'] == true) const Text('Joining date not verified.', style: TextStyle(color: Color(0xFFB26A00))),
                  ]),
                  SectionCard(title: 'Attendance & deductions', children: [
                    _row(NumberField(controller: present, label: '1 · Present days *', onChanged: (_) => _schedulePreview()),
                        NumberField(controller: absent, label: '2 · Absent days (auto if empty)', onChanged: (_) => _schedulePreview())),
                    const SizedBox(height: 12),
                    _row(NumberField(controller: half, label: '3 · Half days', onChanged: (_) => _schedulePreview()),
                        NumberField(controller: leave, label: '4 · Paid leave days', onChanged: (_) => _schedulePreview())),
                    const SizedBox(height: 12),
                    MoneyField(controller: advance, label: '5 · Advance recovery', onChanged: (_) => _schedulePreview()),
                    const SizedBox(height: 8),
                    const Text('6 · Approved penalty deduction', style: TextStyle(fontWeight: FontWeight.w600)),
                    if ((ctx['penalty_items'] as List? ?? const []).isEmpty) const Text('No approved penalties.'),
                    for (final p in (ctx['penalty_items'] as List? ?? const []))
                      CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        value: penalties.contains(((p as Map)['id'] as num).toInt()),
                        onChanged: readOnly
                            ? null
                            : (v) {
                                setState(() => v == true ? penalties.add((p['id'] as num).toInt()) : penalties.remove((p['id'] as num).toInt()));
                                _schedulePreview();
                              },
                        title: Text('${p['date']} · ${rupees(p['amount'])}'),
                        subtitle: Text('${p['reason'] ?? ''}'),
                      ),
                  ]),
                  SectionCard(title: 'Server calculation', children: [
                    if (c.isEmpty) const Text('Enter Present days to see the calculation.'),
                    if (c.isNotEmpty) ...[
                      SumRow('Payable days', '${c['payable_days']} (absent ${c['absent']})'),
                      SumRow('Earned', rupees(c['gross_earned'])),
                      SumRow('− Advance recovery', rupees(c['advance_recovery'])),
                      SumRow('− Penalties', rupees(c['penalties'])),
                      SumRow('Net payable', rupees(c['net_payable']), bold: true),
                      SumRow('Carried forward', rupees(c['carried_forward'])),
                      SumRow('Already paid', rupees(c['paid'])),
                      SumRow('Balance due', rupees(c['balance']), bold: true),
                    ],
                  ]),
                  if (saveError != null) Banner2(saveError!, color: const Color(0xFFFDE2E1), icon: Icons.error_outline),
                  if (l['saved_at'] != null) Text('Last saved ${l['saved_at']}', style: Theme.of(context).textTheme.bodySmall),
                  const SizedBox(height: 8),
                  if (!readOnly) ...[
                    FilledButton(onPressed: saving ? null : _saveAndNext, child: Text(saving ? 'Saving…' : 'Save & Next')),
                    const SizedBox(height: 8),
                    Row(children: [
                      Expanded(
                          child: OutlinedButton(
                              onPressed: saving
                                  ? null
                                  : () async {
                                      if (await _save('draft') && context.mounted) showMessage(context, 'Draft saved on the server.');
                                    },
                              child: const Text('Save draft'))),
                      const SizedBox(width: 8),
                      Expanded(child: OutlinedButton(onPressed: saving ? null : _later, child: const Text('Add later'))),
                    ]),
                    TextButton(onPressed: _load, child: const Text('Reload')),
                  ],
                ]),
    );
  }

  Widget _row(Widget a, Widget b) => Row(children: [Expanded(child: a), const SizedBox(width: 12), Expanded(child: b)]);
}

class SalaryHistoryScreen extends StatelessWidget {
  const SalaryHistoryScreen({super.key, required this.api, this.period});
  final Api api;
  final String? period;
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(period == null ? 'Old salary sheets' : _monthName(period!))),
        body: Loader<Map<String, dynamic>>(
          load: () => api.get('salary/history', {if (period != null) 'period': period!}),
          builder: (context, d, _) {
            final months = d['months'] as List? ?? const [];
            final rows = d['rows'] as List? ?? const [];
            return ListView(padding: const EdgeInsets.all(16), children: [
              Banner2((d['note'] ?? 'Read-only record of imported salary sheets.').toString()),
              if (period == null && months.isEmpty) const Text('No imported salary history yet.'),
              if (period == null)
                for (final m in months)
                  ListTile(
                    title: Text(_monthName((m as Map)['period'].toString())),
                    subtitle: Text('${m['n']} rows · ${m['linked']} linked to staff · net ${rupees(m['net'])} · paid ${rupees(m['paid'])}'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SalaryHistoryScreen(api: api, period: m['period'].toString()))),
                  ),
              if (period != null)
                for (final r in rows)
                  Card(
                    child: ListTile(
                      title: Text('${(r as Map)['name'] ?? ''}${r['hr_code'] != null ? ' · ${r['hr_code']}' : ''}'),
                      subtitle: Text([
                        r['section'],
                        r['designation'],
                        if (r['present'] != null) 'present ${r['present']}',
                        r['employee_id'] == null ? 'Not linked to a staff record (unverified)' : 'Linked: ${r['staff_name']}',
                        r['pay_status'],
                      ].whereType<Object>().join(' · ')),
                      trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text(rupees(r['net_salary']), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text('bal ${rupees(r['balance'])}', style: const TextStyle(fontSize: 12)),
                      ]),
                    ),
                  ),
            ]);
          },
        ),
      );
}
