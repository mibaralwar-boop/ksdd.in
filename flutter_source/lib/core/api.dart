import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiFailure implements Exception {
  const ApiFailure(this.code, this.message, this.status);
  final String code;
  final String message;
  final int status;
  @override
  String toString() => message;
}

abstract class TokenVault {
  Future<Map<String, dynamic>?> read();
  Future<void> write(Map<String, dynamic> tokens);
  Future<void> clear();
}

class SecureTokenVault implements TokenVault {
  const SecureTokenVault();
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );
  @override
  Future<Map<String, dynamic>?> read() async {
    final value = await _storage.read(key: 'finance360.session.v1');
    return value == null ? null : jsonDecode(value) as Map<String, dynamic>;
  }
  @override
  Future<void> write(Map<String, dynamic> tokens) => _storage.write(
      key: 'finance360.session.v1', value: jsonEncode(tokens));
  @override
  Future<void> clear() => _storage.delete(key: 'finance360.session.v1');
}

class FinanceApi {
  FinanceApi({required this.endpoint, required this.vault, http.Client? client})
      : client = client ?? http.Client();
  final Uri endpoint;
  final TokenVault vault;
  final http.Client client;
  Map<String, dynamic>? _tokens;
  Future<void>? _refreshing;
  int? tenantId;
  int? branchId;

  bool get signedIn => _tokens != null;
  bool get configured => endpoint.host.isNotEmpty &&
      (endpoint.scheme == 'https' || (kDebugMode &&
      const bool.fromEnvironment('ALLOW_LOCAL_HTTP') &&
      endpoint.scheme == 'http' &&
      ['localhost', '127.0.0.1', '10.0.2.2'].contains(endpoint.host)));

  Future<void> restore() async {
    try { _tokens = await vault.read(); }
    catch (_) { await clear(); }
  }
  Future<void> clear() async {
    _tokens = null; tenantId = null; branchId = null;
    await vault.clear();
  }
  void selectScope(int tenant, int branch) {
    tenantId = tenant; branchId = branch;
  }
  Uri _url(String route) => endpoint.replace(
      queryParameters: {...endpoint.queryParameters, 'route': '/v1/$route'});

  Future<Map<String, dynamic>> _decode(http.Response response) async {
    Map<String, dynamic> body;
    try { body = jsonDecode(response.body) as Map<String, dynamic>; }
    catch (_) { throw ApiFailure('invalid_response', 'The server returned an unreadable response.', response.statusCode); }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final error = body['error'] as Map<String, dynamic>? ?? {};
      throw ApiFailure(error['code'] as String? ?? 'request_failed',
          error['message'] as String? ?? 'The request failed.', response.statusCode);
    }
    return body['data'] as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> request(String route,
      {Map<String, dynamic>? body, bool authenticated = true, bool retry = true}) async {
    if (!configured) throw const ApiFailure('not_configured', 'A secure Finance 360 server address must be configured for this build.', 0);
    if (authenticated && _tokens == null) throw const ApiFailure('unauthenticated', 'Please sign in.', 401);
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (authenticated) {
      headers['Authorization'] = 'Bearer ${_tokens!['access_token']}';
      if (tenantId != null) headers['X-Tenant-ID'] = '$tenantId';
      if (branchId != null) headers['X-Branch-ID'] = '$branchId';
    }
    try {
      final response = await (body == null
          ? client.get(_url(route), headers: headers)
          : client.post(_url(route), headers: headers, body: jsonEncode(body)))
          .timeout(const Duration(seconds: 25));
      if (response.statusCode == 401 && authenticated && retry) {
        await _refresh();
        return await request(route, body: body, authenticated: true, retry: false);
      }
      return await _decode(response);
    } on TimeoutException {
      throw const ApiFailure('timeout', 'The connection timed out. Check the saved result before retrying an action.', 0);
    } on http.ClientException {
      throw const ApiFailure('network', 'Could not reach the server. Please check your connection.', 0);
    }
  }

  Future<void> _refresh() {
    if (_refreshing != null) return _refreshing!;
    final completer = Completer<void>();
    _refreshing = completer.future;
    () async {
      try {
        final token = _tokens?['refresh_token'];
        if (token == null) throw const ApiFailure('unauthenticated', 'Please sign in.', 401);
        final next = await request('refresh', body: {'refresh_token': token}, authenticated: false);
        await vault.write(next); _tokens = next; completer.complete();
      } catch (error, stack) {
        await clear(); completer.completeError(error, stack);
      } finally { _refreshing = null; }
    }();
    return completer.future;
  }

  Future<void> login(String email, String password) async {
    final tokens = await request('login', body: {'email': email, 'password': password}, authenticated: false);
    await vault.write(tokens); _tokens = tokens;
  }
  Future<void> register(Map<String, dynamic> data) async {
    final tokens = await request('register', body: data, authenticated: false);
    await vault.write(tokens); _tokens = tokens;
  }
  Future<void> logout() async {
    try { if (signedIn) await request('logout', body: {}); }
    finally { await clear(); }
  }
  void close() => client.close();
}
