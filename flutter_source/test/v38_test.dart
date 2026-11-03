// V38: salary wizard rules, previous balances, salary payment, staff add/left and the force-update client.
// Fixtures `*_v38.json` are real responses of the V34 PHP backend (demo data: advance ₹1,500, approved penalty ₹200,
// arrears ₹3,000 carried from the imported September register).
import 'dart:convert';

import 'package:finance360/core/api.dart';
import 'package:finance360/core/format.dart';
import 'package:finance360/core/session.dart';
import 'package:finance360/core/update_policy.dart';
import 'package:finance360/main.dart';
import 'package:finance360/screens/salary.dart';
import 'package:finance360/screens/salary_pay.dart';
import 'package:finance360/screens/staff.dart';
import 'package:finance360/screens/update.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers.dart';

Future<void> pumpScreen(WidgetTester t, Widget w) async {
  t.view.physicalSize = const Size(412, 915) * 2.625;
  t.view.devicePixelRatio = 2.625;
  addTearDown(t.view.reset);
  await t.pumpWidget(MaterialApp(home: w));
  await t.pumpAndSettle();
}

Future<void> tapV(WidgetTester t, Finder f) async {
  await t.scrollUntilVisible(f, 200, scrollable: find.byType(Scrollable).first);
  await t.pumpAndSettle();
  await t.tap(f);
  await t.pumpAndSettle();
}

Map<String, dynamic> approvedLine(int emp, String name, {int net = 2000000, int cf = 0, int paid = 0, int dept = 1}) => {
      'employee_id': emp,
      'name': name,
      'code': 'KS00$emp',
      'dept_id': dept,
      'designation': 'Manager',
      'entry_status': 'completed',
      'state': 'done',
      'version': 3,
      'net_payable': net,
      'carried_forward': cf,
      'paid': paid,
      'balance': net + cf - paid,
      'eligible_days': 31,
    };

void main() {
  group('salary status and progression (pure)', () {
    test('Part paid stays to-pay; Paid only when something was paid and nothing is due', () {
      expect(payStatus(approvedLine(1, 'A')), 'ready');
      expect(payStatus(approvedLine(1, 'A', paid: 500000)), 'part_paid');
      expect(payStatus(approvedLine(1, 'A', paid: 2000000)), 'paid');
      expect(payStatus({'entry_status': 'excluded', 'paid': 0, 'balance': 0}), 'excluded');
      expect(payStatus({'entry_status': 'draft', 'paid': 0, 'balance': 0}), 'draft');
    });
    test('next payable skips fully paid people, prefers the same department', () {
      final ls = [
        approvedLine(1, 'A', paid: 2000000),
        approvedLine(2, 'B', paid: 2000000),
        approvedLine(3, 'C', dept: 2),
        approvedLine(4, 'D', paid: 100),
      ];
      expect(nextPayableLine(ls, 1)!['name'], 'D');
      expect(nextPayableLine(ls, 4)!['name'], 'C');
    });
    test('payment validation: method AND amount mandatory, >0, ≤ due', () {
      expect(validateSalaryPayment(method: null, amount: '100', due: 50000), contains('Cash or Online'));
      expect(validateSalaryPayment(method: 'Online', amount: '100', due: 50000), contains('UPI or Bank'));
      expect(validateSalaryPayment(method: 'Cash', amount: '', due: 50000), contains('Enter the amount'));
      expect(validateSalaryPayment(method: 'Cash', amount: '0', due: 50000), contains('more than ₹0'));
      expect(validateSalaryPayment(method: 'Cash', amount: '600', due: 50000), contains('more than the remaining due'));
      expect(validateSalaryPayment(method: 'Online', online: 'UPI', amount: '250.50', due: 50000), isNull);
    });
  });

  group('salary wizard (server fixtures)', () {
    late Session s;
    late FakeServer srv;
    final sheet = fixture('sheet_v38');
    final lines = [for (final l in sheet['lines'] as List) (l as Map).cast<String, dynamic>()];
    final line = fixture('line_v38');

    Future<void> setup(WidgetTester t, {Map<String, dynamic>? lineOverride}) async {
      await t.runAsync(() async {
        (s, srv) = await signedInSession('select_owner', routes: (x) {
          x.on('GET salary/drafts', (req, b) {
            final e = int.parse(req.url.queryParameters['employee_id']!);
            final l = lines.firstWhere((x) => x['employee_id'] == e);
            return (200, {...line, ...l, 'context': line['context'], ...?lineOverride});
          });
          x.on(
              'POST salary/drafts',
              (req, b) => (
                    200,
                    {
                      'ok': true,
                      if (b['action'] != 'preview') 'line': {'employee_id': b['employee_id'], 'entry_status': 'completed', 'version': 2},
                      'calc': {
                        'payable_days': 28,
                        'absent': 3,
                        'gross_earned': 2258065,
                        'advance_recovery': 0,
                        'penalties': 20000,
                        'net_payable': 2238065,
                        'carried_forward': 300000,
                        'paid': 0,
                        'balance': 2538065
                      }
                    }
                  ));
        });
      });
    }

    testWidgets('previous balances: owed TO employee and owed BY employee are separate lines, not netted', (t) async {
      await setup(t);
      await pumpScreen(t, SalaryLineScreen(session: s, period: '2026-10', employeeId: 1, sheetLines: lines));
      expect(find.text('Employee is owed'), findsOneWidget);
      expect(find.text('Employee owes'), findsOneWidget);
      expect(find.textContaining('Unpaid salary from earlier months'), findsOneWidget);
      expect(find.text('₹3,000'), findsWidgets); // arrears 300000 paise, owed to the employee
      expect(find.text('₹1,500'), findsWidgets); // advance 150000 paise, owed by the employee
      expect(find.text('₹200'), findsWidgets); // approved penalty not yet deducted
      expect(find.text('Employee 1 of 2'), findsOneWidget);
      s.dispose();
    });

    testWidgets('Present Days is mandatory; Half Days / Leave default 0; advance is never recovered automatically', (t) async {
      await setup(t);
      await pumpScreen(t, SalaryLineScreen(session: s, period: '2026-10', employeeId: 1, sheetLines: lines));
      await t.scrollUntilVisible(find.widgetWithText(TextFormField, 'Paid / approved leave'), 300,
          scrollable: find.byType(Scrollable).first);
      for (final label in ['Present days *', 'Half days', 'Paid / approved leave']) {
        expect(find.widgetWithText(TextFormField, label), findsOneWidget, reason: label);
      }
      // Advanced fields are folded away, not six compulsory boxes.
      expect(find.widgetWithText(TextFormField, 'Absent days (auto if empty)'), findsNothing);
      await t.scrollUntilVisible(find.text('Save & Next'), 300, scrollable: find.byType(Scrollable).first);
      await t.tap(find.text('Save & Next'));
      await t.pumpAndSettle();
      expect(find.textContaining('Present days is required'), findsWidgets);
      expect(srv.calls('POST salary/drafts').where((r) => r.body.contains('"action":"save"')), isEmpty);
      await t.scrollUntilVisible(find.widgetWithText(TextFormField, 'Present days *'), -300, scrollable: find.byType(Scrollable).first);
      await t.enterText(find.widgetWithText(TextFormField, 'Present days *'), '28');
      await t.pump(const Duration(milliseconds: 700));
      await t.pumpAndSettle();
      await t.scrollUntilVisible(find.text('Save & Next'), 300, scrollable: find.byType(Scrollable).first);
      await t.tap(find.text('Save & Next'));
      await t.pumpAndSettle();
      final save = jsonDecode(srv.calls('POST salary/drafts').map((r) => r.body).where((b) => b.contains('"action":"save"')).first) as Map;
      expect(save['present'], '28');
      expect(save['half_days'], '0');
      expect(save['paid_leave'], '0');
      expect(save['advance_recovery'], '0', reason: 'an outstanding advance must not be recovered unless an amount is entered');
      expect(find.text('DEMO Sita D'), findsOneWidget, reason: 'Save & Next opens the next pending employee of the department');
      s.dispose();
    });

    testWidgets('attendance over the employed days is refused before any save', (t) async {
      await setup(t);
      await pumpScreen(t, SalaryLineScreen(session: s, period: '2026-10', employeeId: 1, sheetLines: lines));
      await t.scrollUntilVisible(find.widgetWithText(TextFormField, 'Paid / approved leave'), 300,
          scrollable: find.byType(Scrollable).first);
      await t.enterText(find.widgetWithText(TextFormField, 'Present days *'), '25');
      await t.enterText(find.widgetWithText(TextFormField, 'Half days'), '4');
      await t.enterText(find.widgetWithText(TextFormField, 'Paid / approved leave'), '3');
      await t.pump(const Duration(milliseconds: 700));
      await t.pumpAndSettle();
      expect(find.textContaining('more than the 31 employed days'), findsOneWidget);
      expect(srv.calls('POST salary/drafts').where((r) => r.body.contains('"action":"preview"')), isEmpty);
      s.dispose();
    });

    testWidgets('balance verification unavailable → Save & Next disabled (no fabricated zero balances)', (t) async {
      await setup(t, lineOverride: {
        'context': {'monthly': 2500000, 'divisor': 31, 'eligible_days': 31, 'daily_rate': 80645}
      });
      await pumpScreen(t, SalaryLineScreen(session: s, period: '2026-10', employeeId: 1, sheetLines: lines));
      expect(find.textContaining('Balance verification unavailable'), findsOneWidget);
      await t.scrollUntilVisible(find.text('Save & Next'), 300, scrollable: find.byType(Scrollable).first);
      expect(t.widget<FilledButton>(find.widgetWithText(FilledButton, 'Save & Next')).onPressed, isNull);
      s.dispose();
    });
  });

  group('salary payment', () {
    testWidgets('Cash: method + amount required, confirmation, server receipt; a lost reply is retried with the SAME key', (t) async {
      late Session s;
      late FakeServer srv;
      var calls = 0;
      final l = approvedLine(1, 'DEMO Ramesh K', net: 2238065, cf: 300000);
      await t.runAsync(() async {
        (s, srv) = await signedInSession('select_owner', routes: (x) {
          x.json('GET salary/payslip', {
            'line': l,
            'employee': {'code': 'KS001'},
            'payments': []
          });
          x.on('POST salary/payments', (req, b) {
            calls++;
            if (calls == 1) throw Exception('connection reset');
            return (200, {'ok': true, 'id': 7, 'pay_no': 'SP00007', 'balance': 1538065});
          });
        });
      });
      await pumpScreen(t, SalaryPayScreen(session: s, period: '2026-10', line: l));
      expect(find.text('₹25,380.65'), findsWidgets); // remaining due = net + arrears
      await t.scrollUntilVisible(find.text('Confirm & record payment'), 300, scrollable: find.byType(Scrollable).first);
      await tapV(t, find.text('Confirm & record payment'));
      expect(find.textContaining('Cash or Online'), findsOneWidget);
      await tapV(t, find.text('Cash'));
      await tapV(t, find.text('Confirm & record payment'));
      expect(find.textContaining('Enter the amount'), findsOneWidget);
      await t.enterText(find.widgetWithText(TextFormField, 'Amount paid *'), '10000');
      await tapV(t, find.text('Confirm & record payment'));
      await t.tap(find.text('Confirm & record'));
      await t.pumpAndSettle();
      expect(find.textContaining('will not be recorded twice'), findsOneWidget);
      await tapV(t, find.text('Confirm & record payment'));
      await t.pumpAndSettle();
      await t.tap(find.text('Confirm & record'));
      await t.pumpAndSettle();
      expect(find.text('Part payment recorded'), findsOneWidget);
      expect(find.text('SP00007'), findsOneWidget);
      final reqs = srv.calls('POST salary/payments');
      expect(reqs.length, 2);
      expect(reqs.map((r) => r.headers['Idempotency-Key']).toSet().length, 1, reason: 'retry must reuse the idempotency key');
      final b = jsonDecode(reqs.last.body) as Map;
      expect(b['mode'], 'Cash');
      expect(b['amount'], '10000');
      s.dispose();
    });

    testWidgets('sheet: full payment moves the person to Paid and opens the NEXT employee with salary due', (t) async {
      late Session s;
      final paid = <int, int>{};
      final base = [approvedLine(1, 'DEMO Ramesh K'), approvedLine(2, 'DEMO Sita D')];
      List<Map<String, dynamic>> cur() => [
            for (final l in base)
              {...l, 'paid': paid[l['employee_id']] ?? 0, 'balance': (l['net_payable'] as int) - (paid[l['employee_id']] ?? 0)}
          ];
      await t.runAsync(() async {
        (s, _) = await signedInSession('select_owner', routes: (x) {
          x.on(
              'GET salary/sheet',
              (req, b) => (
                    200,
                    {
                      'period': '2026-10',
                      'exists': true,
                      'status': 'approved',
                      'days': 31,
                      'divisor': 31,
                      'divisor_mode': 'calendar',
                      'version': 4,
                      'lines': cur(),
                      'departments': [
                        {'id': 1, 'name': 'Management', 'designations': []}
                      ],
                      'totals': {'net': 4000000, 'carried_forward': 0, 'paid': paid.values.fold(0, (a, b) => a + b), 'balance': 0},
                      'can': {'pay': true},
                    }
                  ));
          x.on('GET salary/payslip', (req, b) {
            final e = int.parse(req.url.queryParameters['employee_id']!);
            return (200, {'line': cur().firstWhere((l) => l['employee_id'] == e), 'employee': {}, 'payments': []});
          });
          x.on('POST salary/payments', (req, b) {
            final e = b['employee_id'] as int;
            paid[e] = (paid[e] ?? 0) + parseRupees(b['amount'] as String)!;
            return (200, {'ok': true, 'pay_no': 'SP0000$e', 'balance': 2000000 - paid[e]!});
          });
        });
      });
      await pumpScreen(t, SalarySheetScreen(session: s, period: '2026-10'));
      expect(find.text('Ready (2)'), findsOneWidget);
      await t.tap(find.text('Pay next'));
      await t.pumpAndSettle();
      await t.tap(find.text('Online'));
      await t.pumpAndSettle();
      await t.tap(find.text('UPI'));
      await t.enterText(find.widgetWithText(TextFormField, 'Amount paid *'), '20000');
      await t.scrollUntilVisible(find.text('Confirm & record payment'), 300, scrollable: find.byType(Scrollable).first);
      await t.tap(find.text('Confirm & record payment'));
      await t.pumpAndSettle();
      await t.tap(find.text('Confirm & record'));
      await t.pumpAndSettle();
      expect(find.text('Salary fully paid'), findsOneWidget);
      await t.tap(find.text('Next employee'));
      await t.pumpAndSettle();
      expect(find.text('DEMO Sita D'), findsOneWidget, reason: 'next employee with salary due opens automatically');
      await t.pageBack();
      await t.pumpAndSettle();
      expect(find.text('Paid (1)'), findsOneWidget);
      expect(find.text('Ready (1)'), findsOneWidget);
      s.dispose();
    });
  });

  group('staff', () {
    testWidgets('Mark left needs last working day AND reason; nothing is posted without them', (t) async {
      late Session s;
      late FakeServer srv;
      await t.runAsync(() async {
        (s, srv) = await signedInSession('select_owner', routes: (x) => x.json('POST f360/staff-exit', {'ok': true, 'warning': null}));
      });
      await pumpScreen(t, MarkLeftScreen(session: s, employee: {'id': 9, 'name': 'DEMO Waiter Sunil'}));
      await t.tap(find.widgetWithText(FilledButton, 'Mark left'));
      await t.pumpAndSettle();
      expect(find.textContaining('Choose the last working day and write the reason'), findsOneWidget);
      expect(srv.calls('POST f360/staff-exit'), isEmpty);
      s.dispose();
    });

    testWidgets('Add staff posts department, designation, basic salary and joining date; duplicate code error is shown', (t) async {
      late Session s;
      late FakeServer srv;
      await t.runAsync(() async {
        (s, srv) = await signedInSession('select_owner', routes: (x) {
          x.json('GET departments', fixture('departments_v38'));
          x.json('POST employees', {'error': 'Staff code KS001 is already used.', 'code': 'CONFLICT'}, 409);
        });
      });
      await pumpScreen(t, StaffForm(session: s));
      await t.enterText(find.widgetWithText(TextField, 'Full name *'), 'DEMO New Cook');
      await t.enterText(find.widgetWithText(TextField, 'Staff / HR code (empty = next free code)'), 'KS001');
      await t.tap(find.byType(DropdownButtonFormField<int>).first);
      await t.pumpAndSettle();
      await t.tap(find.text('Kitchen').last);
      await t.pumpAndSettle();
      await t.tap(find.byType(DropdownButtonFormField<int>).last);
      await t.pumpAndSettle();
      await t.tap(find.byType(DropdownMenuItem<int>).last);
      await t.pumpAndSettle();
      await t.enterText(find.widgetWithText(TextFormField, 'Basic monthly salary *'), '15000');
      await t.tap(find.textContaining('Joining date'));
      await t.pumpAndSettle();
      await t.tap(find.text('OK'));
      await t.pumpAndSettle();
      await t.scrollUntilVisible(find.widgetWithText(FilledButton, 'Add staff'), 300, scrollable: find.byType(Scrollable).first);
      await t.tap(find.widgetWithText(FilledButton, 'Add staff'));
      await t.pumpAndSettle();
      final b = srv.lastBody('POST employees');
      expect(b['dept_id'], 3);
      expect(b['monthly'], '15000');
      expect(b['joined_on'], isNotNull);
      expect(find.textContaining('already used'), findsOneWidget);
      s.dispose();
    });
  });

  group('force update policy (pure rules)', () {
    UpdatePolicy p(Map<String, dynamic> extra) => UpdatePolicy.fromJson({
          'minimum_supported_version_code': 38,
          'latest_version_code': 40,
          'destination_type': 'external_apk',
          'update_url': 'https://staging.ksdd.in/app/finance360.apk',
          ...extra,
        })!;
    const pkg = 'com.finance360.app.staging';
    const hosts = {'ksdd.in', 'staging.ksdd.in'};

    test('versionCode compared as integers; below minimum = required; newer optional = banner only', () {
      expect(evaluate(p({}), 37), UpdateState.required);
      expect(evaluate(p({}), 38), UpdateState.optional);
      expect(evaluate(p({}), 40), UpdateState.ok);
      expect(evaluate(p({'force_update': true}), 39), UpdateState.required);
      expect(evaluate(p({'minimum_supported_version_code': '9'}), 10), UpdateState.optional, reason: 'not string compared');
      expect(evaluate(null, 1), UpdateState.ok);
      expect(UpdatePolicy.fromJson({'minimum_supported_version_code': 'x', 'destination_type': 'apk'}), isNull);
    });

    test('direct APK: only HTTPS on a trusted host, no other port, no user-info', () {
      expect(
          updateTargets(p({}), installedPackage: pkg, trustedApkHosts: hosts), [Uri.parse('https://staging.ksdd.in/app/finance360.apk')]);
      for (final bad in [
        'http://staging.ksdd.in/a.apk',
        'https://evil.example/a.apk',
        'https://staging.ksdd.in.evil.example/a.apk',
        'https://user@staging.ksdd.in/a.apk',
        'https://staging.ksdd.in:8443/a.apk',
        'javascript:alert(1)',
        '',
      ]) {
        expect(updateTargets(p({'update_url': bad}), installedPackage: pkg, trustedApkHosts: hosts), isEmpty, reason: bad);
      }
    });

    test('Play: only this package listing (market + https fallback); other package / staging confusion refused', () {
      final play = p({'destination_type': 'play_store', 'update_url': null});
      expect(updateTargets(play, installedPackage: 'com.finance360.app', trustedApkHosts: hosts).map((u) => u.toString()), [
        'market://details?id=com.finance360.app',
        'https://play.google.com/store/apps/details?id=com.finance360.app',
      ]);
      expect(
          updateTargets(p({'destination_type': 'play_store', 'update_url': 'https://play.google.com/store/apps/details?id=com.other'}),
              installedPackage: 'com.finance360.app', trustedApkHosts: hosts),
          isEmpty);
      expect(updateTargets(p({'package_id': 'com.finance360.app'}), installedPackage: pkg, trustedApkHosts: hosts), isEmpty,
          reason: 'a production policy must not drive the staging app');
    });

    test('checker: current backend (401/404) = no policy; offline keeps a FRESH cached mandatory policy; expired cache ignored', () async {
      final srv = FakeServer();
      final api = Api(base: Uri.parse('https://staging.example.test/api/v1/index.php'), store: MemorySessionStore(), client: srv.client);
      final cache = MemoryPolicyCache();
      var now = DateTime(2026, 11, 3, 10);
      final c = UpdateChecker(api: api, cache: cache, versionCode: 36, packageId: pkg, channel: 'direct', clock: () => now);
      srv.json('GET app/version-policy', {'error': 'Sign in required.', 'code': 'UNAUTHENTICATED'}, 401);
      expect((await c.check()).state, UpdateState.ok);
      srv.json('GET app/version-policy', {
        'minimum_supported_version_code': 38,
        'latest_version_code': 38,
        'destination_type': 'external_apk',
        'update_url': 'https://staging.ksdd.in/x.apk',
        'max_cache_age': 3600,
      });
      expect((await c.check()).state, UpdateState.required);
      srv.offline = true;
      now = now.add(const Duration(minutes: 30));
      final off = await c.check();
      expect(off.state, UpdateState.required);
      expect(off.fromCache, isTrue);
      now = now.add(const Duration(hours: 2));
      final stale = await c.check();
      expect(stale.state, UpdateState.ok, reason: 'an expired cache must not lock users out forever');
      expect(stale.error, isNotNull);
    });
  });

  testWidgets('mandatory update: whole app replaced, open pages closed, back cannot leave, writes refused locally', (t) async {
    late Session s;
    late FakeServer srv;
    await t.runAsync(() async {
      (s, srv) = await signedInSession('select_owner', routes: (x) {
        x.json('GET dashboard', fixture('dashboard'));
        // Current backend: no policy route yet (404) — the app runs normally.
        x.json('GET app/version-policy', {'error': 'Not found', 'code': 'NOT_FOUND'}, 404);
        x.json('POST expenses', {'ok': true});
      });
    });
    final opened = <Uri>[];
    final updates = UpdateController(
      checker: UpdateChecker(
          api: s.api, cache: MemoryPolicyCache(), versionCode: 36, packageId: 'com.finance360.app.staging', channel: 'direct'),
      trustedApkHosts: {'staging.ksdd.in'},
      launcher: (u) async {
        opened.add(u);
        return true;
      },
    );
    s.api.writesBlocked = () => updates.required;
    t.view.physicalSize = const Size(412, 915) * 2.625;
    t.view.devicePixelRatio = 2.625;
    addTearDown(t.view.reset);
    await t.pumpWidget(FinanceApp(session: s, updates: updates, versionLabel: '0.36.0 (36)'));
    await t.pumpAndSettle();
    expect(find.text('Khadak Singh Da Dhaba · Main'), findsOneWidget);
    // A page is open when the policy arrives.
    Navigator.of(t.element(find.byType(Scaffold).first))
        .push(MaterialPageRoute<void>(builder: (_) => const Scaffold(body: Text('PAYMENT FORM'))));
    await t.pumpAndSettle();
    // The server now publishes a mandatory policy; the next check (resume / timer / sign-in) applies it.
    srv.json('GET app/version-policy', {
      'minimum_supported_version_code': 38,
      'latest_version_code': 38,
      'destination_type': 'external_apk',
      'update_url': 'https://staging.ksdd.in/finance360-0.38.apk',
      'update_title': 'Update Finance 360',
      'update_message': 'Version 0.38 is required.',
    });
    await t.runAsync(() => updates.check());
    await t.pumpAndSettle();
    expect(find.text('Update Finance 360'), findsOneWidget);
    expect(find.text('PAYMENT FORM'), findsNothing);
    await t.binding.handlePopRoute();
    await t.pumpAndSettle();
    expect(find.text('Update Finance 360'), findsOneWidget, reason: 'back must not bypass');
    await t.tap(find.text('Update now'));
    await t.pumpAndSettle();
    expect(opened.single.toString(), 'https://staging.ksdd.in/finance360-0.38.apk');
    late Object err;
    await t.runAsync(() async {
      try {
        await s.api.post('expenses', {'amount': '1'});
      } catch (e) {
        err = e;
      }
    });
    expect((err as ApiError).code, 'UPDATE_REQUIRED');
    expect(srv.calls('POST expenses'), isEmpty);
    updates.dispose();
    s.dispose();
  });

  for (final (w, scale) in [(360.0, 1.3), (320.0, 1.0), (412.0, 2.0)]) {
    testWidgets('layout ${w.toInt()} dp × $scale text: salary line, sheet and payment screens without overflow', (t) async {
      late Session s;
      final sheet = fixture('sheet_v38');
      final lines = [for (final l in sheet['lines'] as List) (l as Map).cast<String, dynamic>()];
      final line = fixture('line_v38');
      final l = approvedLine(1, 'DEMO Ramesh K', net: 2238065, cf: 300000, paid: 100000);
      await t.runAsync(() async {
        (s, _) = await signedInSession('select_owner', routes: (x) {
          x.on('GET salary/drafts', (req, b) => (200, {...line, ...lines.first, 'context': line['context']}));
          x.json('POST salary/drafts', {
            'ok': true,
            'calc': {'payable_days': 28, 'gross_earned': 2258065, 'net_payable': 2238065, 'balance': 2538065}
          });
          x.json('GET salary/sheet', sheet);
          x.json('GET salary/payslip', {
            'line': l,
            'employee': {'code': 'KS001'},
            'payments': [
              {'pay_no': 'SP00001', 'date': '2026-11-02', 'amount': 100000, 'mode': 'Cash', 'reference': null, 'voided': 0}
            ]
          });
        });
      });
      t.view.physicalSize = Size(w, 760) * 2.625;
      t.view.devicePixelRatio = 2.625;
      t.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(t.view.reset);
      addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
      for (final screen in <Widget>[
        SalaryLineScreen(session: s, period: '2026-10', employeeId: 1, sheetLines: lines),
        SalarySheetScreen(session: s, period: '2026-10'),
        SalaryPayScreen(session: s, period: '2026-10', line: l),
      ]) {
        await t.pumpWidget(MaterialApp(home: screen));
        await t.pumpAndSettle();
        for (var i = 0; i < 6; i++) {
          await t.drag(find.byType(Scrollable).first, const Offset(0, -500));
          await t.pumpAndSettle();
        }
        expect(t.takeException(), isNull, reason: screen.runtimeType.toString());
      }
      s.dispose();
    });
  }
}
