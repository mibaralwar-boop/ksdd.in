// Finance 360 REST client for the V29 backend (`api/v1/index.php`, see backend_v29/docs/openapi.yaml).
//
// Rules taken from the API contract:
// * Access token 15 min + rotating refresh token bound to a device id.
// * The restaurant/branch context lives on the SERVER token (POST tenants/select). Finance calls never
//   send tenant or branch ids.
// * Every POST carries an Idempotency-Key so a retry after a lost reply cannot double-post.
// * Money is integer paise; errors are `{error, code, needs?, ...}`.
import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiError implements Exception {
  ApiError(this.status, this.code, this.message, [Map<String, dynamic>? data]) : data = data ?? const {};
  final int status;
  final String code;
  final String message;
  final Map<String, dynamic> data;

  /// Server asks the user to confirm something and repeat the call (e.g. `rates_ok`, `dup_reason`).
  String? get needs => data['needs'] as String?;
  bool get isNetwork => status == 0;
  bool get isReadOnly => status == 402;
  bool get isForbidden => status == 403;
  bool get isConflict => status == 409;

  @override
  String toString() => message;
}

/// Persistent session values. Implemented with encrypted storage on the device, in memory for tests.
abstract class SessionStore {
  Future<Map<String, String>> readAll();
  Future<void> write(String key, String? value);
}

class SecureSessionStore implements SessionStore {
  const SecureSessionStore();
  static const _s = FlutterSecureStorage();
  static const _keys = ['device_id', 'access', 'refresh'];
  @override
  Future<Map<String, String>> readAll() async {
    final out = <String, String>{};
    for (final k in _keys) {
      final v = await _s.read(key: 'f360.$k');
      if (v != null) out[k] = v;
    }
    return out;
  }

  @override
  Future<void> write(String key, String? value) =>
      value == null ? _s.delete(key: 'f360.$key') : _s.write(key: 'f360.$key', value: value);
}

class MemorySessionStore implements SessionStore {
  final Map<String, String> values = {};
  @override
  Future<Map<String, String>> readAll() async => Map.of(values);
  @override
  Future<void> write(String key, String? value) async =>
      value == null ? values.remove(key) : values[key] = value;
}

String randomKey([int bytes = 16]) {
  final r = Random.secure();
  return List.generate(bytes, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
}

class Api {
  Api({required this.base, required this.store, http.Client? client, this.allowInsecureLoopback = false})
      : client = client ?? http.Client();

  /// Full URL of `api/v1/index.php` (the route goes in `__path`, which works with or without URL rewriting).
  final Uri base;
  final SessionStore store;
  final http.Client client;

  /// Plain HTTP is accepted only for loopback / emulator hosts and only when the build explicitly allows it.
  final bool allowInsecureLoopback;

  String? _access;
  String? _refresh;
  String? _deviceId;
  Future<bool>? _refreshing;

  /// Called when the server rejects the session for good (refresh failed / revoked).
  void Function()? onSessionLost;

  bool get configured =>
      base.host.isNotEmpty &&
      (base.scheme == 'https' ||
          (allowInsecureLoopback &&
              base.scheme == 'http' &&
              const ['localhost', '127.0.0.1', '10.0.2.2'].contains(base.host)));
  bool get hasSession => _access != null && _refresh != null;
  String get deviceId => _deviceId ?? '';

  Future<void> restore() async {
    final v = await store.readAll();
    _deviceId = v['device_id'];
    if (_deviceId == null || _deviceId!.length < 8) {
      _deviceId = 'and-${randomKey(12)}';
      await store.write('device_id', _deviceId);
    }
    _access = v['access'];
    _refresh = v['refresh'];
  }

  Future<void> _saveTokens(Map<String, dynamic> t) async {
    _access = t['access_token'] as String?;
    _refresh = t['refresh_token'] as String?;
    await store.write('access', _access);
    await store.write('refresh', _refresh);
  }

  Future<void> clearSession() async {
    _access = null;
    _refresh = null;
    await store.write('access', null);
    await store.write('refresh', null);
  }

  Uri url(String path, [Map<String, String>? query]) =>
      base.replace(queryParameters: {...base.queryParameters, '__path': path, ...?query});

  // ---------------------------------------------------------------- auth
  Future<Map<String, dynamic>> login(String email, String password) async {
    final r = await _send('POST', 'auth/login',
        body: {'email': email.trim(), 'password': password, 'device_id': deviceId, 'device_name': 'Android app'},
        auth: false);
    await _saveTokens(r);
    return r;
  }

  /// Choose restaurant + branch. The server returns a NEW token pair bound to that context + permissions.
  Future<Map<String, dynamic>> selectBranch(int tenantId, int branchId) async {
    final r = await post('tenants/select', {'tenant_id': tenantId, 'branch_id': branchId});
    await _saveTokens(r);
    return r;
  }

  Future<void> logout() async {
    try {
      if (hasSession) await post('auth/logout', {});
    } catch (_) {
      // Signing out locally must work offline too.
    } finally {
      await clearSession();
    }
  }

  Future<bool> _doRefresh() async {
    final rt = _refresh;
    if (rt == null) return false;
    try {
      final r = await _send('POST', 'auth/refresh', body: {'refresh_token': rt, 'device_id': deviceId}, auth: false);
      await _saveTokens(r);
      return true;
    } on ApiError catch (e) {
      if (e.isNetwork) rethrow;
      await clearSession();
      return false;
    }
  }

  /// Serialises refreshes: a rotating refresh token must never be presented twice (that revokes the family).
  Future<bool> refreshTokens() {
    final running = _refreshing;
    if (running != null) return running;
    final f = _doRefresh();
    _refreshing = f;
    return f.whenComplete(() => _refreshing = null);
  }

  // ---------------------------------------------------------------- calls
  Future<Map<String, dynamic>> get(String path, [Map<String, String>? query]) => _authed('GET', path, query: query);

  /// POST with an Idempotency-Key. Pass the same [idem] when the user retries the same action.
  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body, {String? idem}) =>
      _authed('POST', path, body: body, idem: idem ?? randomKey());

  Future<Map<String, dynamic>> _authed(String method, String path,
      {Map<String, String>? query, Map<String, dynamic>? body, String? idem}) async {
    if (!hasSession) throw ApiError(401, 'UNAUTHENTICATED', 'Please sign in.');
    try {
      return await _send(method, path, query: query, body: body, idem: idem);
    } on ApiError catch (e) {
      if (e.status != 401) rethrow;
      if (await refreshTokens()) return _send(method, path, query: query, body: body, idem: idem);
      onSessionLost?.call();
      throw ApiError(401, 'UNAUTHENTICATED', 'Your session ended. Please sign in again.');
    }
  }

  Future<Map<String, dynamic>> publicGet(String path) => _send('GET', path, auth: false);
  Future<Map<String, dynamic>> publicPost(String path, Map<String, dynamic> body) =>
      _send('POST', path, body: body, auth: false);

  Future<Map<String, dynamic>> _send(String method, String path,
      {Map<String, String>? query, Map<String, dynamic>? body, bool auth = true, String? idem}) async {
    if (!configured) {
      throw ApiError(0, 'NOT_CONFIGURED', 'A secure Finance 360 server address must be configured for this build.');
    }
    final headers = <String, String>{'Accept': 'application/json'};
    if (auth && _access != null) headers['Authorization'] = 'Bearer $_access';
    if (body != null) headers['Content-Type'] = 'application/json';
    if (idem != null) headers['Idempotency-Key'] = idem;
    http.Response res;
    try {
      final u = url(path, query);
      final f = method == 'GET' ? client.get(u, headers: headers) : client.post(u, headers: headers, body: jsonEncode(body ?? {}));
      res = await f.timeout(const Duration(seconds: 40));
    } on TimeoutException {
      throw ApiError(0, 'TIMEOUT', 'The server did not answer in time. Check whether the entry was saved before trying again.');
    } on Exception {
      throw ApiError(0, 'NETWORK', 'No internet connection or the server cannot be reached.');
    }
    Map<String, dynamic> json;
    try {
      final d = jsonDecode(utf8.decode(res.bodyBytes));
      json = d is Map<String, dynamic> ? d : <String, dynamic>{'data': d};
    } catch (_) {
      throw ApiError(res.statusCode, 'BAD_RESPONSE', 'The server sent an unreadable answer (HTTP ${res.statusCode}).');
    }
    if (res.statusCode >= 200 && res.statusCode < 300) return json;
    throw ApiError(res.statusCode, (json['code'] ?? 'ERROR').toString(),
        (json['error'] ?? 'Request failed (HTTP ${res.statusCode}).').toString(), json);
  }

  void close() => client.close();
}
