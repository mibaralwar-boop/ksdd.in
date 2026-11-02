import 'package:finance360/core/api.dart';
import 'package:finance360/core/session.dart';
import 'package:finance360/main.dart';
import 'package:finance360/screens/purchase.dart';
import 'package:finance360/screens/salary.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers.dart';

Future<void> pumpApp(WidgetTester t, Session s) async {
  t.view.physicalSize = const Size(1080, 2400);
  t.view.devicePixelRatio = 2.6;
  addTearDown(t.view.reset);
  await t.pumpWidget(FinanceApp(session: s));
  await t.pumpAndSettle();
}

void main() {
  testWidgets('Unconfigured build blocks sign-in and says why', (t) async {
    final s = Session(Api(base: Uri(), store: MemorySessionStore()));
    await pumpApp(t, s);
    expect(find.textContaining('no configured secure server'), findsOneWidget);
    expect(t.widget<FilledButton>(find.widgetWithText(FilledButton, 'Sign in')).onPressed, isNull);
    s.dispose();
  });

  testWidgets('Cash Counter: opens Daily Closing directly, no bottom navigation, no other modules', (t) async {
    late Session s;
    await t.runAsync(() async {
      (s, _) = await signedInSession('select_cash_counter', routes: (srv) => srv.json('GET closing', fixture('closing')));
    });
    await pumpApp(t, s);
    expect(find.text('Daily Closing'), findsOneWidget);
    expect(find.byType(NavigationBar), findsNothing);
    for (final hidden in ['Salary', 'Stock', 'Purchase', 'Reports', 'Settings', 'Staff']) {
      expect(find.text(hidden), findsNothing, reason: '$hidden must be absent for a cash counter');
    }
    expect(find.textContaining("Yesterday's sale"), findsOneWidget);
    s.dispose();
  });

  testWidgets('Storekeeper: only purchase/supplier/stock/documents tabs', (t) async {
    late Session s;
    await t.runAsync(() async {
      (s, _) = await signedInSession('select_store', routes: (srv) {
        srv.json('GET purchase-bills', {'bills': [], 'month': {'count': 0, 'total': 0}, 'counts': {}});
      });
    });
    await pumpApp(t, s);
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.descendant(of: find.byType(NavigationBar), matching: find.text('Stock')), findsOneWidget);
    expect(find.descendant(of: find.byType(NavigationBar), matching: find.text('Daily Closing')), findsNothing);
    expect(find.descendant(of: find.byType(NavigationBar), matching: find.text('Salary')), findsNothing);
    s.dispose();
  });

  testWidgets('Owner: dashboard home with real figures and a More menu', (t) async {
    late Session s;
    await t.runAsync(() async {
      (s, _) = await signedInSession('select_owner', routes: (srv) => srv.json('GET dashboard', fixture('dashboard')));
    });
    await pumpApp(t, s);
    expect(find.descendant(of: find.byType(NavigationBar), matching: find.text('More')), findsOneWidget);
    expect(find.text('Cash in hand (books)'), findsOneWidget);
    expect(find.text('₹5,74,650'), findsWidgets); // cash_in_hand 57465000 paise from the server
    s.dispose();
  });

  testWidgets('Expired subscription: read-only banner, records still shown', (t) async {
    late Session s;
    await t.runAsync(() async {
      (s, _) = await signedInSession('select_cash_counter', overridePerms: {
        'read_only': true,
        'subscription': {'state': 'expired', 'label': 'Expired', 'read_only': true},
      }, routes: (srv) => srv.json('GET closing', fixture('closing')));
    });
    await pumpApp(t, s);
    expect(find.textContaining('read-only'), findsOneWidget);
    s.dispose();
  });

  testWidgets('Closing: sales saved to server, total automatic, finalize retries with the SAME idempotency key', (t) async {
    late Session s;
    late FakeServer srv;
    final base = fixture('closing');
    var finalizeCalls = 0;
    await t.runAsync(() async {
      (s, srv) = await signedInSession('select_cash_counter', routes: (x) {
        x.json('GET closing', base);
        x.on('POST closing/save', (req, b) {
          final step = b['step'] as int;
          return (200, {
            ...base,
            'version': (b['version'] as int) + 1,
            'step': step,
            'status': 'draft',
            'sales': {'cash': 3000000, 'card': 500000, 'upi': 700000, 'total': 4200000},
            'expenses_confirmed': step >= 5 ? 'none' : null,
            'actual_cash': step >= 6 ? 3000000 : null,
          });
        });
        x.on('POST closing/finalize', (req, b) {
          finalizeCalls++;
          if (finalizeCalls == 1) throw Exception('connection reset'); // reply lost
          return (200, {'ok': true, 'final': true, 'date': base['date'], 'message': 'Closing is final.', 'summary': {'cash': 3000000, 'card': 500000, 'upi': 700000, 'total_sale': 4200000, 'expenses': 0, 'actual_cash': 3000000}});
        });
        x.json('GET closing/photos', {'date': base['date'], 'photos': [], 'max': 6});
      });
    });
    await pumpApp(t, s);
    await t.enterText(find.widgetWithText(TextFormField, 'Cash Sale'), '30000');
    await t.enterText(find.widgetWithText(TextFormField, 'Card Sale'), '5000');
    await t.enterText(find.widgetWithText(TextFormField, 'UPI Sale'), '7000');
    await t.pump();
    expect(find.text('₹42,000'), findsOneWidget);
    await t.tap(find.text('Save & Next ›'));
    await t.pumpAndSettle();
    final saved = srv.lastBody('POST closing/save');
    expect(saved['cash_sale'], '30000');
    expect(saved['card_sale'], '5000');
    expect(saved['upi_sale'], '7000');
    expect(saved['step'], 4);
    // expenses
    await t.tap(find.text('No expenses today'));
    await t.tap(find.text('Save & Next ›'));
    await t.pumpAndSettle();
    expect(srv.lastBody('POST closing/save')['expenses_confirmed'], 'none');
    // actual cash
    await t.enterText(find.widgetWithText(TextFormField, 'Actual Cash (counted)'), '30000');
    await t.pump();
    // Actual 30000 − (Total Sale 42000 − cash out 0) = −12000 → Cash Short, shown live; above ₹500 a reason is required.
    expect(find.text('Cash Short'), findsOneWidget);
    expect(find.text('₹12,000'), findsOneWidget);
    await t.tap(find.text('Save & Next ›'));
    await t.pumpAndSettle();
    expect(find.text('Write a reason for the difference'), findsOneWidget);
    expect(srv.lastBody('POST closing/save').containsKey('actual_cash'), isFalse);
    await t.enterText(find.byType(TextFormField).last, 'Card machine settlement pending');
    await t.ensureVisible(find.text('Save & Next ›'));
    await t.tap(find.text('Save & Next ›'));
    await t.pumpAndSettle();
    expect(srv.lastBody('POST closing/save')['actual_cash'], '30000');
    // review → finalize (first reply lost, retry)
    await t.scrollUntilVisible(find.text('I confirm these figures are correct.'), 200);
    await t.tap(find.text('I confirm these figures are correct.'));
    await t.pump();
    await t.ensureVisible(find.text('Finalize & lock'));
    await t.tap(find.text('Finalize & lock'));
    await t.pumpAndSettle();
    expect(find.textContaining('will not be saved twice'), findsOneWidget);
    await t.tap(find.text('Finalize & lock'));
    await t.pumpAndSettle();
    expect(find.text('Closing final'), findsOneWidget);
    expect(find.text('Daybook photo (optional)'), findsOneWidget);
    final keys = srv.calls('POST closing/finalize').map((r) => r.headers['Idempotency-Key']).toSet();
    expect(keys.length, 1, reason: 'a retried finalize must reuse its idempotency key');
    s.dispose();
  });

  testWidgets('Salary: six inputs, server preview, Save & Next opens next pending employee in the same department', (t) async {
    late Session s;
    late FakeServer srv;
    final sheet = fixture('sheet');
    final lines = [for (final l in sheet['lines'] as List) (l as Map).cast<String, dynamic>()];
    final first = lines.first;
    final next = nextPendingLine(lines, first['employee_id'] as int)!;
    final line = fixture('line');
    await t.runAsync(() async {
      (s, srv) = await signedInSession('select_owner', routes: (x) {
        x.on('GET salary/drafts', (req, b) {
          final e = int.parse(req.url.queryParameters['employee_id']!);
          final l = lines.firstWhere((x) => x['employee_id'] == e);
          return (200, {...line, ...l, 'context': line['context']});
        });
        x.on('POST salary/drafts', (req, b) {
          if (b['action'] == 'preview') {
            return (200, {'ok': true, 'calc': {'payable_days': 29, 'absent': 1, 'gross_earned': 2338710, 'advance_recovery': 0, 'penalties': 0, 'net_payable': 2338710, 'carried_forward': 0, 'paid': 0, 'balance': 2338710}});
          }
          return (200, {'ok': true, 'line': {'employee_id': b['employee_id'], 'entry_status': 'completed', 'version': 2, 'saved_at': '2026-11-02 18:30:00'}, 'calc': {'net_payable': 2338710}});
        });
      });
    });
    await pumpApp(t, s);
    await t.pumpWidget(MaterialApp(home: SalaryLineScreen(session: s, period: '2026-10', employeeId: first['employee_id'] as int, sheetLines: lines)));
    await t.pumpAndSettle();
    expect(find.text('${first['name']}'), findsOneWidget);
    for (final label in ['1 · Present days *', '2 · Absent days (auto if empty)', '3 · Half days', '4 · Paid leave days', '5 · Advance recovery']) {
      expect(find.widgetWithText(TextFormField, label), findsOneWidget);
    }
    expect(find.text('6 · Approved penalty deduction'), findsOneWidget);
    await t.enterText(find.widgetWithText(TextFormField, '1 · Present days *'), '28');
    await t.enterText(find.widgetWithText(TextFormField, '3 · Half days'), '2');
    await t.pump(const Duration(milliseconds: 700));
    await t.pumpAndSettle();
    expect(find.text('₹23,387.10'), findsWidgets); // server preview value
    await t.scrollUntilVisible(find.text('Save & Next'), 300, scrollable: find.byType(Scrollable).first);
    await t.ensureVisible(find.text('Save & Next'));
    await t.pumpAndSettle();
    await t.tap(find.text('Save & Next'));
    await t.pumpAndSettle();
    final saveCall = srv.calls('POST salary/drafts').map((r) => r.body).where((b) => b.contains('"action":"save"')).single;
    expect(saveCall, contains('"present":"28"'));
    expect(saveCall, contains('"half_days":"2"'));
    expect(find.text('${next['name']}'), findsOneWidget, reason: 'Save & Next must open the next pending employee');
    s.dispose();
  });

  testWidgets('Purchase: supplier-first suggestions; changed rate needs confirmation before posting', (t) async {
    late Session s;
    late FakeServer srv;
    var submits = 0;
    await t.runAsync(() async {
      (s, srv) = await signedInSession('select_owner', routes: (x) {
        x.json('GET suppliers', fixture('suppliers'));
        x.json('GET suppliers/suggest', fixture('suggest'));
        x.json('POST purchase-bills', {'ok': true, 'bill': {'id': 7, 'version': 1}, 'lines': [{'id': 70}]});
        x.on('POST purchase-bills/submit', (req, b) {
          submits++;
          if (b['rates_ok'] != 1) {
            return (409, {
              'error': 'Rates changed',
              'code': 'CONFLICT',
              'needs': 'rates_ok',
              'rate_changes': [
                {'item': 'DEMO Onion', 'previous_per_stock_unit': 3200, 'new_per_stock_unit': 4000, 'stock_unit': 'kg', 'change_pct': 25, 'previous_date': '2026-11-01'}
              ]
            });
          }
          return (200, {'ok': true, 'status': 'posted', 'receipt': {'receipt_no': 'GR00009'}});
        });
      });
    });
    await pumpApp(t, s);
    await t.pumpWidget(MaterialApp(home: Scaffold(body: Builder(builder: (c) => BillEditor(session: s)))));
    await t.pumpAndSettle();
    await t.tap(find.text('Choose supplier'));
    await t.pumpAndSettle();
    await t.tap(find.text('DEMO Sharma Vegetables'));
    await t.pumpAndSettle();
    expect(find.text('DEMO Onion'), findsOneWidget); // last-delivered item suggested
    expect(find.textContaining('@ ₹32'), findsOneWidget); // with last rate
    await t.enterText(find.widgetWithText(TextFormField, 'Qty (kg)'), '5');
    await t.enterText(find.widgetWithText(TextFormField, 'Rate / kg'), '40');
    await t.pump();
    expect(find.textContaining('Old ₹32 → New ₹40'), findsOneWidget);
    await t.scrollUntilVisible(find.text('Submit bill'), 300, scrollable: find.byType(Scrollable).first);
    await t.ensureVisible(find.text('Submit bill'));
    await t.pumpAndSettle();
    await t.tap(find.text('Submit bill'));
    await t.pumpAndSettle();
    expect(find.text('Rate changed'), findsOneWidget);
    await t.tap(find.text('Yes, rates are correct'));
    await t.pumpAndSettle();
    expect(find.text('Bill posted'), findsOneWidget);
    expect(submits, 2);
    final saved = srv.lastBody('POST purchase-bills');
    expect((saved['lines'] as List).single, containsPair('qty', '5'));
    expect(saved.containsKey('gstin'), isFalse, reason: 'no GST fields on the entry form');
    s.dispose();
  });
}
