import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';
import 'closing.dart';
import 'dashboard.dart';
import 'expenses.dart';
import 'purchase.dart';
import 'reports.dart';
import 'salary.dart';
import 'staff.dart';
import 'stock.dart';

class ModuleDef {
  const ModuleDef(this.label, this.labelHi, this.icon);
  final String label;
  final String labelHi;
  final IconData icon;
}

const moduleDefs = <String, ModuleDef>{
  'dashboard': ModuleDef('Home', 'होम', Icons.space_dashboard_outlined),
  'closing': ModuleDef('Daily Closing', 'डेली क्लोज़िंग', Icons.point_of_sale_outlined),
  'sales': ModuleDef('Sales', 'सेल', Icons.receipt_long_outlined),
  'expenses': ModuleDef('Expenses', 'खर्च', Icons.payments_outlined),
  'cash': ModuleDef('Cash book', 'कैश बुक', Icons.account_balance_wallet_outlined),
  'handover': ModuleDef('Cash handover', 'कैश हैंडओवर', Icons.swap_horiz),
  'staff': ModuleDef('Staff', 'स्टाफ', Icons.badge_outlined),
  'payroll': ModuleDef('Salary', 'सैलरी', Icons.calendar_month_outlined),
  'purchases': ModuleDef('Purchase', 'खरीद', Icons.shopping_cart_outlined),
  'suppliers': ModuleDef('Suppliers', 'सप्लायर', Icons.local_shipping_outlined),
  'stock': ModuleDef('Stock', 'स्टॉक', Icons.inventory_2_outlined),
  'documents': ModuleDef('Documents', 'दस्तावेज़', Icons.folder_outlined),
  'reports': ModuleDef('Reports', 'रिपोर्ट', Icons.bar_chart_outlined),
  'accounts': ModuleDef('Accounts', 'अकाउंट्स', Icons.menu_book_outlined),
  'settings': ModuleDef('Settings', 'सेटिंग्स', Icons.settings_outlined),
};

Widget moduleScreen(String key, Session s) => switch (key) {
      'dashboard' => DashboardScreen(session: s),
      'closing' => ClosingScreen(session: s),
      'payroll' => SalaryScreen(session: s),
      'purchases' => PurchaseScreen(session: s),
      'stock' => StockScreen(session: s),
      'staff' => StaffScreen(session: s),
      'expenses' => ExpensesScreen(session: s),
      'reports' => ReportsScreen(session: s),
      _ => WebOnlyScreen(module: key),
    };

/// Navigation is built ONLY from the server's effective permissions:
/// one module → a single-module page (no bottom bar); 2–5 → bottom bar; more → 4 + "More".
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, required this.session, required this.onLanguage});
  final Session session;
  final VoidCallback onLanguage;
  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with WidgetsBindingObserver {
  String? _current;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) widget.session.reloadPermissions().catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.session;
    final p = s.permissions!;
    final mods = visibleModules(p);
    if (mods.isEmpty) {
      return Scaffold(
        appBar: _bar(context, 'Finance 360'),
        body: const Padding(
          padding: EdgeInsets.all(24),
          child: Banner2('Your account has no screens in this branch yet. Ask the owner to give you access.', icon: Icons.lock_outline),
        ),
      );
    }
    var cur = _current;
    if (cur == null || !mods.contains(cur)) cur = mods.contains(p.home) ? p.home : mods.first;
    final primary = mods.length <= 5 ? mods : [...mods.take(4), 'more'];
    final idx = primary.contains(cur) ? primary.indexOf(cur) : primary.length - 1;
    final title = moduleDefs[cur]?.label ?? cur;
    return Scaffold(
      appBar: _bar(context, tr(context, title, moduleDefs[cur]?.labelHi ?? title)),
      body: Column(children: [
        SubscriptionStrip(session: s),
        Expanded(child: KeyedSubtree(key: ValueKey('$cur-${p.version}'), child: moduleScreen(cur, s))),
      ]),
      bottomNavigationBar: mods.length == 1
          ? null
          : NavigationBar(
              selectedIndex: idx,
              onDestinationSelected: (i) async {
                if (primary[i] == 'more') {
                  final pick = await showModalBottomSheet<String>(
                    context: context,
                    builder: (c) => SafeArea(
                      child: ListView(shrinkWrap: true, children: [
                        for (final m in mods.skip(4))
                          ListTile(
                            leading: Icon(moduleDefs[m]?.icon ?? Icons.apps),
                            title: Text(tr(context, moduleDefs[m]?.label ?? m, moduleDefs[m]?.labelHi ?? m)),
                            onTap: () => Navigator.pop(c, m),
                          ),
                      ]),
                    ),
                  );
                  if (pick != null) setState(() => _current = pick);
                } else {
                  setState(() => _current = primary[i]);
                }
              },
              destinations: [
                for (final m in primary)
                  m == 'more'
                      ? const NavigationDestination(icon: Icon(Icons.more_horiz), label: 'More')
                      : NavigationDestination(
                          icon: Icon(moduleDefs[m]?.icon ?? Icons.apps),
                          label: tr(context, moduleDefs[m]?.label ?? m, moduleDefs[m]?.labelHi ?? m)),
              ],
            ),
    );
  }

  PreferredSizeWidget _bar(BuildContext context, String title) {
    final s = widget.session;
    return AppBar(
      titleSpacing: 16,
      title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(title),
        Text('${s.restaurantName} · ${s.permissions?.branchName ?? ''}',
            style: Theme.of(context).textTheme.bodySmall, overflow: TextOverflow.ellipsis),
      ]),
      actions: [
        PopupMenuButton<String>(
          tooltip: 'Account',
          icon: const Icon(Icons.account_circle_outlined),
          onSelected: (v) async {
            if (v == 'switch') s.leaveBranch();
            if (v == 'lang') widget.onLanguage();
            if (v == 'logout') await s.logout();
          },
          itemBuilder: (_) => [
            PopupMenuItem(enabled: false, child: Text(s.user?['email']?.toString() ?? '')),
            if (s.memberships.length > 1 || (s.memberships.isNotEmpty && ((s.memberships.first['branches'] as List?)?.length ?? 0) > 1))
              const PopupMenuItem(value: 'switch', child: Text('Switch restaurant / branch')),
            const PopupMenuItem(value: 'lang', child: Text('हिन्दी / English')),
            const PopupMenuItem(value: 'logout', child: Text('Sign out')),
          ],
        ),
      ],
    );
  }
}

class SubscriptionStrip extends StatelessWidget {
  const SubscriptionStrip({super.key, required this.session});
  final Session session;
  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    final sub = p.subscription;
    if (p.readOnly) {
      return Banner2(
        '${sub.label}: read-only. Your records are safe and can be viewed and exported; new entries need an active plan '
        '(the owner can renew on the website).',
        color: const Color(0xFFFDE2E1),
        icon: Icons.lock_clock_outlined,
      );
    }
    if (sub.trialing && sub.daysLeft != null) {
      return Banner2('Free trial · ${sub.daysLeft} day(s) left (ends ${sub.endsIst}).', icon: Icons.hourglass_bottom);
    }
    return const SizedBox.shrink();
  }
}

class WebOnlyScreen extends StatelessWidget {
  const WebOnlyScreen({super.key, required this.module});
  final String module;
  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.all(24), children: [
        Icon(moduleDefs[module]?.icon ?? Icons.apps, size: 48, color: brandOrange),
        const SizedBox(height: 12),
        Text('${moduleDefs[module]?.label ?? module} is available on the Finance 360 website for your account.',
            textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        const Text('This screen is not in the Android app yet. Your permission for it is real; use the website until it is added.',
            textAlign: TextAlign.center),
      ]);
}
