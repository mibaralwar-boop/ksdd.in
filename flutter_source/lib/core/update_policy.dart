// Backend-controlled app update policy (V38 force-update CLIENT).
//
// The server decides: minimum supported versionCode, latest versionCode, mandatory or optional, title / message,
// destination (Play Store or a trusted direct APK link) and how long the policy may be cached. Nothing about the update
// URL is hard-coded in the app except WHICH HOSTS are trusted for a direct APK (build-time `UPDATE_TRUSTED_HOSTS`).
//
// Proposed endpoint (NOT on the server yet — see docs/V38_BACKEND_CHANGE_REQUESTS_LATER.md):
//   GET app/version-policy?platform=android&channel=play|direct&package=<applicationId>&version_code=<int>
// When the endpoint is missing (404) the app behaves exactly as before (no policy → no block). This is a client only;
// real enforcement needs the server to refuse outdated clients on write requests (documented backend change).
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api.dart';

enum UpdateDestination { playStore, externalApk }

class UpdatePolicy {
  UpdatePolicy({
    required this.minimumVersionCode,
    required this.latestVersionCode,
    required this.forceUpdate,
    required this.title,
    required this.message,
    required this.destination,
    required this.updateUrl,
    required this.apkSha256,
    required this.packageId,
    required this.revision,
    required this.fetchedAt,
    required this.maxAgeSeconds,
    required this.maintenance,
  });

  final int minimumVersionCode;
  final int latestVersionCode;
  final bool forceUpdate;
  final String title;
  final String message;
  final UpdateDestination destination;
  final String? updateUrl;
  final String? apkSha256;
  final String? packageId;
  final String revision;
  final DateTime fetchedAt;
  final int maxAgeSeconds;
  final bool maintenance;

  /// Parses the server JSON. Unknown / malformed values never produce a block: they produce `null` (= no policy).
  static UpdatePolicy? fromJson(Map<String, dynamic> j, {DateTime? now}) {
    int? i(Object? v) => v is int ? v : (v is String ? int.tryParse(v) : (v is num ? v.toInt() : null));
    final min = i(j['minimum_supported_version_code']);
    if (min == null || min < 0) return null;
    final dest = switch ((j['destination_type'] ?? '').toString()) {
      'play_store' || 'play' => UpdateDestination.playStore,
      'external_apk' || 'apk' || 'direct' => UpdateDestination.externalApk,
      _ => null,
    };
    if (dest == null) return null;
    return UpdatePolicy(
      minimumVersionCode: min,
      latestVersionCode: i(j['latest_version_code']) ?? min,
      forceUpdate: j['force_update'] == true || j['force_update'] == 1 || j['force_update'] == '1',
      title: (j['update_title'] ?? 'Update required').toString(),
      message: (j['update_message'] ?? 'A newer version of Finance 360 is required to continue.').toString(),
      destination: dest,
      updateUrl: (j['update_url'] as String?)?.trim(),
      apkSha256: (j['apk_sha256'] ?? j['sha256']) as String?,
      packageId: j['package_id'] as String?,
      revision: (j['policy_revision'] ?? j['config_version'] ?? '').toString(),
      fetchedAt: now ?? DateTime.now(),
      maxAgeSeconds: (i(j['max_cache_age']) ?? 21600).clamp(60, 7 * 86400),
      maintenance: j['maintenance_mode'] == true,
    );
  }

  Map<String, dynamic> toJson() => {
        'minimum_supported_version_code': minimumVersionCode,
        'latest_version_code': latestVersionCode,
        'force_update': forceUpdate,
        'update_title': title,
        'update_message': message,
        'destination_type': destination == UpdateDestination.playStore ? 'play_store' : 'external_apk',
        'update_url': updateUrl,
        'apk_sha256': apkSha256,
        'package_id': packageId,
        'policy_revision': revision,
        'max_cache_age': maxAgeSeconds,
        'maintenance_mode': maintenance,
        '_fetched_at': fetchedAt.toIso8601String(),
      };

  bool isFresh(DateTime now) => now.difference(fetchedAt).inSeconds <= maxAgeSeconds;
}

enum UpdateState { ok, optional, required }

/// Integer comparison of Android versionCode — never versionName strings.
UpdateState evaluate(UpdatePolicy? p, int currentVersionCode) {
  if (p == null) return UpdateState.ok;
  if (currentVersionCode < p.minimumVersionCode) return UpdateState.required;
  if (p.forceUpdate && currentVersionCode < p.latestVersionCode) return UpdateState.required;
  if (currentVersionCode < p.latestVersionCode) return UpdateState.optional;
  return UpdateState.ok;
}

/// Where the Update button may go. Returns the URIs to try in order, or an empty list when the policy's link is not
/// trusted (then the app shows "contact the owner" and still blocks if the update is mandatory).
List<Uri> updateTargets(UpdatePolicy p, {required String installedPackage, required Set<String> trustedApkHosts}) {
  if (p.packageId != null && p.packageId != installedPackage) return const []; // policy for another app (e.g. staging vs prod)
  if (p.destination == UpdateDestination.playStore) {
    // Only the listing of THIS installed package; a server URL may only confirm it, never redirect elsewhere.
    final u = p.updateUrl == null || p.updateUrl!.isEmpty ? null : Uri.tryParse(p.updateUrl!);
    if (u != null) {
      final ok = (u.scheme == 'market' && u.queryParameters['id'] == installedPackage) ||
          (u.scheme == 'https' && u.host == 'play.google.com' && u.queryParameters['id'] == installedPackage);
      if (!ok) return const [];
    }
    return [
      Uri.parse('market://details?id=$installedPackage'),
      Uri.parse('https://play.google.com/store/apps/details?id=$installedPackage'),
    ];
  }
  final u = Uri.tryParse(p.updateUrl ?? '');
  if (u == null || u.scheme != 'https' || u.host.isEmpty || u.userInfo.isNotEmpty) return const [];
  if (!trustedApkHosts.contains(u.host.toLowerCase())) return const [];
  if (u.hasPort && u.port != 443) return const [];
  return [u];
}

/// Policy cache (encrypted storage). A mandatory policy stays in force offline until it expires; an expired cache
/// is ignored (fail-open) so a temporary server outage cannot lock every user out forever.
abstract class PolicyCache {
  Future<String?> read();
  Future<void> write(String? v);
}

class SecurePolicyCache implements PolicyCache {
  const SecurePolicyCache();
  static const _s = FlutterSecureStorage();
  @override
  Future<String?> read() => _s.read(key: 'f360.update_policy');
  @override
  Future<void> write(String? v) => v == null ? _s.delete(key: 'f360.update_policy') : _s.write(key: 'f360.update_policy', value: v);
}

class MemoryPolicyCache implements PolicyCache {
  String? value;
  @override
  Future<String?> read() async => value;
  @override
  Future<void> write(String? v) async => value = v;
}

class UpdateCheckResult {
  UpdateCheckResult(this.policy, this.state, {this.fromCache = false, this.error});
  final UpdatePolicy? policy;
  final UpdateState state;
  final bool fromCache;
  final String? error; // shown with "Retry" when the check failed and no valid cache exists
}

class UpdateChecker {
  UpdateChecker({
    required this.api,
    required this.cache,
    required this.versionCode,
    required this.packageId,
    required this.channel,
    DateTime Function()? clock,
  }) : clock = clock ?? DateTime.now;

  final Api api;
  final PolicyCache cache;
  final int versionCode;
  final String packageId;
  final String channel; // play | direct
  final DateTime Function() clock;

  Future<UpdatePolicy?> _cached() async {
    try {
      final raw = await cache.read();
      if (raw == null) return null;
      final j = jsonDecode(raw) as Map<String, dynamic>;
      return UpdatePolicy.fromJson(j, now: DateTime.tryParse((j['_fetched_at'] ?? '').toString()));
    } catch (_) {
      return null;
    }
  }

  Future<UpdateCheckResult> check() async {
    final now = clock();
    try {
      final r = await api.publicGet('app/version-policy', {
        'platform': 'android',
        'channel': channel,
        'package': packageId,
        'version_code': '$versionCode',
      });
      final p = UpdatePolicy.fromJson((r['policy'] is Map ? r['policy'] as Map : r).cast<String, dynamic>(), now: now);
      await cache.write(p == null ? null : jsonEncode(p.toJson()));
      return UpdateCheckResult(p, evaluate(p, versionCode));
    } on ApiError catch (e) {
      if (e.status == 404 || e.status == 401 || e.status == 405) {
        // Server has no public update policy route (current V34 backend answers 401/404): nothing to enforce.
        await cache.write(null);
        return UpdateCheckResult(null, UpdateState.ok);
      }
      final c = await _cached();
      if (c != null && c.isFresh(now)) return UpdateCheckResult(c, evaluate(c, versionCode), fromCache: true);
      return UpdateCheckResult(null, UpdateState.ok, error: e.message);
    }
  }
}
