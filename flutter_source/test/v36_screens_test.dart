// V36 screens: permission-only navigation, native Sales history, money screens and staff entries.
// Fixtures in test/fixtures are real responses captured from the V34 PHP backend with DEMO data (no real names).
import 'package:finance360/core/session.dart';
import 'package:finance360/main.dart';
import 'package:finance360/screens/money.dart';
import 'package:finance360/screens/sales.dart';
import 'package:finance360/screens/shell.dart';
import 'package:finance360/screens/staff.dart';
import 'package:finance360/screens/staff_extra.dart';
import 'package:finance360/screens/suppliers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers.dart';

Future<void> pumpApp(WidgetTester t, Session s, {Size size = const Size(412, 915), double textScale = 1}) async {
  t.view.physicalSize = size * 2.625;
  t.view.devicePixelRatio = 2.625;
  t.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(t.view.reset);
  addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
  await t.pumpWidget(FinanceApp(session: s));
  await t.pumpAndSettle();
}

Future<void> pumpScreen(WidgetTester t, Widget w, {Size size = const Size(412, 915)}) async {
  t.view.physicalSize = size * 2.625;
  t.view.devicePixelRatio = 2.625;
  addTearDown(t.view.reset);
  await t.pumpWidget(MaterialApp(home: Scaffold(body: w)));
  await t.pumpAndSettle();
}

Finder inNav(String text) => find.descendant(of: find.byType(NavigationBar), matching: find.text(text));

void ownerRoutes(FakeServer srv) {
  srv.json('GET dashboard', fixture('dashboard'));
  srv.json('GET sales', fixture('sales'));
  srv.json('GET cashbook', fixture('cashbook'));
  srv.json('GET accounts', fixture('accounts'));
  srv.json('GET handover', fixture('handover'));
  srv.json('GET suppliers', fixture('suppliers_list'));
  srv.json('GET suppliers/one', fixture('supplier_one'));
  srv.json('GET notifications', fixture('notifications'));
  srv.json('GET employees', fixture('employees'));
  srv.json('GET staff-entries', fixture('staff_entries'));
  srv.json('GET expenses', fixture('expenses'));
  srv.json('GET subscriptions/current', fixture('subscription'));
  srv.json('GET subscriptions/payments', {'orders': []});
  srv.json('GET salary/periods', fixture('salary_periods'));
  srv.json('GET salary/history', {'months': [], 'rows': [], 'note': 'Copied from your old salary sheets.', 'ready': true});
  srv.json('GET stock', fixture('stock'));
  srv.json('GET items', fixture('items'));
  srv.json('GET stock/moves', fixture('stock_moves'));
  srv.json('GET reports/monthly', fixture('monthly'));
  srv.json('GET documents', fixture('documents'));
  srv.json('GET purchase-bills', {
    'bills': [],
    'month': {'count': 0, 'total': 0},
    'counts': {}
  });
  srv.json('GET f360/users', {'users': []});
}

void main() {
  testWidgets('Owner: V36 bottom bar is Home · Daily Closing · Sales · Expenses · More, header shows restaurant · branch', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_owner', routes: ownerRoutes));
    await pumpApp(t, s);
    for (final tab in ['Home', 'Daily Closing', 'Sales', 'Expenses', 'More']) {
      expect(inNav(tab), findsOneWidget, reason: tab);
    }
    expect(find.text('Khadak Singh Da Dhaba · Main'), findsOneWidget);
    expect(find.textContaining('UI DEMO'), findsNothing);
    s.dispose();
  });

  testWidgets('Owner: More is a grouped grid of permitted modules and opens a native Cash book', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_owner', routes: ownerRoutes));
    await pumpApp(t, s);
    await t.tap(inNav('More'));
    await t.pumpAndSettle();
    for (final g in ['Finance', 'Team', 'Purchasing & stock', 'Restaurant']) {
      await t.scrollUntilVisible(find.text(g), 200, scrollable: find.byType(Scrollable).first);
      expect(find.text(g), findsOneWidget, reason: g);
    }
    await t.scrollUntilVisible(find.text('Cash book'), -200, scrollable: find.byType(Scrollable).first);
    expect(find.text('Cash book'), findsOneWidget);
    expect(find.textContaining('available on the Finance 360 website'), findsNothing, reason: 'no website-only placeholders');
    await t.ensureVisible(find.text('Cash book'));
    await t.pumpAndSettle();
    await t.tap(find.text('Cash book'));
    await t.pumpAndSettle();
    expect(find.text('Cash · closing balance'), findsOneWidget);
    s.dispose();
  });

  testWidgets('Storekeeper: More holds only purchasing extras — never Sales, Salary or Cash book', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_store', routes: (srv) {
          srv.json('GET purchase-bills', {
            'bills': [],
            'month': {'count': 0, 'total': 0},
            'counts': {}
          });
          srv.json('GET suppliers', fixture('suppliers_list'));
        }));
    await pumpApp(t, s);
    await t.tap(inNav('More'));
    await t.pumpAndSettle();
    expect(find.text('Purchase bills'), findsOneWidget);
    for (final hidden in ['Sales', 'Salary', 'Cash book', 'Accounts', 'Staff payments', 'Users & permissions', 'Subscription']) {
      expect(find.text(hidden), findsNothing, reason: '$hidden must be absent for a storekeeper');
    }
    s.dispose();
  });

  testWidgets('Legacy counter: only its own modules in the bar; Salary, Stock and Cash book never appear', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_counter_legacy', routes: (srv) {
          srv.json('GET closing', fixture('closing'));
          srv.json('GET dashboard', fixture('dashboard'));
        }));
    await pumpApp(t, s);
    expect(inNav('Daily Closing'), findsOneWidget);
    expect(inNav('Sales'), findsOneWidget);
    await t.tap(inNav('More'));
    await t.pumpAndSettle();
    for (final hidden in ['Salary', 'Stock', 'Cash book', 'Suppliers', 'Users & permissions']) {
      expect(find.text(hidden), findsNothing, reason: hidden);
    }
    expect(find.text('Cash handover'), findsOneWidget);
    s.dispose();
  });

  testWidgets('Revoked module: a page opened from More shows "No permission" after the server removes it', (t) async {
    late Session s;
    late FakeServer srv;
    await t.runAsync(() async => (s, srv) = await signedInSession('select_owner', routes: ownerRoutes));
    await pumpScreen(t, ModulePage(session: s, module: 'cash', open: (_) {}));
    expect(find.text('Cash · closing balance'), findsOneWidget);
    final revoked = Map<String, dynamic>.from(s.permissions!.raw)
      ..['menus'] = ['closing']
      ..['perm_version'] = 'revoked-1';
    srv.json('GET permissions/me', revoked);
    await t.runAsync(() => s.reloadPermissions());
    await t.pumpAndSettle();
    expect(find.text('No permission'), findsOneWidget);
    expect(find.text('Cash · closing balance'), findsNothing);
    s.dispose();
  });

  testWidgets('Sales: native history with Cash/Card/UPI totals and a day detail (no website placeholder)', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_owner', routes: ownerRoutes));
    await pumpScreen(t, SalesScreen(session: s));
    final f = fixture('sales');
    final total = (f['totals'] as Map)['total'] as int;
    expect(find.textContaining('Total sale ·'), findsOneWidget);
    expect(find.text(_r(total)), findsOneWidget);
    expect(find.text('This month'), findsOneWidget);
    final first = (f['days'] as List).first as Map;
    await t.tap(find.text(first['date'] as String));
    await t.pumpAndSettle();
    expect(find.text('Business day ${first['date']}'), findsOneWidget);
    expect(find.text('Total Sale'), findsOneWidget);
    s.dispose();
  });

  testWidgets('Expenses: empty period shows a helpful empty state with Add expense (no blank screen)', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_owner', routes: (srv) {
          ownerRoutes(srv);
          srv.json('GET expenses', {'rows': [], 'total': 0, 'by_category': [], 'by_account': [], 'count': 0});
        }));
    await pumpApp(t, s);
    await t.tap(inNav('Expenses'));
    await t.pumpAndSettle();
    expect(find.text('No expenses in this period.'), findsOneWidget);
    expect(find.text('Add expense'), findsNWidgets(2)); // top button + empty-state action
    s.dispose();
  });

  testWidgets('Handover: receiver accepts → POST handover/respond {id, act: accept}', (t) async {
    late FakeServer srv;
    late Session s;
    await t.runAsync(() async => (s, srv) = await signedInSession('select_owner', routes: (x) {
          ownerRoutes(x);
          x.json('POST handover/respond', {'ok': true});
        }));
    await pumpScreen(t, HandoverScreen(session: s));
    expect(find.textContaining('HO00001'), findsOneWidget);
    await t.tap(find.text('Accept'));
    await t.pumpAndSettle();
    await t.tap(find.widgetWithText(FilledButton, 'Accept').last);
    await t.pumpAndSettle();
    final b = srv.lastBody('POST handover/respond');
    expect(b['act'], 'accept');
    expect(b['id'], 1);
    s.dispose();
  });

  testWidgets('Supplier: ledger + aging, pay posts amount in rupees with a client key; confirm required', (t) async {
    late FakeServer srv;
    late Session s;
    await t.runAsync(() async => (s, srv) = await signedInSession('select_owner', routes: (x) {
          ownerRoutes(x);
          x.json('POST suppliers/pay', {'ok': true});
        }));
    final one = fixture('supplier_one');
    await pumpScreen(t, SupplierPayForm(session: s, supplier: (one['supplier'] as Map).cast<String, dynamic>(), balance: one['balance']));
    await t.enterText(find.widgetWithText(TextFormField, 'Payment amount'), '500');
    await t.tap(find.text('Record payment'));
    await t.pumpAndSettle();
    expect(srv.calls('POST suppliers/pay'), isEmpty, reason: 'nothing is posted before confirmation');
    await t.tap(find.text('Pay'));
    await t.pumpAndSettle();
    final b = srv.lastBody('POST suppliers/pay');
    expect(b['amount'], '500');
    expect(b['mode'], 'Cash');
    expect((b['client_key'] as String).length, greaterThanOrEqualTo(16));
    expect(srv.calls('POST suppliers/pay').single.headers['Idempotency-Key'], b['client_key']);
    s.dispose();
  });

  testWidgets('Staff entries: unpaid overtime is sent as paid=0 without a payment mode', (t) async {
    late FakeServer srv;
    late Session s;
    await t.runAsync(() async => (s, srv) = await signedInSession('select_owner', routes: (x) {
          ownerRoutes(x);
          x.json('POST overtime', {'ok': true, 'status': 'unpaid'});
        }));
    await pumpScreen(t, Navigator(onGenerateRoute: (_) => MaterialPageRoute(builder: (_) => StaffTxnForm(session: s))));
    await t.tap(find.text('Overtime'));
    await t.pumpAndSettle();
    await t.tap(find.byType(DropdownButtonFormField<int>));
    await t.pumpAndSettle();
    await t.tap(find.textContaining('DEMO Ramesh K').last);
    await t.pumpAndSettle();
    await t.enterText(find.widgetWithText(TextFormField, 'Amount'), '300');
    await t.enterText(find.widgetWithText(TextField, 'Reason *'), 'Extra shift on Sunday');
    await t.tap(find.text('Paid now'));
    await t.pumpAndSettle();
    await t.tap(find.text('Save'));
    await t.pumpAndSettle();
    final b = srv.lastBody('POST overtime');
    expect(b['kind'], 'overtime');
    expect(b['paid'], '0');
    expect(b.containsKey('mode'), isFalse);
    s.dispose();
  });

  testWidgets('Staff: departments in official order Management → UT → Kitchen → Waiter, expandable', (t) async {
    late Session s;
    await t.runAsync(() async => (s, _) = await signedInSession('select_owner', routes: ownerRoutes));
    await pumpScreen(t, StaffScreen(session: s));
    final titles = t
        .widgetList<Text>(find.descendant(of: find.byType(ExpansionTile), matching: find.byType(Text)))
        .map((w) => w.data ?? '')
        .where((x) => RegExp(r'^(Management|UT|Kitchen|Waiter) \(').hasMatch(x))
        .map((x) => x.split(' ').first)
        .toList();
    expect(titles, ['Management', 'UT', 'Kitchen', 'Waiter']);
    s.dispose();
  });

  for (final (w, scale) in [(360.0, 1.0), (360.0, 1.3), (412.0, 2.0)]) {
    testWidgets('Layout ${w.toInt()} dp at ${scale}x text: Home, Sales and More render without overflow', (t) async {
      late Session s;
      await t.runAsync(() async => (s, _) = await signedInSession('select_owner', routes: ownerRoutes));
      await pumpApp(t, s, size: Size(w, 780), textScale: scale);
      expect(t.takeException(), isNull);
      await t.tap(inNav('Sales'));
      await t.pumpAndSettle();
      expect(t.takeException(), isNull);
      await t.tap(inNav('More'));
      await t.pumpAndSettle();
      expect(t.takeException(), isNull);
      s.dispose();
    });
  }
}

String _r(int paise) {
  final s = (paise ~/ 100).toString();
  if (s.length <= 3) return '₹$s';
  final last3 = s.substring(s.length - 3);
  var rest = s.substring(0, s.length - 3);
  final parts = <String>[];
  while (rest.length > 2) {
    parts.insert(0, rest.substring(rest.length - 2));
    rest = rest.substring(0, rest.length - 2);
  }
  if (rest.isNotEmpty) parts.insert(0, rest);
  return '₹${parts.join(',')},$last3';
}
