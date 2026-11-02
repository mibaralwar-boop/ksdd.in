import 'package:finance360/core/api.dart';
import 'package:finance360/core/format.dart';
import 'package:finance360/core/session.dart';
import 'package:finance360/screens/salary.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers.dart';

void main() {
  group('money', () {
    test('paise are shown with Indian digit grouping', () {
      expect(rupees(12345678), '₹1,23,456.78');
      expect(rupees(100), '₹1');
      expect(rupees(57465000), '₹5,74,650');
      expect(rupees(-50050), '−₹500.50');
      expect(rupees(null), '—');
    });
    test('typed rupees are validated and converted to paise exactly', () {
      expect(parseRupees('1,234.5'), 123450);
      expect(parseRupees('0'), 0);
      expect(parseRupees('10.05'), 1005);
      expect(parseRupees('1.234'), isNull);
      expect(parseRupees('abc'), isNull);
      expect(rupeeInput(123450), '1234.50');
      expect(rupeeInput(3000), '30');
    });
  });

  group('permission-only navigation (real server permissions)', () {
    test('Cash Counter sees only Daily Closing', () {
      final p = Permissions(fixture('select_cash_counter')['permissions'] as Map<String, dynamic>);
      expect(visibleModules(p), ['closing']);
      expect(p.home, 'closing');
    });
    test('Storekeeper sees purchase/stock modules and nothing about cash or salary', () {
      final p = Permissions(fixture('select_store')['permissions'] as Map<String, dynamic>);
      final m = visibleModules(p);
      expect(m, containsAll(['purchases', 'stock']));
      expect(m, isNot(contains('closing')));
      expect(m, isNot(contains('payroll')));
    });
    test('Owner gets dashboard first, then every server menu', () {
      final p = Permissions(fixture('select_owner')['permissions'] as Map<String, dynamic>);
      final m = visibleModules(p);
      expect(m.first, 'dashboard');
      expect(m, containsAll(['closing', 'payroll', 'purchases', 'reports']));
    });
  });

  group('salary Save & Next order', () {
    final lines = [for (final l in fixture('sheet')['lines'] as List) (l as Map).cast<String, dynamic>()];
    test('next pending employee stays in the same department', () {
      final first = lines.first;
      final next = nextPendingLine(lines, first['employee_id'] as int);
      expect(next, isNotNull);
      expect(next!['dept_id'], first['dept_id']);
      expect(next['employee_id'], isNot(first['employee_id']));
    });
    test('completed rows are skipped; none left → null', () {
      final dept = lines.first['dept_id'];
      final done = [
        for (final l in lines) l['dept_id'] == dept && l != lines.first ? {...l, 'entry_status': 'completed'} : l,
      ];
      expect(nextPendingLine(done, lines.first['employee_id'] as int), isNull);
    });
  });

  group('API client', () {
    Api make(FakeServer s) => Api(base: Uri.parse('https://x.example.test/api/v1/index.php'), store: MemorySessionStore(), client: s.client);

    test('refuses plain HTTP unless loopback is explicitly allowed', () {
      final s = FakeServer();
      expect(Api(base: Uri.parse('http://example.com/api/v1/index.php'), store: MemorySessionStore(), client: s.client).configured, isFalse);
      expect(Api(base: Uri.parse('http://10.0.2.2/api/v1/index.php'), store: MemorySessionStore(), client: s.client).configured, isFalse);
      expect(Api(base: Uri.parse('http://10.0.2.2/api/v1/index.php'), store: MemorySessionStore(), client: s.client, allowInsecureLoopback: true).configured,
          isTrue);
      expect(Api(base: Uri(), store: MemorySessionStore(), client: s.client).configured, isFalse);
    });

    test('login sends a stable device id; route goes in __path; POST carries Idempotency-Key', () async {
      final s = FakeServer()
        ..json('POST auth/login', {...tokens, 'user': {}, 'memberships': []})
        ..json('POST expenses', {'ok': true});
      final api = make(s);
      await api.restore();
      await api.login('a@b.test', 'pw');
      final body = s.lastBody('POST auth/login');
      expect((body['device_id'] as String).length, greaterThanOrEqualTo(8));
      await api.post('expenses', {'amount': '10'});
      final r = s.calls('POST expenses').single;
      expect(r.headers['Authorization'], 'Bearer ${tokens['access_token']}');
      expect(r.headers['Idempotency-Key'], matches(RegExp(r'^[A-Za-z0-9_:.-]{8,64}$')));
    });

    test('401 → exactly one refresh (even for parallel calls) → retry with new token', () async {
      final s = FakeServer();
      var refreshes = 0;
      s.json('POST auth/login', {...tokens, 'user': {}, 'memberships': []});
      s.on('POST auth/refresh', (req, b) {
        refreshes++;
        return (200, {'access_token': 'N' * 40, 'refresh_token': 'M' * 40});
      });
      s.on('GET dashboard', (req, b) => req.headers['Authorization'] == 'Bearer ${'N' * 40}' ? (200, {'ok': true}) : (401, {'error': 'expired', 'code': 'UNAUTHENTICATED'}));
      final api = make(s);
      await api.restore();
      await api.login('a@b.test', 'pw');
      final r = await Future.wait([api.get('dashboard'), api.get('dashboard'), api.get('dashboard')]);
      expect(r.every((x) => x['ok'] == true), isTrue);
      expect(refreshes, 1);
      expect(s.lastBody('POST auth/refresh')['device_id'], api.deviceId);
    });

    test('revoked refresh → session cleared and onSessionLost fires', () async {
      final s = FakeServer()
        ..json('POST auth/login', {...tokens, 'user': {}, 'memberships': []})
        ..json('POST auth/refresh', {'error': 'revoked', 'code': 'UNAUTHENTICATED'}, 401)
        ..json('GET dashboard', {'error': 'expired', 'code': 'UNAUTHENTICATED'}, 401);
      final api = make(s);
      var lost = false;
      api.onSessionLost = () => lost = true;
      await api.restore();
      await api.login('a@b.test', 'pw');
      await expectLater(api.get('dashboard'), throwsA(isA<ApiError>().having((e) => e.status, 'status', 401)));
      expect(lost, isTrue);
      expect(api.hasSession, isFalse);
    });

    test('server error codes and 409 needs are exposed', () async {
      final s = FakeServer()
        ..json('POST auth/login', {...tokens, 'user': {}, 'memberships': []})
        ..json('POST purchase-bills/submit', {'error': 'Rates changed', 'code': 'CONFLICT', 'needs': 'rates_ok', 'rate_changes': []}, 409)
        ..json('POST closing/save', {'error': 'Read only', 'code': 'SUBSCRIPTION_READ_ONLY'}, 402);
      final api = make(s);
      await api.restore();
      await api.login('a@b.test', 'pw');
      await expectLater(api.post('purchase-bills/submit', {}), throwsA(isA<ApiError>().having((e) => e.needs, 'needs', 'rates_ok')));
      await expectLater(api.post('closing/save', {}), throwsA(isA<ApiError>().having((e) => e.isReadOnly, 'readOnly', true)));
    });

    test('offline → network error; logout still clears local tokens', () async {
      final s = FakeServer()..json('POST auth/login', {...tokens, 'user': {}, 'memberships': []});
      final api = make(s);
      await api.restore();
      await api.login('a@b.test', 'pw');
      s.offline = true;
      await expectLater(api.get('dashboard'), throwsA(isA<ApiError>().having((e) => e.isNetwork, 'network', true)));
      await api.logout();
      expect(api.hasSession, isFalse);
    });
  });

  test('revoked membership on permission refresh → back to restaurant chooser', () async {
    final (session, server) = await signedInSession('select_owner');
    expect(session.inBranch, isTrue);
    server.json('GET permissions/me', {'error': 'Not allowed', 'code': 'FORBIDDEN'}, 403);
    server.json('GET auth/profile', {'user': {'id': 1}, 'memberships': [], 'selected': {}});
    await session.reloadPermissions();
    expect(session.inBranch, isFalse);
    expect(session.memberships, isEmpty);
    session.dispose();
  });
}
