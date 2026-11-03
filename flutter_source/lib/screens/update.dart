import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/format.dart';
import '../core/update_policy.dart';
import '../widgets/common.dart';

/// Holds the current update decision; re-checks on start, after sign-in, on resume and every [interval].
class UpdateController extends ChangeNotifier with WidgetsBindingObserver {
  UpdateController({
    required this.checker,
    required this.trustedApkHosts,
    this.interval = const Duration(minutes: 30),
    this.launcher = _launch,
  });

  final UpdateChecker checker;
  final Set<String> trustedApkHosts;
  final Duration interval;
  final Future<bool> Function(Uri) launcher;
  UpdateCheckResult? last;
  bool checking = false;
  Timer? _timer;
  bool dismissedOptional = false;

  bool get required => last?.state == UpdateState.required;
  bool get optional => last?.state == UpdateState.optional && !dismissedOptional;

  static Future<bool> _launch(Uri u) => launchUrl(u, mode: LaunchMode.externalApplication);

  void start() {
    WidgetsBinding.instance.addObserver(this);
    _timer = Timer.periodic(interval, (_) => check());
    check();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) check();
  }

  Future<void> check() async {
    if (checking) return;
    checking = true;
    notifyListeners();
    try {
      final r = await checker.check();
      // A failed check never lifts a block that is still valid in the cache (handled by the checker), and never
      // creates one: `r.state` is ok when nothing trustworthy is known.
      last = r;
    } finally {
      checking = false;
      notifyListeners();
    }
  }

  void dismissOptional() {
    dismissedOptional = true;
    notifyListeners();
  }

  List<Uri> targets() =>
      last?.policy == null ? const [] : updateTargets(last!.policy!, installedPackage: checker.packageId, trustedApkHosts: trustedApkHosts);

  /// Opens the first target that Android accepts. Returns false when no trusted target exists or none could open.
  Future<bool> openUpdate() async {
    for (final u in targets()) {
      try {
        if (await launcher(u)) return true;
      } catch (_) {}
    }
    return false;
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}

/// Non-dismissible mandatory update page. Back / deep links cannot leave it (it replaces the whole app);
/// Sign out and Retry remain available.
class UpdateRequiredScreen extends StatelessWidget {
  const UpdateRequiredScreen({super.key, required this.controller, required this.currentVersion, this.onSignOut});
  final UpdateController controller;
  final String currentVersion;
  final Future<void> Function()? onSignOut;

  @override
  Widget build(BuildContext context) {
    final p = controller.last?.policy;
    final targets = controller.targets();
    return PopScope(
      canPop: false,
      child: Scaffold(
        body: SafeArea(
          child: ListView(padding: const EdgeInsets.all(24), children: [
            const SizedBox(height: 24),
            const CircleAvatar(
                radius: 40, backgroundColor: brandPeach, child: Icon(Icons.system_update_rounded, size: 44, color: brandOrange)),
            const SizedBox(height: 18),
            Text(p?.title ?? 'Update required', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 10),
            Text(p?.message ?? '', textAlign: TextAlign.center, style: const TextStyle(height: 1.45)),
            const SizedBox(height: 14),
            SoftCard(
              child: Column(children: [
                SumRow('Installed version', currentVersion),
                if (p != null) SumRow('Minimum required build', '${p.minimumVersionCode}'),
                if (p != null)
                  SumRow('Update from', p.destination == UpdateDestination.playStore ? 'Google Play' : 'Official download link'),
              ]),
            ),
            const SizedBox(height: 10),
            const Banner2('Your records are safe on the server. Nothing can be saved from this old version until it is updated.',
                icon: Icons.lock_outline_rounded),
            if (p?.destination == UpdateDestination.externalApk && targets.isNotEmpty)
              const Banner2('Android will ask you to allow the install. The app cannot install itself silently. '
                  'Install only from the link this button opens.'),
            if (targets.isEmpty)
              const Banner2(
                  'The update link from the server is not a trusted address, so the app will not open it. '
                  'Please contact the restaurant owner or Finance 360 support.',
                  color: brandRedBg,
                  icon: Icons.gpp_bad_outlined),
            const SizedBox(height: 16),
            FilledButton.icon(
              icon: const Icon(Icons.download_rounded),
              label: const Text('Update now'),
              onPressed: targets.isEmpty
                  ? null
                  : () async {
                      final ok = await controller.openUpdate();
                      if (!ok && context.mounted) showMessage(context, 'Could not open the update page.', error: true);
                    },
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              icon: controller.checking
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.refresh_rounded),
              label: const Text('I have updated — check again'),
              onPressed: controller.checking ? null : controller.check,
            ),
            if (onSignOut != null) TextButton(onPressed: onSignOut, child: const Text('Sign out')),
          ]),
        ),
      ),
    );
  }
}

/// Small banner for an optional (non-mandatory) newer version.
class OptionalUpdateBanner extends StatelessWidget {
  const OptionalUpdateBanner({super.key, required this.controller});
  final UpdateController controller;
  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: controller,
        builder: (context, _) {
          if (!controller.optional || controller.targets().isEmpty) return const SizedBox.shrink();
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SoftCard(
              color: brandPeach,
              padding: const EdgeInsets.fromLTRB(14, 6, 6, 6),
              child: Row(children: [
                const Icon(Icons.system_update_rounded, color: brandBurnt),
                const SizedBox(width: 10),
                const Expanded(child: Text('A newer Finance 360 version is available.', style: TextStyle(fontWeight: FontWeight.w700))),
                TextButton(onPressed: controller.openUpdate, child: const Text('Update')),
                IconButton(
                  tooltip: 'Later',
                  icon: const Icon(Icons.close_rounded),
                  onPressed: controller.dismissOptional,
                ),
              ]),
            ),
          );
        },
      );
}
