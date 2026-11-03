// Monthly salary wizard (V38). The server owns every calculation (daily rate = monthly ÷ calendar days of the month,
// half day = 0.5, approved leave, arrears, recoveries); the app collects the inputs, shows the server's breakdown and
// records payments through the server. Nothing here changes a status locally before the server confirms it.
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';
import 'salary_pay.dart';
import 'staff.dart';
import 'staff_extra.dart';

class SalaryScreen extends StatelessWidget {
  const SalaryScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        load: () => session.api.get('salary/periods'),
        builder: (context, d, reload) {
          final months = (d['months'] as List? ?? const []);
          final due = d['due']?.toString();
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            if (due != null)
              SectionCard(title: 'Salary due: ${monthName(due)}', icon: Icons.calendar_month_outlined, children: [
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
                  title: Text(monthName((m as Map)['period'].toString())),
                  subtitle:
                      Text('${m['days'] ?? ''} days · ${m['source'] == 'legacy_register' ? 'imported from old register' : 'Finance 360'}'
                          '${m['payment_status'] != null ? ' · ${m['payment_status']}' : ''}'),
                  trailing: StatusChip(m['status']?.toString()),
                  onTap: () => _open(context, m['period'].toString(), reload),
                ),
            ]),
            OutlinedButton.icon(
              icon: const Icon(Icons.history),
              label: const Text('Old salary sheets (imported history)'),
              onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                      builder: (_) =>
                          Scaffold(appBar: AppBar(title: const Text('Old salary sheets')), body: SalaryHistoryScreen(session: session)))),
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
String monthName(String p) {
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
  bool open(Map<String, dynamic> l) =>
      l['dept_id'] == dept && l['employee_id'] != currentEmployee && const ['pending', 'draft'].contains(l['entry_status']);
  for (var i = cur + 1; i < lines.length; i++) {
    if (open(lines[i])) return lines[i];
  }
  for (var i = 0; i < cur; i++) {
    if (open(lines[i])) return lines[i];
  }
  return null;
}

/// Payroll status of one line, derived ONLY from server fields (entry_status, paid, balance).
/// Part Paid stays "to pay"; Paid = something was paid and nothing is due.
String payStatus(Map<String, dynamic> l) {
  if (l['entry_status'] == 'excluded' || l['state'] == 'excluded') return 'excluded';
  final paid = (l['paid'] as num?)?.toInt() ?? 0;
  final bal = (l['balance'] as num?)?.toInt() ?? 0;
  if (paid > 0 && bal > 0) return 'part_paid';
  if (paid > 0 && bal <= 0) return 'paid';
  return switch (l['entry_status']) { 'completed' => 'ready', 'draft' => 'draft', _ => 'pending' };
}

/// Next employee with salary still due (Part Paid or Ready with a balance), same department first.
Map<String, dynamic>? nextPayableLine(List<Map<String, dynamic>> lines, int currentEmployee) {
  final cur = lines.indexWhere((l) => l['employee_id'] == currentEmployee);
  bool due(Map<String, dynamic> l) =>
      l['employee_id'] != currentEmployee &&
      const ['ready', 'part_paid'].contains(payStatus(l)) &&
      ((l['balance'] as num?)?.toInt() ?? 0) > 0;
  final dept = cur < 0 ? null : lines[cur]['dept_id'];
  final order = [
    if (cur >= 0) ...lines.skip(cur + 1),
    if (cur >= 0) ...lines.take(cur),
    if (cur < 0) ...lines,
  ];
  return order.where((l) => due(l) && l['dept_id'] == dept).firstOrNull ?? order.where(due).firstOrNull;
}

const _tabs = [
  ('pending', 'Pending'),
  ('draft', 'Draft'),
  ('ready', 'Ready'),
  ('part_paid', 'Part paid'),
  ('paid', 'Paid'),
  ('excluded', 'Left / excluded'),
  ('all', 'All'),
];

class SalarySheetScreen extends StatefulWidget {
  const SalarySheetScreen({super.key, required this.session, required this.period});
  final Session session;
  final String period;
  @override
  State<SalarySheetScreen> createState() => _SalarySheetScreenState();
}

class _SalarySheetScreenState extends State<SalarySheetScreen> {
  Api get api => widget.session.api;
  Permissions get perms => widget.session.permissions!;
  Map<String, dynamic>? sheet;
  Object? err;
  bool busy = false;
  String tab = 'pending';
  int? dept;
  bool _tabChosen = false;

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
        if (!_tabChosen) tab = _defaultTab(r);
      });
    } catch (e) {
      setState(() => err = e);
    }
  }

  String _defaultTab(Map<String, dynamic> s) {
    final st = s['status'];
    if (st == 'approved' || st == 'locked') return 'ready';
    return 'pending';
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

  bool get editable => const ['draft', 'review'].contains(sheet?['status']);
  bool get payable => const ['approved', 'locked'].contains(sheet?['status']);

  List<Map<String, dynamic>> get lines => [for (final l in (sheet?['lines'] as List? ?? const [])) (l as Map).cast<String, dynamic>()];

  Future<void> _edit(Map<String, dynamic> line) async {
    await Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) => SalaryLineScreen(
                session: widget.session, period: widget.period, employeeId: line['employee_id'] as int, sheetLines: lines)));
    await _load();
  }

  /// Record a payment; when the employee is fully paid, open the next employee with salary due (after the server
  /// confirmed and the sheet was re-read — never on a local guess).
  Future<void> _pay(Map<String, dynamic> line) async {
    var cur = line;
    while (mounted) {
      final r = await Navigator.push<SalaryPayResult>(
          context, MaterialPageRoute(builder: (_) => SalaryPayScreen(session: widget.session, period: widget.period, line: cur)));
      await _load();
      if (r == null || !r.fullyPaid || !mounted) return;
      final next = nextPayableLine(lines, cur['employee_id'] as int);
      if (next == null) {
        showMessage(context, 'Everyone in this list is paid.');
        return;
      }
      showMessage(context, 'Next: ${next['name']}');
      cur = next;
    }
  }

  Future<void> _addStaff() async {
    final saved = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => StaffForm(session: widget.session)));
    if (saved == true) await _refreshRoster('New staff added to this month (from the joining date).');
  }

  /// Re-reads the roster on the server: new joiners get a line with their eligible days, people who left are excluded.
  Future<void> _refreshRoster(String ok) async {
    if (!editable || !perms.can('payroll.prepare')) {
      await _load();
      return;
    }
    await _post('salary/open', {'period': widget.period}, ok);
  }

  Future<void> _lineMenu(Map<String, dynamic> l, String action) async {
    switch (action) {
      case 'edit':
        await _edit(l);
      case 'pay':
        await _pay(l);
      case 'history':
        await Navigator.push(context,
            MaterialPageRoute(builder: (_) => SalaryPayScreen(session: widget.session, period: widget.period, line: l, historyOnly: true)));
      case 'exclude':
        final why = await askText(context, 'Exclude from ${monthName(widget.period)}?',
            '${l['name']} stays on staff and keeps all history; no salary is prepared for this month only.');
        if (why == null) return;
        await _post(
            'salary/drafts',
            {'period': widget.period, 'employee_id': l['employee_id'], 'action': 'exclude', 'version': l['version'], 'reason': why},
            'Excluded from this month.');
      case 'left':
        final ok = await Navigator.push<bool>(
            context,
            MaterialPageRoute(
                builder: (_) =>
                    MarkLeftScreen(session: widget.session, employee: {'id': l['employee_id'], 'name': l['name'], 'code': l['code']})));
        if (ok == true) await _refreshRoster('Roster refreshed for the exit date.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final can = (sheet?['can'] as Map?) ?? const {};
    return Scaffold(
      appBar: AppBar(title: Text('Salary · ${monthName(widget.period)}')),
      body: err != null
          ? ErrorPanel(error: err!, onRetry: _load)
          : sheet == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
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
                      _controls(),
                      ..._list(),
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
                                    if (await confirmDialog(context, 'Approve salary',
                                        'Approve ${monthName(widget.period)}? Net total ${rupees((sheet!['totals'] as Map?)?['net'])}.',
                                        ok: 'Approve')) {
                                      await _post(
                                          'salary/approve', {'period': widget.period, 'version': sheet!['version']}, 'Salary approved.');
                                    }
                                  },
                            child: const Text('Approve salary')),
                      if (!payable && perms.can('payroll.pay'))
                        const Banner2('Salary can be paid after the sheet is approved.', icon: Icons.info_outline_rounded),
                    ],
                  ]),
                ),
    );
  }

  Map<String, int> _counts(Iterable<Map<String, dynamic>> ls) {
    final c = <String, int>{for (final t in _tabs) t.$1: 0};
    for (final l in ls) {
      c[payStatus(l)] = (c[payStatus(l)] ?? 0) + 1;
      c['all'] = c['all']! + 1;
    }
    return c;
  }

  Widget _summary() {
    final tot = (sheet!['totals'] as Map?) ?? const {};
    final c = _counts(lines);
    final active = lines.where((l) => payStatus(l) != 'excluded').length;
    final entered = c['ready']! + c['part_paid']! + c['paid']!;
    return Column(children: [
      HeroCard(
        title: 'Balance to pay · ${monthName(widget.period)}',
        value: rupees(tot['balance'], showPaise: false),
        icon: Icons.payments_outlined,
        parts: [
          ('Net', rupees(tot['net'], showPaise: false)),
          ('Arrears', rupees(tot['carried_forward'], showPaise: false)),
          ('Paid', rupees(tot['paid'], showPaise: false)),
        ],
      ),
      SectionCard(children: [
        Row(children: [const Expanded(child: Text('Sheet status')), StatusChip(sheet!['status']?.toString())]),
        const SizedBox(height: 8),
        LinearProgressIndicator(value: active == 0 ? 0 : entered / active, color: brandOrange, backgroundColor: brandPeach),
        const SizedBox(height: 6),
        Text('$entered of $active entered · ${c['pending']} pending · ${c['draft']} draft · '
            '${c['part_paid']} part paid · ${c['paid']} paid · ${c['excluded']} left/excluded'),
        Text('${sheet!['days']} calendar days · divisor ${sheet!['divisor']} (${sheet!['divisor_mode']})',
            style: Theme.of(context).textTheme.bodySmall),
      ]),
    ]);
  }

  Widget _controls() {
    final depts = [for (final d in (sheet!['departments'] as List? ?? const [])) (d as Map).cast<String, dynamic>()];
    final inDept = lines.where((l) => dept == null || l['dept_id'] == dept).toList();
    final c = _counts(inDept);
    final firstPending = inDept.where((l) => const ['pending', 'draft'].contains(payStatus(l))).firstOrNull;
    final firstDue =
        inDept.where((l) => const ['ready', 'part_paid'].contains(payStatus(l)) && ((l['balance'] as num?) ?? 0) > 0).firstOrNull;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(children: [
          Padding(
              padding: const EdgeInsets.only(right: 8),
              child:
                  ChoiceChip(label: const Text('All departments'), selected: dept == null, onSelected: (_) => setState(() => dept = null))),
          for (final d in depts)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                  label: Text(d['name'].toString()),
                  selected: dept == d['id'],
                  onSelected: (_) => setState(() => dept = (d['id'] as num).toInt())),
            ),
        ]),
      ),
      const SizedBox(height: 6),
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(children: [
          for (final t in _tabs)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Text('${t.$2} (${c[t.$1]})'),
                selected: tab == t.$1,
                onSelected: (_) => setState(() {
                  tab = t.$1;
                  _tabChosen = true;
                }),
              ),
            ),
        ]),
      ),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (editable && perms.can('payroll.prepare') && firstPending != null && !perms.readOnly)
          FilledButton.icon(
              icon: const Icon(Icons.play_arrow_rounded), label: const Text('Next pending'), onPressed: () => _edit(firstPending)),
        if (payable && perms.can('payroll.pay') && firstDue != null && !perms.readOnly)
          FilledButton.icon(icon: const Icon(Icons.payments_outlined), label: const Text('Pay next'), onPressed: () => _pay(firstDue)),
        if (perms.can('staff.manage') && !perms.readOnly)
          OutlinedButton.icon(icon: const Icon(Icons.person_add_alt_1_rounded), label: const Text('Add staff'), onPressed: _addStaff),
      ]),
    ]);
  }

  List<Widget> _list() {
    final shown = lines.where((l) => (dept == null || l['dept_id'] == dept) && (tab == 'all' || payStatus(l) == tab)).toList();
    if (shown.isEmpty) {
      return [
        EmptyState(
          icon: tab == 'paid' ? Icons.task_alt_rounded : Icons.inbox_outlined,
          title: switch (tab) { 'pending' => 'No pending staff', 'part_paid' => 'No part payments', _ => 'Nobody in this list' },
          subtitle: tab == 'pending' ? 'Everyone in this view has been entered.' : 'Choose another tab or department.',
        )
      ];
    }
    return [
      for (final l in shown)
        Card(
          child: ListTile(
            onTap: () => editable ? _edit(l) : (payable && perms.can('payroll.pay') && ((l['balance'] as num?) ?? 0) > 0 ? _pay(l) : null),
            title: Text('${l['name']}', style: const TextStyle(fontWeight: FontWeight.w800)),
            subtitle: Text([
              '${l['code'] ?? ''} · ${l['designation'] ?? ''}',
              if (payStatus(l) == 'excluded' && l['skip_reason'] != null) l['skip_reason'],
              if (((l['eligible_days'] as num?) ?? 0) < ((sheet!['days'] as num?) ?? 0) && payStatus(l) != 'excluded')
                '${l['eligible_days']} of ${sheet!['days']} days employed',
            ].join(' · ')),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              TrailingStack(children: [
                Text(rupees(payable ? l['balance'] : l['net_payable'], showPaise: false),
                    style: const TextStyle(fontWeight: FontWeight.w900)),
                _pill(payStatus(l)),
              ]),
              PopupMenuButton<String>(
                tooltip: 'Actions',
                onSelected: (a) => _lineMenu(l, a),
                itemBuilder: (_) => [
                  if (editable && perms.can('payroll.prepare') && payStatus(l) != 'excluded')
                    const PopupMenuItem(value: 'edit', child: Text('Enter / review salary')),
                  if (payable && perms.can('payroll.pay') && ((l['balance'] as num?) ?? 0) > 0)
                    const PopupMenuItem(value: 'pay', child: Text('Pay salary')),
                  if (payable) const PopupMenuItem(value: 'history', child: Text('Payments & slip')),
                  if (editable && perms.can('payroll.approve') && payStatus(l) != 'excluded')
                    const PopupMenuItem(value: 'exclude', child: Text('Exclude from this month')),
                  if (perms.can('staff.manage') && payStatus(l) != 'excluded') const PopupMenuItem(value: 'left', child: Text('Mark left')),
                ],
              ),
            ]),
          ),
        ),
    ];
  }

  static Widget _pill(String s) => switch (s) {
        'paid' => const StatusPill('Paid', tone: PillTone.good),
        'part_paid' => const StatusPill('Part paid', tone: PillTone.bad),
        'ready' => const StatusPill('Ready', tone: PillTone.info),
        'draft' => const StatusPill('Draft'),
        'excluded' => const StatusPill('Left / excluded', tone: PillTone.info),
        _ => const StatusPill('Pending'),
      };
}

/// One employee, one month. Present Days is required; Half Days and Paid Leave are optional (0 when empty);
/// absent days, advance recovery and penalties are in "Adjustments & details". Previous balances come first.
class SalaryLineScreen extends StatefulWidget {
  const SalaryLineScreen({super.key, required this.session, required this.period, required this.employeeId, required this.sheetLines});
  final Session session;
  final String period;
  final int employeeId;
  final List<Map<String, dynamic>> sheetLines;
  @override
  State<SalaryLineScreen> createState() => _SalaryLineScreenState();
}

/// Context keys the server must send before a salary can be saved (previous-balance verification).
const balanceKeys = ['carried_forward', 'advance_outstanding', 'penalties', 'paid', 'ot_paid'];

class _SalaryLineScreenState extends State<SalaryLineScreen> {
  Api get api => widget.session.api;
  late int emp = widget.employeeId;
  late List<Map<String, dynamic>> sheetLines = widget.sheetLines;
  Map<String, dynamic>? line;
  Map<String, dynamic>? calc;
  Object? err;
  bool saving = false;
  String? saveError;
  String? inputError;
  Set<int> penalties = {};
  Timer? _debounce;
  int _previewSeq = 0;
  final List<int> _visited = [];

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

  Map get ctx => (line?['context'] as Map?) ?? const {};
  bool get balancesVerified => line != null && balanceKeys.every((k) => ctx[k] is num);
  int get eligible => ((ctx['eligible_days'] ?? line?['eligible_days']) as num?)?.toInt() ?? 0;

  Future<void> _load() async {
    setState(() {
      line = null;
      calc = null;
      err = null;
      saveError = null;
      inputError = null;
    });
    try {
      final r = await api.get('salary/drafts', {'period': widget.period, 'employee_id': '$emp'});
      final c = (r['context'] as Map?) ?? const {};
      final fresh = r['entry_status'] == 'pending';
      present.text = _n(r['present']);
      absent.text = fresh ? '' : _n(r['absent']);
      half.text = (r['half_days'] as num?) == 0 ? '' : _n(r['half_days']);
      leave.text = (r['paid_leave'] as num?) == 0 ? '' : _n(r['paid_leave']);
      advance.text = fresh || (r['advance_recovery'] as num?) == 0 ? '' : rupeeInput(r['advance_recovery']);
      final ids = (r['penalty_ids'] ?? c['penalty_ids']) as List?;
      penalties = ids == null
          ? {for (final p in (c['penalty_items'] as List? ?? const [])) ((p as Map)['id'] as num).toInt()}
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

  /// Client checks only stop obvious mistakes; the server validates again and decides.
  String? _validate({bool forSave = true}) {
    double? num_(TextEditingController c) => c.text.trim().isEmpty ? 0 : double.tryParse(c.text.trim());
    if (forSave && present.text.trim().isEmpty) return 'Present days is required (enter 0 if the person did not work).';
    final p = num_(present), h = num_(half), lv = num_(leave), a = num_(absent);
    if (p == null || h == null || lv == null || a == null) return 'Enter numbers only.';
    if (p < 0 || h < 0 || lv < 0 || a < 0) return 'Days cannot be negative.';
    if (h * 2 != (h * 2).roundToDouble()) return 'Half days must be whole numbers.';
    if (eligible > 0 && p > eligible) return 'Present days ($p) are more than the $eligible days this person was employed this month.';
    if (eligible > 0 && p + h + lv > eligible) {
      return 'Present + half days + leave (${p + h + lv}) are more than the $eligible employed days. Each day can be counted only once.';
    }
    final adv = advance.text.trim().isEmpty ? 0 : parseRupees(advance.text);
    if (adv == null) return 'Advance recovery: enter a valid amount.';
    final out = (ctx['advance_outstanding'] as num?)?.toInt() ?? 0;
    if (adv > out) return 'Advance recovery ${rupees(adv)} is more than the advance outstanding ${rupees(out)}.';
    return null;
  }

  Map<String, dynamic> _body(String action) => {
        'period': widget.period,
        'employee_id': emp,
        'action': action,
        'version': line!['version'],
        'present': present.text.trim(),
        'absent': absent.text.trim(),
        'half_days': half.text.trim().isEmpty ? '0' : half.text.trim(),
        'paid_leave': leave.text.trim().isEmpty ? '0' : leave.text.trim(),
        'rest_days': '',
        // Never recover an advance automatically: empty means 0 this month.
        'advance_recovery': advance.text.trim().isEmpty ? '0' : advance.text.trim(),
        'penalty_ids': penalties.toList()..sort(),
      };

  void _schedulePreview() {
    _debounce?.cancel();
    setState(() => inputError = _validate(forSave: false));
    _debounce = Timer(const Duration(milliseconds: 600), _preview);
  }

  /// Server-side preview. Older replies are ignored so a slow response cannot overwrite a newer one.
  Future<void> _preview() async {
    if (line == null || present.text.trim().isEmpty || _validate() != null) return;
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
    final v = action == 'later' ? null : _validate(forSave: action == 'save');
    if (v != null) {
      setState(() => saveError = v);
      return false;
    }
    if (action == 'save' && !balancesVerified) {
      setState(
          () => saveError = 'Previous balances could not be verified from the server. The salary cannot be completed until they load.');
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
        sheetLines = [
          for (final x in sheetLines) x['employee_id'] == emp ? {...x, ...l} : x
        ];
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

  Future<void> _goTo(Map<String, dynamic>? next, String doneMsg) async {
    if (!mounted) return;
    if (next == null) {
      showMessage(context, doneMsg);
      Navigator.pop(context);
      return;
    }
    _visited.add(emp);
    emp = next['employee_id'] as int;
    await _load();
  }

  Future<void> _saveAndNext() async {
    if (!await _save('save')) return;
    await _goTo(nextPendingLine(sheetLines, emp), 'Saved. No more pending employees in this department.');
  }

  Future<void> _later() async {
    if (!await _save('later')) return;
    await _goTo(nextPendingLine(sheetLines, emp), 'No more pending employees in this department.');
  }

  Future<void> _previous() async {
    if (_visited.isEmpty) return;
    emp = _visited.removeLast();
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = line;
    final c = calc ?? const {};
    final readOnly = !widget.session.permissions!.can('payroll.prepare') || widget.session.permissions!.readOnly;
    final deptLines = l == null ? const <Map<String, dynamic>>[] : sheetLines.where((x) => x['dept_id'] == l['dept_id']).toList();
    final pos = deptLines.indexWhere((x) => x['employee_id'] == emp) + 1;
    return Scaffold(
      appBar: AppBar(title: Text(l == null ? 'Salary' : '${l['name']}')),
      body: err != null
          ? ErrorPanel(error: err!, onRetry: _load)
          : l == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
                  SectionCard(children: [
                    Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      Text('${l['code'] ?? ''} · ${l['designation'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700)),
                      if (pos > 0) StatusPill('Employee $pos of ${deptLines.length}', tone: PillTone.info),
                    ]),
                    const SizedBox(height: 6),
                    SumRow('Salary month', monthName(widget.period)),
                    SumRow('Monthly basic', rupees(ctx['monthly'])),
                    SumRow('Calendar days in month', '${ctx['days'] ?? l['days'] ?? ''}'),
                    SumRow('Daily rate (÷ ${ctx['divisor']} calendar days)', rupees(ctx['daily_rate'])),
                    SumRow('Days employed this month', '$eligible'),
                    if (eligible > 0 && eligible < ((ctx['days'] as num?) ?? eligible))
                      const Text('Joined or left during this month: only the employed days are paid.', style: TextStyle(color: brandBurnt)),
                    if (ctx['rest_paid'] == true)
                      Text('Weekly rest days are paid by this restaurant’s policy.', style: Theme.of(context).textTheme.bodySmall),
                    if (l['joining_unverified'] == true) const Text('Joining date not verified.', style: TextStyle(color: brandBurnt)),
                  ]),
                  _previousBalances(),
                  SectionCard(title: 'Attendance', icon: Icons.event_available_outlined, children: [
                    NumberField(controller: present, label: 'Present days *', onChanged: (_) => _schedulePreview()),
                    const SizedBox(height: 12),
                    Row(children: [
                      Expanded(child: NumberField(controller: half, label: 'Half days', onChanged: (_) => _schedulePreview())),
                      const SizedBox(width: 12),
                      Expanded(child: NumberField(controller: leave, label: 'Paid / approved leave', onChanged: (_) => _schedulePreview())),
                    ]),
                    const SizedBox(height: 6),
                    Text(
                        'Half days and leave are 0 when empty. A half day counts as 0.5 paid day. Absent days are worked out by the server.',
                        style: Theme.of(context).textTheme.bodySmall),
                    if (inputError != null) Banner2(inputError!, color: brandRedBg, icon: Icons.error_outline_rounded),
                  ]),
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: SoftCard(
                      padding: EdgeInsets.zero,
                      child: ExpansionTile(
                        shape: const Border(),
                        initiallyExpanded: advance.text.isNotEmpty || absent.text.isNotEmpty,
                        leading: const Icon(Icons.tune_rounded, color: brandOrange),
                        title: const Text('Adjustments & details', style: TextStyle(fontWeight: FontWeight.w800)),
                        subtitle: Text(
                            'Absent override, advance recovery, approved penalties (${(ctx['penalty_items'] as List? ?? const []).length})'),
                        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                        children: [
                          NumberField(controller: absent, label: 'Absent days (auto if empty)', onChanged: (_) => _schedulePreview()),
                          const SizedBox(height: 12),
                          MoneyField(controller: advance, label: 'Advance recovery this month', onChanged: (_) => _schedulePreview()),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: Text(
                                'Outstanding ${rupees(ctx['advance_outstanding'])}'
                                '${((ctx['installment'] as num?) ?? 0) > 0 ? ' · agreed instalment ${rupees(ctx['installment'])}' : ''}'
                                ' · nothing is recovered unless you enter an amount.',
                                style: Theme.of(context).textTheme.bodySmall),
                          ),
                          if (((ctx['installment'] as num?) ?? 0) > 0 && !readOnly)
                            Align(
                              alignment: Alignment.centerLeft,
                              child: TextButton(
                                onPressed: () {
                                  final out = (ctx['advance_outstanding'] as num).toInt();
                                  final inst = (ctx['installment'] as num).toInt();
                                  advance.text = rupeeInput(inst < out ? inst : out);
                                  _schedulePreview();
                                },
                                child: const Text('Use agreed instalment'),
                              ),
                            ),
                          const SizedBox(height: 4),
                          const Align(
                              alignment: Alignment.centerLeft,
                              child: Text('Approved penalties to deduct', style: TextStyle(fontWeight: FontWeight.w700))),
                          if ((ctx['penalty_items'] as List? ?? const []).isEmpty)
                            const Align(alignment: Alignment.centerLeft, child: Text('No approved penalties.')),
                          for (final p in (ctx['penalty_items'] as List? ?? const []))
                            CheckboxListTile(
                              contentPadding: EdgeInsets.zero,
                              value: penalties.contains(((p as Map)['id'] as num).toInt()),
                              onChanged: readOnly
                                  ? null
                                  : (v) {
                                      setState(() =>
                                          v == true ? penalties.add((p['id'] as num).toInt()) : penalties.remove((p['id'] as num).toInt()));
                                      _schedulePreview();
                                    },
                              title: Text('${p['date']} · ${rupees(p['amount'])}'),
                              subtitle: Text('${p['reason'] ?? ''}'),
                            ),
                          if (((ctx['penalty_pending_approval'] as num?) ?? 0) > 0)
                            Text('${rupees(ctx['penalty_pending_approval'])} of penalties wait for approval and cannot be deducted yet.',
                                style: Theme.of(context).textTheme.bodySmall),
                        ],
                      ),
                    ),
                  ),
                  SectionCard(title: 'Server calculation', icon: Icons.calculate_outlined, children: [
                    if (c.isEmpty) const Text('Enter Present days to see the calculation.'),
                    if (c.isNotEmpty) ...[
                      SumRow('Payable days', '${c['payable_days']} (absent ${c['absent'] ?? c['unpaid_leave'] ?? 0})'),
                      SumRow('Earned this month', rupees(c['gross_earned'])),
                      SumRow('− Advance recovery', rupees(c['advance_recovery'])),
                      SumRow('− Approved penalties', rupees(c['penalties'])),
                      SumRow('= Net salary this month', rupees(c['net_payable']), bold: true),
                      SumRow('+ Earlier salary still owed (arrears)', rupees(c['carried_forward'] ?? ctx['carried_forward'])),
                      SumRow('− Already paid for this month', rupees(c['paid'] ?? ctx['paid'])),
                      SumRow('Remaining due to employee', rupees(c['balance']), bold: true),
                      if (c['advance_outstanding_after'] != null)
                        SumRow('Advance still owed after this salary', rupees(c['advance_outstanding_after'])),
                      if (((c['ot_paid_info'] as num?) ?? 0) > 0)
                        Text('Overtime already paid (${rupees(c['ot_paid_info'])}) is shown for information only and is not added again.',
                            style: Theme.of(context).textTheme.bodySmall),
                    ],
                  ]),
                  if (saveError != null) Banner2(saveError!, color: brandRedBg, icon: Icons.error_outline),
                  if (l['saved_at'] != null) Text('Last saved ${l['saved_at']}', style: Theme.of(context).textTheme.bodySmall),
                  const SizedBox(height: 8),
                  if (!readOnly) ...[
                    FilledButton(
                        onPressed: saving || !balancesVerified ? null : _saveAndNext, child: Text(saving ? 'Saving…' : 'Save & Next')),
                    const SizedBox(height: 8),
                    Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
                      OutlinedButton(onPressed: saving || _visited.isEmpty ? null : _previous, child: const Text('Previous')),
                      OutlinedButton(
                          onPressed: saving
                              ? null
                              : () async {
                                  if (await _save('draft') && context.mounted) showMessage(context, 'Draft saved on the server.');
                                },
                          child: const Text('Save draft')),
                      OutlinedButton(onPressed: saving ? null : _later, child: const Text('Add later')),
                    ]),
                    TextButton(onPressed: _load, child: const Text('Reload')),
                  ],
                ]),
    );
  }

  /// V38 §2.3: what the employee is OWED and what the employee OWES are separate lines, never netted.
  Widget _previousBalances() {
    if (!balancesVerified) {
      return const SectionCard(title: 'Previous balances', icon: Icons.account_balance_wallet_outlined, children: [
        Banner2(
            'Balance verification unavailable: the server did not send the previous salary / advance balances. '
            'The salary cannot be completed or paid until they load. Tap Reload.',
            color: brandRedBg,
            icon: Icons.gpp_maybe_outlined),
      ]);
    }
    final cf = (ctx['carried_forward'] as num).toInt();
    final adv = (ctx['advance_outstanding'] as num).toInt();
    final pen = (ctx['penalties'] as num).toInt();
    final items = [for (final i in (ctx['items'] as List? ?? const [])) (i as Map).cast<String, dynamic>()];
    return SectionCard(title: 'Previous balances (before this salary)', icon: Icons.account_balance_wallet_outlined, children: [
      const Text('Employee is owed', style: TextStyle(fontWeight: FontWeight.w800, color: brandGreen)),
      SumRow('Unpaid salary from earlier months${ctx['cf_source'] != null ? ' (${ctx['cf_source']})' : ''}', rupees(cf > 0 ? cf : 0),
          color: cf > 0 ? brandGreen : null),
      const SizedBox(height: 6),
      const Text('Employee owes', style: TextStyle(fontWeight: FontWeight.w800, color: brandRed)),
      SumRow('Advance / loan outstanding', rupees(adv), color: adv > 0 ? brandRed : null),
      if (((ctx['advance_given'] as num?) ?? 0) > 0) SumRow('  of which given this month', rupees(ctx['advance_given'])),
      if (((ctx['advance_recovered'] as num?) ?? 0) > 0) SumRow('  already recovered', rupees(ctx['advance_recovered'])),
      SumRow('Approved penalties not yet deducted', rupees(pen), color: pen > 0 ? brandRed : null),
      if (cf < 0) SumRow('Paid too much earlier (to adjust)', rupees(-cf), color: brandRed),
      const Divider(),
      SumRow('Already paid for this month', rupees(ctx['paid'])),
      SumRow('Overtime paid immediately (not added again)', rupees(ctx['ot_paid'])),
      if (((ctx['ot_unpaid'] as num?) ?? 0) > 0) SumRow('Overtime recorded, not yet paid', rupees(ctx['ot_unpaid'])),
      if (items.isNotEmpty) ...[
        const SizedBox(height: 6),
        Text('This month’s staff entries', style: Theme.of(context).textTheme.bodySmall),
        for (final i in items) SumRow('${i['date']} · ${i['kind_label'] ?? i['kind']} (${i['status']})', rupees(i['amount'])),
      ],
      const SizedBox(height: 4),
      Text('These are shown separately on purpose: an advance owed by the employee is never mixed with salary owed to the employee.',
          style: Theme.of(context).textTheme.bodySmall),
    ]);
  }
}
