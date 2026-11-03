// LIVE integration test: the app's own Api client against a REAL Finance 360 V29 PHP server + MariaDB.
// Run with tool/live_test.sh (builds disposable staging databases with demo data). Skipped when F360_LIVE_BASE is unset.
@TestOn('vm')
library;

import 'dart:convert';
import 'dart:io';

import 'package:finance360/core/api.dart';
import 'package:finance360/core/session.dart';
import 'package:finance360/core/format.dart';
import 'package:finance360/screens/salary.dart';
import 'package:flutter_test/flutter_test.dart';

final base = Platform.environment['F360_LIVE_BASE'];
final jpegPath = Platform.environment['F360_LIVE_JPEG'];

Api newApi() => Api(base: Uri.parse(base!), store: MemorySessionStore(), allowInsecureLoopback: true);

Future<Session> signIn(String email, String pw, {int? tenant, int? branch}) async {
  final s = Session(newApi());
  await s.api.restore();
  await s.login(email, pw);
  if (tenant != null && (s.permissions == null || s.permissions!.tenantId != tenant || s.permissions!.branchId != branch)) {
    await s.select(tenant, branch!);
  }
  return s;
}

Matcher apiError(int status) => throwsA(isA<ApiError>().having((e) => e.status, 'status', status));

void main() {
  if (base == null) {
    test('live backend tests', () {}, skip: 'Set F360_LIVE_BASE (run tool/live_test.sh)');
    return;
  }

  test('health is public', () async {
    final r = await newApi().publicGet('health');
    expect(r['ok'], isTrue);
  });

  group('Cash Counter (Alpha Dhaba)', () {
    late Session s;
    setUpAll(() async => s = await signIn('cashier.a@example.test', 'CashPass#2026'));
    tearDownAll(() => s.dispose());

    test('single membership opens directly; server menus = [closing] only', () {
      expect(s.inBranch, isTrue);
      expect(s.permissions!.menus, ['closing']);
      expect(s.permissions!.home, 'closing');
      expect(visibleModules(s.permissions!), ['closing']);
    });

    test('hidden modules are refused by the server, not just hidden', () async {
      await expectLater(s.api.get('salary/sheet', {'period': '2026-10'}), apiError(403));
      await expectLater(s.api.get('suppliers'), apiError(403));
      await expectLater(s.api.get('reports/monthly'), apiError(403));
    });

    test('cannot open another restaurant (same 403 as non-existent)', () async {
      await expectLater(s.api.post('tenants/select', {'tenant_id': 1, 'branch_id': 1}), apiError(403));
      await expectLater(s.api.post('tenants/select', {'tenant_id': 999, 'branch_id': 999}), apiError(403));
    });

    test('previous-day closing: sales → expenses → actual cash → FINAL without admin approval; retry is idempotent; day locks', () async {
      var c = await s.api.get('closing');
      final date = c['date'] as String;
      expect(c['locked'], isFalse, reason: 'fixture day must be open');
      c = await s.api.post('closing/save',
          {'date': date, 'version': c['version'], 'step': 4, 'cash_sale': '25000', 'card_sale': '3000', 'upi_sale': '4000.50'});
      expect((c['sales'] as Map)['total'], 3200050);
      // The server refuses "No expenses" when expenses exist for the day; the user must review them instead.
      final hasExpenses = (c['expenses'] as List).isNotEmpty;
      if (hasExpenses) {
        final bad = await s.api.post('closing/save', {'date': date, 'version': c['version'], 'step': 5, 'expenses_confirmed': 'none'});
        await expectLater(
            s.api.post('closing/finalize', {'date': date, 'version': bad['version'], 'confirm': true}), throwsA(isA<ApiError>()));
        c = bad;
      }
      c = await s.api.post(
          'closing/save', {'date': date, 'version': c['version'], 'step': 5, 'expenses_confirmed': hasExpenses ? 'reviewed' : 'none'});
      // Wrong version → 409, nothing overwritten
      await expectLater(
          s.api.post('closing/save', {'date': date, 'version': (c['version'] as int) - 1, 'step': 6, 'actual_cash': '1'}), apiError(409));
      c = await s.api.post('closing/save', {
        'date': date,
        'version': c['version'],
        'step': 6,
        'actual_cash': '25000', // physical drawer = cash sale only; Card/UPI are not cash
        'diff_reason': 'Card and UPI are not in the drawer',
      });
      final key = randomKey();
      final f1 = await s.api.post('closing/finalize', {'date': date, 'version': c['version'], 'confirm': true}, idem: key);
      expect(f1['final'], isTrue, reason: 'V29: counter finalises directly');
      final f2 = await s.api.post('closing/finalize', {'date': date, 'version': c['version'], 'confirm': true}, idem: key);
      expect(f2['duplicate'], isTrue, reason: 'lost-reply retry must not post twice');
      final after = await s.api.get('closing', {'date': date});
      expect(after['locked'], isTrue);
      expect(after['status'], 'approved');
      await expectLater(
          s.api.post(
              'closing/save', {'date': date, 'version': after['version'], 'step': 4, 'cash_sale': '1', 'card_sale': '0', 'upi_sale': '0'}),
          throwsA(isA<ApiError>()));
    });

    test('optional daybook photo: JPEG accepted after finalising; PDF refused', () async {
      final c = await s.api.get('closing', {'date': s.permissions!.closingTargetDate});
      final date = c['date'] as String;
      final bytes = File(jpegPath!).readAsBytesSync();
      await s.api.post('closing/photos', {
        'date': date,
        'file': {'name': 'daybook.jpg', 'data': base64Encode(bytes)}
      });
      final list = await s.api.get('closing/photos', {'date': date});
      expect((list['photos'] as List).length, 1);
      await expectLater(
          s.api.post('closing/photos', {
            'date': date,
            'file': {'name': 'x.pdf', 'data': base64Encode(utf8.encode('%PDF-1.4 fake'))}
          }),
          throwsA(isA<ApiError>()));
    });
  });

  test('refresh token rotation: old refresh token replay revokes the session family', () async {
    final s = await signIn('cashier.a@example.test', 'CashPass#2026');
    final store = s.api.store as MemorySessionStore;
    final oldRefresh = store.values['refresh']!;
    expect(await s.api.refreshTokens(), isTrue);
    expect(store.values['refresh'], isNot(oldRefresh));
    await expectLater(s.api.publicPost('auth/refresh', {'refresh_token': oldRefresh, 'device_id': s.api.deviceId}), apiError(401));
    await expectLater(s.api.get('closing'), apiError(401), reason: 'family revoked after replay');
    expect(s.api.hasSession, isFalse);
    s.dispose();
  });

  group('Owner (Khadak Singh Da Dhaba, adopted legacy data)', () {
    late Session s;
    setUpAll(() async => s = await signIn('owner@staging.test', 'OwnerPass#2026', tenant: 1, branch: 1));
    tearDownAll(() => s.dispose());

    test('owner permissions include every module; dashboard reads real ledger', () async {
      expect(visibleModules(s.permissions!).first, 'dashboard');
      final d = await s.api.get('dashboard');
      expect(d['cash_in_hand'], isA<int>());
    });

    test('salary: calendar-day divisor, server preview, save, stale version refused, Save & Next order', () async {
      const period = '2026-10';
      var sheet = await s.api.get('salary/sheet', {'period': period});
      if (sheet['exists'] != true) {
        await s.api.post('salary/open', {'period': period});
        sheet = await s.api.get('salary/sheet', {'period': period});
      }
      expect(sheet['divisor'], 31);
      final lines = [for (final l in sheet['lines'] as List) (l as Map).cast<String, dynamic>()];
      final first = lines.firstWhere((l) => l['entry_status'] == 'pending');
      final emp = first['employee_id'] as int;
      final line = await s.api.get('salary/drafts', {'period': period, 'employee_id': '$emp'});
      final monthly = (line['context'] as Map)['monthly'] as int;
      final body = {
        'period': period,
        'employee_id': emp,
        'version': line['version'],
        'present': '28',
        'half_days': '2',
        'paid_leave': '',
        'rest_days': '',
        'advance_recovery': '0',
        'penalty_ids': <int>[]
      };
      final pv = await s.api.post('salary/drafts', {...body, 'action': 'preview'});
      final calc = pv['calc'] as Map;
      expect(calc['divisor'], 31);
      expect(calc['payable_days'], 29); // 28 + 0.5 × 2
      expect(calc['gross_earned'], (monthly * 29 / 31).round());
      final saved = await s.api.post('salary/drafts', {...body, 'action': 'save'});
      expect((saved['line'] as Map)['entry_status'], 'completed');
      await expectLater(s.api.post('salary/drafts', {...body, 'action': 'save'}), apiError(409), reason: 'stale version');
      final again = await s.api.get('salary/sheet', {'period': period});
      final newLines = [for (final l in again['lines'] as List) (l as Map).cast<String, dynamic>()];
      final next = nextPendingLine(newLines, emp);
      if (next != null) expect(next['dept_id'], first['dept_id']);
    });

    test('supplier-first purchase: suggestion with last rate → changed rate needs confirmation → posted once', () async {
      final sup = ((await s.api.get('suppliers'))['suppliers'] as List).first as Map;
      final sg = await s.api.get('suppliers/suggest', {'supplier_id': '${sup['id']}'});
      final items = ((await s.api.get('items'))['items'] as List);
      final item = items.first as Map;
      final lastRate = (sg['suggested_items'] as List).isEmpty ? null : ((sg['suggested_items'] as List).first as Map)['last_rate'] as int;
      final newRate = ((lastRate ?? 3000) + 700) / 100;
      Future<Map<String, dynamic>> bill(String inv) => s.api.post('purchase-bills', {
            'supplier_id': sup['id'],
            'invoice_no': inv,
            'invoice_date': s.permissions!.businessDate,
            'location': 'Main Store',
            'lines': [
              {'item_id': item['id'], 'unit': item['unit'], 'qty': '4', 'rate': '$newRate'}
            ],
          });
      final inv = 'LIVE-${DateTime.now().millisecondsSinceEpoch}';
      final b = await bill(inv);
      final id = (b['bill'] as Map)['id'];
      final v = (b['bill'] as Map)['version'];
      if (lastRate != null) {
        try {
          await s.api.post('purchase-bills/submit', {'id': id, 'version': v, 'receive_all': 1});
          fail('expected rate confirmation');
        } on ApiError catch (e) {
          expect(e.status, 409);
          expect(e.needs, 'rates_ok');
          expect((e.data['rate_changes'] as List), isNotEmpty);
        }
      }
      final k = randomKey();
      final r = await s.api.post('purchase-bills/submit', {'id': id, 'version': v, 'receive_all': 1, 'rates_ok': 1}, idem: k);
      expect(r['status'], 'posted');
      final dup = await s.api.post('purchase-bills/submit', {'id': id, 'version': v, 'receive_all': 1, 'rates_ok': 1}, idem: k);
      expect(dup['duplicate'], isTrue);
      final sg2 = await s.api.get('suppliers/suggest', {'supplier_id': '${sup['id']}'});
      final it2 = (sg2['suggested_items'] as List).cast<Map>().firstWhere((x) => x['item_id'] == item['id']);
      expect(it2['last_rate'], (newRate * 100).round(), reason: 'price history updated only after posting');
    });
  });

  group('V36 native screens against the real backend (owner of Khadak Singh Da Dhaba)', () {
    late Session s;
    setUpAll(() async => s = await signIn('owner@staging.test', 'OwnerPass#2026', tenant: 1, branch: 1));
    tearDownAll(() => s.dispose());

    test('Sales history: day rows add up to the period totals (integer paise)', () async {
      final bd = DateTime.parse(s.permissions!.businessDate);
      final from = bd.subtract(const Duration(days: 60)).toIso8601String().substring(0, 10);
      final r = await s.api.get('sales', {'from': from, 'to': s.permissions!.businessDate});
      final days = (r['days'] as List).cast<Map>();
      expect(days, isNotEmpty);
      final t = r['totals'] as Map;
      for (final k in ['cash', 'card', 'upi', 'total']) {
        expect(days.fold<int>(0, (a, d) => a + (d[k] as int)), t[k], reason: k);
      }
    });

    test('Cash book: closing = opening + every non-voided cash movement', () async {
      final bd = DateTime.parse(s.permissions!.businessDate);
      final from = bd.subtract(const Duration(days: 45)).toIso8601String().substring(0, 10);
      final r = await s.api.get('cashbook', {'from': from, 'to': s.permissions!.businessDate});
      final moves = (r['txns'] as List).cast<Map>().where((x) => x['voided'] == 0).fold<int>(0, (a, x) => a + (x['cash'] as int));
      expect(r['closing'], (r['opening'] as int) + moves);
      final acc = await s.api.get('accounts');
      final cash = (acc['accounts'] as List).cast<Map>().firstWhere((a) => a['account'] == r['account']);
      expect(cash['balance'], r['closing'], reason: 'account balance equals the book closing when the period ends today');
    });

    test('Cash handover: send → listed as sent → owner accepts once (second answer refused)', () async {
      final h = await s.api.get('handover');
      final to = ((h['receivers'] as List).first as Map)['id'];
      final key = randomKey();
      final sent = await s.api.post(
          'handover', {'to_user': to, 'amount': '125.50', 'from_account': h['cash_account'], 'note': 'live test', 'client_key': key},
          idem: key);
      final id = (sent['handover'] as Map)['id'];
      expect((sent['handover'] as Map)['amount'], 12550);
      final again = await s.api.post(
          'handover', {'to_user': to, 'amount': '125.50', 'from_account': h['cash_account'], 'note': 'live test', 'client_key': key},
          idem: key);
      expect(again['duplicate'], isTrue, reason: 'same client key never creates a second handover');
      await s.api.post('handover/respond', {'id': id, 'act': 'accept'});
      await expectLater(s.api.post('handover/respond', {'id': id, 'act': 'accept'}), apiError(409));
      final rows = ((await s.api.get('handover'))['rows'] as List).cast<Map>();
      expect(rows.firstWhere((r) => r['id'] == id)['status'], 'accepted');
    });

    test('Staff entries: unpaid overtime is recorded as unpaid; paid overtime needs a payment mode', () async {
      final emp = (((await s.api.get('employees'))['staff'] as List).first as Map)['id'];
      final k = randomKey();
      final r = await s.api.post('overtime',
          {'kind': 'overtime', 'employee_id': emp, 'amount': '200', 'reason': 'live test extra shift', 'paid': '0', 'client_key': k},
          idem: k);
      expect(r['status'], 'unpaid');
      await expectLater(
          s.api.post('overtime', {'kind': 'overtime', 'employee_id': emp, 'amount': '200', 'reason': 'live test paid shift', 'paid': '1'}),
          apiError(400));
      final list = ((await s.api.get('staff-entries', {'kind': 'overtime'}))['rows'] as List).cast<Map>();
      expect(list.any((x) => x['id'] == r['id'] && x['status'] == 'unpaid'), isTrue);
    });

    test('Supplier: payment lowers the balance exactly once (idempotent retry)', () async {
      final sup = ((await s.api.get('suppliers'))['suppliers'] as List).cast<Map>().firstWhere((x) => (x['balance'] as int) > 10000);
      final before = (await s.api.get('suppliers/one', {'id': '${sup['id']}'}))['balance'] as int;
      final k = randomKey();
      final body = {'supplier_id': sup['id'], 'amount': '50', 'mode': 'Cash', 'client_key': k};
      await s.api.post('suppliers/pay', body, idem: k);
      await s.api.post('suppliers/pay', body, idem: k);
      final after = (await s.api.get('suppliers/one', {'id': '${sup['id']}'}))['balance'] as int;
      expect(after, before - 5000);
    });

    test('Documents: photo upload goes to the private inbox and is listed', () async {
      final bytes = File(jpegPath!).readAsBytesSync();
      final r = await s.api.post('documents', {
        'kind': 'purchase',
        'files': [
          {'name': 'live-bill.jpg', 'data': base64Encode(bytes)}
        ]
      });
      final id = (r['ids'] as List).first;
      final rows = ((await s.api.get('documents', {'status': 'open'}))['rows'] as List).cast<Map>();
      expect(rows.any((x) => x['id'] == id), isTrue);
    }, skip: jpegPath == null ? 'no JPEG' : null);

    test('Notifications: list + per-user snooze; expenses period filter; subscription state', () async {
      final n = await s.api.get('notifications');
      final items = (n['items'] as List).cast<Map>();
      if (items.isNotEmpty) {
        await s.api.post('f360/snooze', {'key': items.first['dedupe_key'], 'minutes': 60});
        final n2 = (await s.api.get('notifications'))['items'] as List;
        expect((n2.cast<Map>().firstWhere((x) => x['id'] == items.first['id']))['snoozed'], isTrue);
      }
      final e = await s.api.get('expenses', {
        'from': DateTime.parse(s.permissions!.businessDate).subtract(const Duration(days: 90)).toIso8601String().substring(0, 10),
        'to': s.permissions!.businessDate
      });
      expect((e['rows'] as List).cast<Map>().fold<int>(0, (a, x) => a + (x['voided'] == 1 ? 0 : x['total'] as int)), e['total']);
      final sub = await s.api.get('subscriptions/current');
      expect(sub['read_only'], isFalse);
    });
  });

  test('Isolation: another restaurant\'s owner cannot read KSDD supplier, cash book rows or sales', () async {
    final ksdd = await signIn('owner@staging.test', 'OwnerPass#2026', tenant: 1, branch: 1);
    final ksddSup = ((await ksdd.api.get('suppliers'))['suppliers'] as List).cast<Map>().first;
    final from = DateTime.parse(ksdd.permissions!.businessDate).subtract(const Duration(days: 300)).toIso8601String().substring(0, 10);
    final ksddSales = await ksdd.api.get('sales', {'from': from, 'to': ksdd.permissions!.businessDate});
    ksdd.dispose();
    final a = await signIn('ownera@example.test', 'TestPass#2026');
    final alpha = a.memberships.firstWhere((m) => m['name'] == 'Alpha Dhaba');
    await a.select((alpha['id'] as num).toInt(), (((alpha['branches'] as List).first as Map)['id'] as num).toInt());
    final mine = ((await a.api.get('suppliers'))['suppliers'] as List).cast<Map>();
    expect(mine.any((x) => x['name'] == ksddSup['name']), isFalse);
    try {
      final one = await a.api.get('suppliers/one', {'id': '${ksddSup['id']}'});
      expect((one['supplier'] as Map)['name'], isNot(ksddSup['name']));
    } on ApiError catch (e) {
      expect(e.status, 404);
    }
    final aSales = await a.api.get('sales', {'from': from, 'to': a.permissions!.businessDate});
    expect((aSales['totals'] as Map)['total'], isNot((ksddSales['totals'] as Map)['total']));
    a.dispose();
  });

  test('Cash counter: Cash book, Accounts and Suppliers are refused by the server (403)', () async {
    final c = await signIn('cashier.a@example.test', 'CashPass#2026');
    for (final r in ['cashbook', 'accounts', 'suppliers', 'staff-entries', 'f360/users']) {
      await expectLater(c.api.get(r), apiError(403), reason: r);
    }
    c.dispose();
  });

  group('V38 salary + staff against the real backend (owner of Khadak Singh Da Dhaba)', () {
    late Session s;
    setUpAll(() async => s = await signIn('owner@staging.test', 'OwnerPass#2026', tenant: 1, branch: 1));
    tearDownAll(() => s.dispose());
    const period = '2026-10';

    test('Add staff (duplicate code refused) → roster refresh puts the new joiner on the open month with eligible days', () async {
      var sheet = await s.api.get('salary/sheet', {'period': period});
      if (sheet['exists'] != true) await s.api.post('salary/open', {'period': period});
      final deps = ((await s.api.get('departments'))['departments'] as List).cast<Map>();
      final kitchen = deps.firstWhere((d) => d['name'] == 'Kitchen');
      final desig = (kitchen['designations'] as List).cast<Map>().first['id'];
      final existingCode = ((await s.api.get('employees'))['staff'] as List).cast<Map>().first['code'];
      await expectLater(
          s.api.post('employees', {
            'name': 'LIVE Duplicate',
            'code': existingCode,
            'dept_id': kitchen['id'],
            'desig_id': desig,
            'monthly': '9000',
            'joined_on': '2026-10-16'
          }),
          apiError(409));
      final r = await s.api.post('employees',
          {'name': 'LIVE New Cook', 'dept_id': kitchen['id'], 'desig_id': desig, 'monthly': '15500', 'joined_on': '2026-10-16'});
      final id = r['id'];
      await s.api.post('salary/open', {'period': period}); // the app's roster refresh
      sheet = await s.api.get('salary/sheet', {'period': period});
      final l = (sheet['lines'] as List).cast<Map>().firstWhere((x) => x['employee_id'] == id);
      expect(l['eligible_days'], 16, reason: 'joined 16 Oct → 16 of 31 days');
      final line = await s.api.get('salary/drafts', {'period': period, 'employee_id': '$id'});
      for (final k in balanceKeys) {
        expect((line['context'] as Map)[k], isA<num>(), reason: 'previous-balance key $k must come from the server');
      }
    });

    test('Mark left needs date + reason; the open month pays only the days up to the exit; record kept; rejoin restores', () async {
      final staff = ((await s.api.get('employees'))['staff'] as List).cast<Map>().firstWhere((x) => x['name'] == 'LIVE New Cook');
      await expectLater(s.api.post('f360/staff-exit', {'id': staff['id'], 'left_on': '2026-10-20'}), apiError(400));
      await s.api.post('f360/staff-exit', {'id': staff['id'], 'left_on': '2026-10-20', 'reason': 'live test: moved city'});
      await s.api.post('salary/open', {'period': period});
      final sheet = await s.api.get('salary/sheet', {'period': period});
      final l = (sheet['lines'] as List).cast<Map>().firstWhere((x) => x['employee_id'] == staff['id']);
      expect(l['eligible_days'], 5, reason: 'joined 16 Oct, left 20 Oct → 5 paid-eligible days, not the whole month');
      final one = await s.api.get('employees/one', {'id': '${staff['id']}'});
      expect((one['staff'] as Map)['status'], 'left');
      await s.api.post('f360/staff-exit', {'id': staff['id'], 'rejoin': 1});
      expect(((await s.api.get('employees/one', {'id': '${staff['id']}'}))['staff'] as Map)['status'], 'active');
    });

    test('Full month: enter every line (present only, half/leave 0, advance 0) → submit → approve → part pay Cash → pay rest Online',
        () async {
      var sheet = await s.api.get('salary/sheet', {'period': period});
      for (final l in (sheet['lines'] as List).cast<Map>()) {
        if (l['entry_status'] == 'excluded' || l['entry_status'] == 'completed') continue;
        final line = await s.api.get('salary/drafts', {'period': period, 'employee_id': '${l['employee_id']}'});
        final elig = (line['context'] as Map)['eligible_days'] as num;
        await s.api.post('salary/drafts', {
          'period': period,
          'employee_id': l['employee_id'],
          'action': 'save',
          'version': line['version'],
          'present': '${elig.toInt() - 1}',
          'absent': '',
          'half_days': '0',
          'paid_leave': '0',
          'rest_days': '',
          'advance_recovery': '0',
          'penalty_ids': <int>[],
        });
      }
      sheet = await s.api.get('salary/sheet', {'period': period});
      if (sheet['status'] == 'draft') await s.api.post('salary/submit', {'period': period});
      sheet = await s.api.get('salary/sheet', {'period': period});
      if (sheet['status'] == 'review') await s.api.post('salary/approve', {'period': period, 'version': sheet['version']});
      sheet = await s.api.get('salary/sheet', {'period': period});
      expect(sheet['status'], anyOf('approved', 'locked'));
      final lines = [for (final l in sheet['lines'] as List) (l as Map).cast<String, dynamic>()];
      final l = lines.firstWhere((x) => payStatus(x) == 'ready' && (x['balance'] as int) > 20000);
      final due = l['balance'] as int;
      // amount 0 and over-due are refused by the server too
      await expectLater(
          s.api.post('salary/payments', {'period': period, 'employee_id': l['employee_id'], 'amount': '0', 'mode': 'Cash'}), apiError(400));
      await expectLater(
          s.api.post(
              'salary/payments', {'period': period, 'employee_id': l['employee_id'], 'amount': rupeeInput(due + 100), 'mode': 'Cash'}),
          apiError(422));
      final k1 = randomKey();
      final part = {'period': period, 'employee_id': l['employee_id'], 'amount': '100', 'mode': 'Cash', 'client_key': k1};
      final p1 = await s.api.post('salary/payments', part, idem: k1);
      await s.api.post('salary/payments', part, idem: k1); // retried: must not pay twice
      expect(p1['balance'], due - 10000);
      var now = (await s.api.get('salary/sheet', {'period': period}))['lines'] as List;
      expect(payStatus((now.cast<Map>().firstWhere((x) => x['employee_id'] == l['employee_id'])).cast<String, dynamic>()), 'part_paid');
      final k2 = randomKey();
      final p2 = await s.api.post(
          'salary/payments',
          {
            'period': period,
            'employee_id': l['employee_id'],
            'amount': rupeeInput(due - 10000),
            'mode': 'UPI',
            'reference': 'LIVE-UTR-1',
            'client_key': k2
          },
          idem: k2);
      expect(p2['balance'], 0);
      now = (await s.api.get('salary/sheet', {'period': period}))['lines'] as List;
      expect(payStatus((now.cast<Map>().firstWhere((x) => x['employee_id'] == l['employee_id'])).cast<String, dynamic>()), 'paid');
      final slip = await s.api.get('salary/payslip', {'period': period, 'employee_id': '${l['employee_id']}'});
      expect((slip['payments'] as List).length, 2);
      expect(nextPayableLine([for (final x in now) (x as Map).cast<String, dynamic>()], l['employee_id'] as int), isNotNull);
    });
  });

  test('read-only subscription (second restaurant, no trial): reads work, writes get 402', () async {
    final s = await signIn('ownera@example.test', 'TestPass#2026');
    final alphaTwo = s.memberships.firstWhere((m) => m['name'] == 'Alpha Two');
    final br = ((alphaTwo['branches'] as List).first as Map)['id'] as int;
    await s.select((alphaTwo['id'] as num).toInt(), br);
    expect(s.permissions!.readOnly, isTrue);
    expect(s.permissions!.can('expenses.create'), isFalse);
    await s.api.get('closing');
    await expectLater(
        s.api.post('expenses', {'date': s.permissions!.businessDate, 'category': 'Vegetable', 'amount': '10', 'mode': 'Cash'}),
        apiError(402));
    s.dispose();
  });
}
