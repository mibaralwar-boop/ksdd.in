import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:finance360/core/api.dart';
import 'package:finance360/core/session.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Responses captured from the real V29 backend on a local staging server (demo data only).
Map<String, dynamic> fixture(String name) => jsonDecode(File('test/fixtures/$name.json').readAsStringSync()) as Map<String, dynamic>;

typedef Handler = FutureOr<(int, Object)> Function(http.Request req, Map<String, dynamic> body);

/// In-process fake of `api/v1/index.php`: routes are "METHOD path" (the `__path` query parameter).
class FakeServer {
  final routes = <String, Handler>{};
  final log = <http.Request>[];
  bool offline = false;

  late final http.Client client = MockClient((req) async {
    log.add(req);
    if (offline) throw const SocketException('offline');
    final path = req.url.queryParameters['__path'];
    final h = routes['${req.method} $path'];
    if (h == null) return _json(404, {'error': 'Not found', 'code': 'NOT_FOUND'});
    final body = req.body.isEmpty ? <String, dynamic>{} : jsonDecode(req.body) as Map<String, dynamic>;
    final (status, out) = await h(req, body);
    return _json(status, out);
  });

  static http.Response _json(int s, Object b) =>
      http.Response.bytes(utf8.encode(jsonEncode(b)), s, headers: {'content-type': 'application/json; charset=utf-8'});

  void on(String key, Handler h) => routes[key] = h;
  void json(String key, Object body, [int status = 200]) => routes[key] = (_, __) => (status, body);

  List<http.Request> calls(String key) => log.where((r) => '${r.method} ${r.url.queryParameters['__path']}' == key).toList();
  Map<String, dynamic> lastBody(String key) => jsonDecode(calls(key).last.body) as Map<String, dynamic>;
}

final tokens = {'access_token': 'A' * 40, 'refresh_token': 'R' * 40, 'token_type': 'Bearer', 'expires_in': 900};

/// A signed-in session inside one branch, using [selectFixture] for permissions.
Future<(Session, FakeServer)> signedInSession(String selectFixture,
    {void Function(FakeServer s)? routes, Map<String, dynamic>? overridePerms}) async {
  final server = FakeServer();
  final sel = fixture(selectFixture);
  final perms = {...(sel['permissions'] as Map<String, dynamic>), ...?overridePerms};
  final tenantId = perms['tenant_id'];
  final branchId = perms['branch_id'];
  final memberships = [
    {
      'id': tenantId,
      'name': 'Khadak Singh Da Dhaba',
      'role': 'member',
      'status': 'active',
      'branches': [
        {'id': branchId, 'name': perms['branch_name'], 'primary': true, 'template': perms['template']},
      ],
      'subscription': perms['subscription'],
    }
  ];
  server.json('GET auth/profile', {
    'user': {'id': 1, 'name': 'Test User', 'email': 'user@example.test'},
    'memberships': memberships,
    'selected': {'tenant_id': tenantId, 'branch_id': branchId},
  });
  server.json('POST auth/login', {
    ...tokens,
    'user': {'id': 1, 'name': 'Test User', 'email': 'user@example.test'},
    'memberships': [
      {
        'id': tenantId,
        'name': 'Khadak Singh Da Dhaba',
        'role': 'member',
        'status': 'active',
        'branches': [
          {'id': branchId, 'name': perms['branch_name'], 'primary': true, 'template': perms['template']},
        ],
        'subscription': perms['subscription'],
      }
    ],
  });
  server.json('POST tenants/select', {...tokens, 'permissions': perms});
  server.json('GET permissions/me', perms);
  routes?.call(server);
  final api = Api(base: Uri.parse('https://staging.example.test/api/v1/index.php'), store: MemorySessionStore(), client: server.client);
  final session = Session(api);
  await api.restore();
  await session.login('user@example.test', 'secret-password');
  return (session, server);
}
