// LIVE integration test: the app's own Api client against a REAL Finance 360 V29 PHP server + MariaDB.
// Run with tool/live_test.sh (builds disposable staging databases with demo data). Skipped when F360_LIVE_BASE is unset.
@TestOn('vm')
library;

import 'dart:convert';
import 'dart:io';

import 'package:finance360/core/api.dart';
import 'package:finance360/core/session.dart';
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
      c = await s.api.post('closing/save', {'date': date, 'version': c['version'], 'step': 4, 'cash_sale': '25000', 'card_sale': '3000', 'upi_sale': '4000.50'});
      expect((c['sales'] as Map)['total'], 3200050);
      // The server refuses "No expenses" when expenses exist for the day; the user must review them instead.
      final hasExpenses = (c['expenses'] as List).isNotEmpty;
      if (hasExpenses) {
        final bad = await s.api.post('closing/save', {'date': date, 'version': c['version'], 'step': 5, 'expenses_confirmed': 'none'});
        await expectLater(s.api.post('closing/finalize', {'date': date, 'version': bad['version'], 'confirm': true}), throwsA(isA<ApiError>()));
        c = bad;
      }
      c = await s.api.post('closing/save', {'date': date, 'version': c['version'], 'step': 5, 'expenses_confirmed': hasExpenses ? 'reviewed' : 'none'});
      // Wrong version → 409, nothing overwritten
      await expectLater(s.api.post('closing/save', {'date': date, 'version': (c['version'] as int) - 1, 'step': 6, 'actual_cash': '1'}), apiError(409));
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
          s.api.post('closing/save', {'date': date, 'version': after['version'], 'step': 4, 'cash_sale': '1', 'card_sale': '0', 'upi_sale': '0'}),
          throwsA(isA<ApiError>()));
    });

    test('optional daybook photo: JPEG accepted after finalising; PDF refused', () async {
      final c = await s.api.get('closing', {'date': s.permissions!.closingTargetDate});
      final date = c['date'] as String;
      final bytes = File(jpegPath!).readAsBytesSync();
      await s.api.post('closing/photos', {'date': date, 'file': {'name': 'daybook.jpg', 'data': base64Encode(bytes)}});
      final list = await s.api.get('closing/photos', {'date': date});
      expect((list['photos'] as List).length, 1);
      await expectLater(
          s.api.post('closing/photos', {'date': date, 'file': {'name': 'x.pdf', 'data': base64Encode(utf8.encode('%PDF-1.4 fake'))}}),
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
      final body = {'period': period, 'employee_id': emp, 'version': line['version'], 'present': '28', 'half_days': '2', 'paid_leave': '', 'rest_days': '', 'advance_recovery': '0', 'penalty_ids': <int>[]};
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

  test('read-only subscription (second restaurant, no trial): reads work, writes get 402', () async {
    final s = await signIn('ownera@example.test', 'TestPass#2026');
    final alphaTwo = s.memberships.firstWhere((m) => m['name'] == 'Alpha Two');
    final br = ((alphaTwo['branches'] as List).first as Map)['id'] as int;
    await s.select((alphaTwo['id'] as num).toInt(), br);
    expect(s.permissions!.readOnly, isTrue);
    expect(s.permissions!.can('expenses.create'), isFalse);
    await s.api.get('closing');
    await expectLater(
        s.api.post('expenses', {'date': s.permissions!.businessDate, 'category': 'Vegetable', 'amount': '10', 'mode': 'Cash'}), apiError(402));
    s.dispose();
  });
}
