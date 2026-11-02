import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api.dart';
import 'core/session.dart';
import 'core/theme.dart';
import 'screens/auth.dart';
import 'screens/shell.dart';
import 'widgets/common.dart';

/// API_BASE = full HTTPS URL of the server's `api/v1/index.php`, e.g. https://example.com/finance/api/v1/index.php
const apiBase = String.fromEnvironment('API_BASE');

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final api = Api(
    base: Uri.parse(apiBase),
    store: const SecureSessionStore(),
    allowInsecureLoopback: kDebugMode && const bool.fromEnvironment('ALLOW_LOCAL_HTTP'),
  );
  runApp(FinanceApp(session: Session(api)));
}

class FinanceApp extends StatefulWidget {
  const FinanceApp({super.key, required this.session});
  final Session session;
  @override
  State<FinanceApp> createState() => _FinanceAppState();
}

class _FinanceAppState extends State<FinanceApp> {
  Locale _locale = const Locale('en');
  late Future<void> _restore;

  @override
  void initState() {
    super.initState();
    _restore = widget.session.restore();
  }

  void _toggleLanguage() => setState(() => _locale = Locale(_locale.languageCode == 'en' ? 'hi' : 'en'));

  @override
  Widget build(BuildContext context) => MaterialApp(
        debugShowCheckedModeBanner: false,
        title: 'Finance 360',
        theme: financeTheme(),
        locale: _locale,
        supportedLocales: const [Locale('en'), Locale('hi')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: FutureBuilder<void>(
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
                return HomeShell(session: s, onLanguage: _toggleLanguage);
              },
            );
          },
        ),
      );
}
