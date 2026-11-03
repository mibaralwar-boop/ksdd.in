// App-wide session: who is signed in, which restaurant/branch is open, and the effective permissions
// returned by the server. The UI renders ONLY what `permissions.menus` / `actions` allow.
import 'dart:async';

import 'package:flutter/foundation.dart';

import 'api.dart';

class Subscription {
  Subscription(this.raw);
  final Map<String, dynamic> raw;
  String get state => (raw['state'] ?? 'none').toString();
  String get label => (raw['label'] ?? state).toString();
  bool get readOnly => raw['read_only'] == true;
  int? get daysLeft => (raw['days_left'] as num?)?.toInt();
  String get endsIst => (raw['period_end_ist'] ?? '').toString();
  String? get plan => raw['plan'] as String?;
  bool get trialing => state == 'trialing';
}

class Permissions {
  Permissions(this.raw);
  final Map<String, dynamic> raw;
  int get tenantId => (raw['tenant_id'] as num).toInt();
  int get branchId => (raw['branch_id'] as num).toInt();
  String get branchName => (raw['branch_name'] ?? '').toString();
  String get template => (raw['template'] ?? '').toString();
  List<String> get menus => [for (final m in (raw['menus'] as List? ?? const [])) m.toString()];
  Set<String> get actions => {for (final a in (raw['actions'] as List? ?? const [])) a.toString()};
  String get home => (raw['home'] ?? '').toString();
  bool get readOnly => raw['read_only'] == true;
  Subscription get subscription => Subscription((raw['subscription'] as Map?)?.cast<String, dynamic>() ?? const {});
  String get businessDate => (raw['business_date'] ?? '').toString();
  String get closingTargetDate => (raw['closing_target_date'] ?? '').toString();
  String get version => (raw['perm_version'] ?? '').toString();
  int get refreshAfterSeconds => (raw['refresh_after_seconds'] as num?)?.toInt() ?? 300;
  bool can(String action) => actions.contains(action);
}

/// Screens the Android app implements natively. A server menu that is not in this list is shown as
/// "available on the website" — never as a fake screen.
const nativeModules = ['dashboard', 'closing', 'payroll', 'purchases', 'stock', 'staff', 'expenses', 'reports'];

List<String> visibleModules(Permissions p) => [
      if (p.can('dashboard.view')) 'dashboard',
      for (final m in p.menus)
        if (m != 'dashboard') m,
    ];

class Session extends ChangeNotifier {
  Session(this.api) {
    api.onSessionLost = () {
      _reset();
      notifyListeners();
    };
  }
  final Api api;

  Map<String, dynamic>? user;
  List<Map<String, dynamic>> memberships = [];
  Permissions? permissions;
  Timer? _timer;
  bool restoring = true;

  bool get signedIn => api.hasSession && user != null;
  bool get inBranch => signedIn && permissions != null;

  Map<String, dynamic>? get tenant {
    final p = permissions;
    if (p == null) return null;
    for (final m in memberships) {
      if ((m['id'] as num).toInt() == p.tenantId) return m;
    }
    return null;
  }

  String get restaurantName => (tenant?['name'] ?? '').toString();

  void _reset() {
    _timer?.cancel();
    user = null;
    memberships = [];
    permissions = null;
  }

  Future<void> restore() async {
    restoring = true;
    try {
      await api.restore();
      if (!api.hasSession) return;
      final prof = await api.get('auth/profile');
      user = (prof['user'] as Map).cast<String, dynamic>();
      memberships = _list(prof['memberships']);
      final sel = (prof['selected'] as Map?)?.cast<String, dynamic>();
      if (sel != null && sel['branch_id'] != null) await reloadPermissions();
    } on ApiError catch (e) {
      if (!e.isNetwork) {
        await api.clearSession();
        _reset();
      } else {
        rethrow;
      }
    } finally {
      restoring = false;
      notifyListeners();
    }
  }

  static List<Map<String, dynamic>> _list(Object? v) => [for (final x in (v as List? ?? const [])) (x as Map).cast<String, dynamic>()];

  Future<void> login(String email, String password) async {
    final r = await api.login(email, password);
    user = (r['user'] as Map).cast<String, dynamic>();
    memberships = _list(r['memberships']);
    permissions = null;
    // One restaurant with one branch: open it directly.
    if (memberships.length == 1) {
      final b = _list(memberships.first['branches']);
      if (b.length == 1) {
        await select((memberships.first['id'] as num).toInt(), (b.first['id'] as num).toInt());
        return;
      }
    }
    notifyListeners();
  }

  Future<void> select(int tenantId, int branchId) async {
    final r = await api.selectBranch(tenantId, branchId);
    _setPermissions((r['permissions'] as Map).cast<String, dynamic>());
  }

  void _setPermissions(Map<String, dynamic> raw) {
    permissions = Permissions(raw);
    _timer?.cancel();
    _timer = Timer.periodic(Duration(seconds: permissions!.refreshAfterSeconds.clamp(30, 3600)), (_) {
      reloadPermissions().catchError((_) {});
    });
    notifyListeners();
  }

  /// Re-read effective permissions (on resume, on a timer, and after any 403). Revocation shows immediately.
  Future<void> reloadPermissions() async {
    try {
      final r = await api.get('permissions/me');
      if (permissions == null || r['perm_version'] != permissions!.version || r['read_only'] != permissions!.readOnly) {
        _setPermissions(r);
      }
    } on ApiError catch (e) {
      if (e.isForbidden || e.status == 409) {
        // Membership removed or branch closed: back to the restaurant chooser.
        _timer?.cancel();
        permissions = null;
        final prof = await api.get('auth/profile');
        memberships = _list(prof['memberships']);
        notifyListeners();
      } else {
        rethrow;
      }
    }
  }

  void leaveBranch() {
    _timer?.cancel();
    permissions = null;
    notifyListeners();
  }

  Future<void> logout() async {
    await api.logout();
    _reset();
    notifyListeners();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
