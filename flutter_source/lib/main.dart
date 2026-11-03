import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'core/api.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'core/update_policy.dart';
import 'screens/auth.dart';
import 'screens/shell.dart';
import 'screens/update.dart';
import 'widgets/common.dart';

/// API_BASE = full HTTPS URL of the server's `api/v1/index.php`, e.g. https://example.com/finance/api/v1/index.php
const apiBase = String.fromEnvironment('API_BASE');

/// Distribution channel of THIS build: `play` (installed from Google Play) or `direct` (official APK link).
const distChannel = String.fromEnvironment('DIST_CHANNEL', defaultValue: 'direct');

/// Hosts trusted for a direct APK download link sent by the server (comma separated). The URL itself is never built in.
const trustedHosts = String.fromEnvironment('UPDATE_TRUSTED_HOSTS', defaultValue: 'ksdd.in,staging.ksdd.in');

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final api = Api(
    base: Uri.parse(apiBase),
    store: const SecureSessionStore(),
    allowInsecureLoopback: kDebugMode && const bool.fromEnvironment('ALLOW_LOCAL_HTTP'),
  );
  final info = await PackageInfo.fromPlatform();
  final code = int.tryParse(info.buildNumber) ?? 0;
  api.clientHeaders = {
    'X-App-Platform': 'android',
    'X-App-Package': info.packageName,
    'X-App-Version-Code': '$code',
    'X-App-Version-Name': info.version,
    'X-App-Channel': distChannel,
  };
  final updates = UpdateController(
    checker:
        UpdateChecker(api: api, cache: const SecurePolicyCache(), versionCode: code, packageId: info.packageName, channel: distChannel),
    trustedApkHosts: {
      for (final h in trustedHosts.split(','))
        if (h.trim().isNotEmpty) h.trim().toLowerCase()
    },
  );
  api.writesBlocked = () => updates.required;
  updates.start();
  runApp(FinanceApp(
      session: Session(api), updates: updates, versionLabel: '${info.version} (${info.buildNumber})', prefs: const SecurePrefs()));
}

/// Small persisted preferences (language).
abstract class Prefs {
  Future<String?> read(String k);
  Future<void> write(String k, String v);
}

class SecurePrefs implements Prefs {
  const SecurePrefs();
  static const _s = FlutterSecureStorage();
  @override
  Future<String?> read(String k) => _s.read(key: 'f360.pref.$k');
  @override
  Future<void> write(String k, String v) => _s.write(key: 'f360.pref.$k', value: v);
}

class FinanceApp extends StatefulWidget {
  const FinanceApp({super.key, required this.session, this.updates, this.versionLabel = '', this.prefs});
  final Session session;
  final UpdateController? updates;
  final String versionLabel;
  final Prefs? prefs;
  @override
  State<FinanceApp> createState() => _FinanceAppState();
}

class _FinanceAppState extends State<FinanceApp> {
  Locale _locale = const Locale('en');
  late Future<void> _restore;
  final _nav = GlobalKey<NavigatorState>();
  bool _wasBlocked = false;
  bool _wasSignedIn = false;

  @override
  void initState() {
    super.initState();
    _restore = widget.session.restore();
    widget.prefs?.read('lang').then((v) {
      if (v == 'hi' && mounted) setState(() => _locale = const Locale('hi'));
    });
    widget.updates?.addListener(_onUpdate);
    widget.session.addListener(_onSession);
  }

  @override
  void dispose() {
    widget.updates?.removeListener(_onUpdate);
    widget.session.removeListener(_onSession);
    super.dispose();
  }

  void _onSession() {
    final now = widget.session.signedIn;
    if (now && !_wasSignedIn) widget.updates?.check(); // re-check right after sign-in
    _wasSignedIn = now;
  }

  void _onUpdate() {
    final blocked = widget.updates?.required ?? false;
    if (blocked && !_wasBlocked) {
      // Close every open page so nothing (forms, payment sheets, deep links) stays reachable behind the block.
      _nav.currentState?.popUntil((r) => r.isFirst);
    }
    _wasBlocked = blocked;
    setState(() {});
  }

  void _toggleLanguage() {
    setState(() => _locale = Locale(_locale.languageCode == 'en' ? 'hi' : 'en'));
    widget.prefs?.write('lang', _locale.languageCode);
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
        navigatorKey: _nav,
        debugShowCheckedModeBanner: false,
        title: 'Finance 360',
        theme: financeTheme(),
        locale: _locale,
        supportedLocales: const [Locale('en'), Locale('hi')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: (widget.updates?.required ?? false)
            ? UpdateRequiredScreen(
                controller: widget.updates!,
                currentVersion: widget.versionLabel,
                onSignOut: widget.session.signedIn ? widget.session.logout : null,
              )
            : FutureBuilder<void>(
                future: _restore,
                builder: (context, snap) {
                  if (snap.connectionState != ConnectionState.done) {
                    return const Scaffold(body: Center(child: CircularProgressIndicator()));
                  }
                  if (snap.hasError) {
                    return Scaffold(
                      body: ErrorPanel(error: snap.error!, onRetry: () => setState(() => _restore = widget.session.restore())),
                    );
                  }
                  return ListenableBuilder(
                    listenable: widget.session,
                    builder: (context, _) {
                      final s = widget.session;
                      if (!s.signedIn) return SignInScreen(session: s, onLanguage: _toggleLanguage);
                      if (!s.inBranch) return ChooseBranchScreen(session: s);
                      return HomeShell(session: s, onLanguage: _toggleLanguage, updates: widget.updates);
                    },
                  );
                },
              ),
      );
}
