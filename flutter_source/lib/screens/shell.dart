import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';
import 'account.dart';
import 'bills.dart';
import 'closing.dart';
import 'dashboard.dart';
import 'documents.dart';
import 'expenses.dart';
import 'money.dart';
import 'notifications.dart';
import 'purchase.dart';
import 'reports.dart';
import 'salary.dart';
import 'sales.dart';
import 'staff.dart';
import 'staff_extra.dart';
import 'stock.dart';
import 'suppliers.dart';
import 'update.dart';

class ModuleDef {
  const ModuleDef(this.label, this.labelHi, this.icon, this.group);
  final String label;
  final String labelHi;
  final IconData icon;
  final String group;
}

/// Every module key the server can return in `permissions.menus`, plus app-only screens that are gated by
/// server actions (see [extraModules]). Groups follow the V36 More grid.
const moduleDefs = <String, ModuleDef>{
  'dashboard': ModuleDef('Home', 'होम', Icons.space_dashboard_rounded, 'Finance'),
  'closing': ModuleDef('Daily Closing', 'डेली क्लोज़िंग', Icons.point_of_sale_rounded, 'Finance'),
  'sales': ModuleDef('Sales', 'सेल', Icons.bar_chart_rounded, 'Finance'),
  'expenses': ModuleDef('Expenses', 'खर्च', Icons.receipt_long_rounded, 'Finance'),
  'cash': ModuleDef('Cash book', 'कैश बुक', Icons.account_balance_wallet_outlined, 'Finance'),
  'handover': ModuleDef('Cash handover', 'कैश हैंडओवर', Icons.swap_horiz_rounded, 'Finance'),
  'accounts': ModuleDef('Accounts', 'अकाउंट्स', Icons.account_balance_outlined, 'Finance'),
  'reports': ModuleDef('Reports', 'रिपोर्ट', Icons.analytics_outlined, 'Finance'),
  'staff': ModuleDef('Staff', 'स्टाफ', Icons.groups_outlined, 'Team'),
  'payroll': ModuleDef('Salary', 'सैलरी', Icons.calendar_month_outlined, 'Team'),
  'salary_history': ModuleDef('Salary history', 'पुरानी सैलरी', Icons.history_rounded, 'Team'),
  'staff_payments': ModuleDef('Staff payments', 'स्टाफ भुगतान', Icons.currency_rupee_rounded, 'Team'),
  'purchases': ModuleDef('Purchase', 'खरीद', Icons.shopping_cart_outlined, 'Purchasing & stock'),
  'purchase_bills': ModuleDef('Purchase bills', 'खरीद बिल', Icons.receipt_outlined, 'Purchasing & stock'),
  'suppliers': ModuleDef('Suppliers', 'सप्लायर', Icons.local_shipping_outlined, 'Purchasing & stock'),
  'stock': ModuleDef('Stock', 'स्टॉक', Icons.inventory_2_outlined, 'Purchasing & stock'),
  'documents': ModuleDef('Documents', 'दस्तावेज़', Icons.folder_outlined, 'Purchasing & stock'),
  'notifications': ModuleDef('Notifications', 'सूचनाएँ', Icons.notifications_none_rounded, 'Restaurant'),
  'subscription': ModuleDef('Subscription', 'सब्सक्रिप्शन', Icons.workspace_premium_outlined, 'Restaurant'),
  'settings': ModuleDef('Users & permissions', 'यूज़र और अनुमति', Icons.admin_panel_settings_outlined, 'Restaurant'),
  'profile': ModuleDef('Profile & settings', 'प्रोफ़ाइल', Icons.person_outline_rounded, 'Restaurant'),
};

/// Preferred order: the V36 bottom bar is Home · Daily Closing · Sales · Expenses · More (only where permitted).
const moduleOrder = [
  'dashboard',
  'closing',
  'sales',
  'expenses',
  'cash',
  'handover',
  'accounts',
  'reports',
  'staff',
  'payroll',
  'purchases',
  'suppliers',
  'stock',
  'documents',
  'settings',
];

/// App-only screens: shown only when the server's effective actions allow the underlying API.
List<String> extraModules(Permissions p) => [
      if (p.can('payroll.view')) 'salary_history',
      if (p.can('staff.ledger') || p.can('stafftxn.create') || p.can('stafftxn.approve')) 'staff_payments',
      if (p.can('purchase.view')) 'purchase_bills',
      if (p.can('notifications.view')) 'notifications',
      // Plan / billing details only for the owner or a user who manages users (never cashier / storekeeper).
      if (p.template == 'owner' || p.can('users.manage') || p.can('settings.manage')) 'subscription',
      'profile',
    ];

List<String> orderedModules(Permissions p) {
  final mods = visibleModules(p);
  return [
    for (final k in moduleOrder)
      if (mods.contains(k)) k,
    for (final k in mods)
      if (!moduleOrder.contains(k)) k,
  ];
}

Widget moduleScreen(String key, Session s, {void Function(String)? open}) => switch (key) {
      'dashboard' => DashboardScreen(session: s, open: open),
      'closing' => ClosingScreen(session: s),
      'sales' => SalesScreen(session: s),
      'expenses' => ExpensesScreen(session: s),
      'cash' => CashBookScreen(session: s),
      'handover' => HandoverScreen(session: s),
      'accounts' => AccountsScreen(session: s),
      'reports' => ReportsScreen(session: s),
      'staff' => StaffScreen(session: s),
      'payroll' => SalaryScreen(session: s),
      'salary_history' => SalaryHistoryScreen(session: s),
      'staff_payments' => StaffPaymentsScreen(session: s),
      'purchases' => PurchaseScreen(session: s),
      'purchase_bills' => PurchaseBillsScreen(session: s),
      'suppliers' => SuppliersScreen(session: s),
      'stock' => StockScreen(session: s),
      'documents' => DocumentsScreen(session: s),
      'notifications' => NotificationsScreen(session: s),
      'subscription' => SubscriptionScreen(session: s),
      'settings' => UsersScreen(session: s),
      'profile' => ProfileScreen(session: s),
      _ => UnknownModuleScreen(module: key),
    };

String moduleLabel(BuildContext context, String key) =>
    tr(context, moduleDefs[key]?.label ?? key, moduleDefs[key]?.labelHi ?? moduleDefs[key]?.label ?? key);

/// Navigation is built ONLY from the server's effective permissions:
/// one module → a single-module page (no bottom bar); otherwise up to four modules + "More".
class HomeShell extends StatefulWidget {
  const HomeShell({super.key, required this.session, required this.onLanguage, this.updates});
  final Session session;
  final VoidCallback onLanguage;
  final UpdateController? updates;
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

  void _open(String key) {
    final s = widget.session;
    final p = s.permissions!;
    final mods = orderedModules(p);
    final all = [...mods, ...extraModules(p)];
    if (!all.contains(key)) {
      showMessage(context, 'You do not have permission for this screen.', error: true);
      return;
    }
    final primary = _primary(mods, p);
    if (primary.contains(key)) {
      setState(() => _current = key);
      return;
    }
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ModulePage(session: s, module: key, open: _open)));
  }

  List<String> _primary(List<String> mods, Permissions p) {
    if (mods.length <= 1) return mods;
    final rest = mods.length > 4 || extraModules(p).isNotEmpty;
    return rest ? [...mods.take(4), 'more'] : mods;
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.session;
    final p = s.permissions!;
    final mods = orderedModules(p);
    if (mods.isEmpty) {
      return Scaffold(
        appBar: ShellBar(session: s, title: 'Finance 360', onLanguage: widget.onLanguage, open: _open),
        body: const Padding(
          padding: EdgeInsets.all(20),
          child: EmptyState(
            icon: Icons.lock_outline_rounded,
            title: 'No screens yet',
            subtitle: 'Your account has no screens in this branch yet. Ask the owner to give you access.',
          ),
        ),
      );
    }
    final primary = _primary(mods, p);
    var cur = _current;
    if (cur == null || !(primary.contains(cur))) cur = primary.contains(p.home) ? p.home : primary.first;
    final idx = primary.indexOf(cur);
    final title = cur == 'more' ? tr(context, 'More', 'और') : moduleLabel(context, cur);
    return Scaffold(
      appBar: ShellBar(session: s, title: title, onLanguage: widget.onLanguage, open: _open),
      body: Column(children: [
        SubscriptionStrip(session: s),
        if (widget.updates != null) OptionalUpdateBanner(controller: widget.updates!),
        Expanded(
          child: KeyedSubtree(
            key: ValueKey('$cur-${p.version}'),
            child: cur == 'more'
                ? MoreScreen(session: s, modules: [...mods.skip(4), ...extraModules(p)], open: _open)
                : moduleScreen(cur, s, open: _open),
          ),
        ),
      ]),
      bottomNavigationBar: primary.length <= 1
          ? null
          : NavigationBar(
              selectedIndex: idx < 0 ? 0 : idx,
              onDestinationSelected: (i) => setState(() => _current = primary[i]),
              destinations: [
                for (final m in primary)
                  m == 'more'
                      ? NavigationDestination(icon: const Icon(Icons.grid_view_rounded), label: tr(context, 'More', 'और'))
                      : NavigationDestination(icon: Icon(moduleDefs[m]?.icon ?? Icons.apps), label: moduleLabel(context, m)),
              ],
            ),
    );
  }
}

/// V36 header: restaurant · branch line, page title, notifications and the account menu.
class ShellBar extends StatelessWidget implements PreferredSizeWidget {
  const ShellBar({super.key, required this.session, required this.title, required this.onLanguage, required this.open});
  final Session session;
  final String title;
  final VoidCallback onLanguage;
  final void Function(String) open;

  @override
  Size get preferredSize => const Size.fromHeight(72);

  @override
  Widget build(BuildContext context) {
    final s = session;
    final p = s.permissions;
    final canSwitch =
        s.memberships.length > 1 || (s.memberships.isNotEmpty && ((s.memberships.first['branches'] as List?)?.length ?? 0) > 1);
    final name = (s.user?['name'] ?? s.user?['email'] ?? '').toString();
    return AppBar(
      toolbarHeight: 72,
      titleSpacing: 18,
      title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('${s.restaurantName} · ${p?.branchName ?? ''}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 12.5, color: brandMuted, fontWeight: FontWeight.w600)),
        Text(title, maxLines: 1, overflow: TextOverflow.ellipsis),
      ]),
      actions: [
        if (p != null && p.can('notifications.view'))
          IconButton(
            tooltip: 'Notifications',
            onPressed: () => open('notifications'),
            icon: const Icon(Icons.notifications_none_rounded),
          ),
        PopupMenuButton<String>(
          tooltip: 'Account',
          icon: CircleAvatar(
            radius: 17,
            backgroundColor: brandPeach,
            child: Text(name.isEmpty ? '?' : name.characters.first.toUpperCase(),
                style: const TextStyle(color: brandBurnt, fontWeight: FontWeight.w800)),
          ),
          onSelected: (v) async {
            if (v == 'switch') s.leaveBranch();
            if (v == 'lang') onLanguage();
            if (v == 'profile') open('profile');
            if (v == 'logout') await s.logout();
          },
          itemBuilder: (_) => [
            PopupMenuItem(enabled: false, child: Text(s.user?['email']?.toString() ?? '')),
            const PopupMenuItem(value: 'profile', child: Text('Profile & settings')),
            if (canSwitch) const PopupMenuItem(value: 'switch', child: Text('Switch restaurant / branch')),
            const PopupMenuItem(value: 'lang', child: Text('हिन्दी / English')),
            const PopupMenuItem(value: 'logout', child: Text('Sign out')),
          ],
        ),
        const SizedBox(width: 8),
      ],
    );
  }
}

/// A module opened from More: its own page with back navigation.
class ModulePage extends StatelessWidget {
  const ModulePage({super.key, required this.session, required this.module, required this.open});
  final Session session;
  final String module;
  final void Function(String) open;
  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: session,
        builder: (context, _) {
          final p = session.permissions;
          final allowed = p != null && [...orderedModules(p), ...extraModules(p)].contains(module);
          return Scaffold(
            appBar: AppBar(
              title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${session.restaurantName} · ${p?.branchName ?? ''}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 12.5, color: brandMuted, fontWeight: FontWeight.w600)),
                Text(moduleLabel(context, module)),
              ]),
            ),
            body: allowed
                ? moduleScreen(module, session, open: open)
                : const EmptyState(
                    icon: Icons.lock_outline_rounded,
                    title: 'No permission',
                    subtitle: 'Your access to this screen was changed. Ask the owner if you need it.',
                  ),
          );
        },
      );
}

/// V36 More page: permitted modules only, grouped in a two-column grid.
class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key, required this.session, required this.modules, required this.open});
  final Session session;
  final List<String> modules;
  final void Function(String) open;

  @override
  Widget build(BuildContext context) {
    final groups = <String, List<String>>{};
    for (final m in modules) {
      groups.putIfAbsent(moduleDefs[m]?.group ?? 'Other', () => []).add(m);
    }
    final p = session.permissions!;
    return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
      SoftCard(
        color: brandPeach,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(session.restaurantName, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          const SizedBox(height: 4),
          Text('${p.branchName} branch · ${_template(p.template)}', style: const TextStyle(color: brandMuted, fontSize: 12.5)),
        ]),
      ),
      for (final g in groups.entries) ...[
        const SizedBox(height: 16),
        Text(g.key, style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 10),
        LayoutBuilder(builder: (context, c) {
          final cols = c.maxWidth > 560 ? 3 : 2;
          final w = (c.maxWidth - 11 * (cols - 1)) / cols;
          return Wrap(spacing: 11, runSpacing: 11, children: [
            for (final m in g.value)
              SizedBox(
                width: w,
                child: SoftCard(
                  onTap: () => open(m),
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 18),
                  child: Column(children: [
                    Icon(moduleDefs[m]?.icon ?? Icons.widgets_outlined, size: 27, color: brandOrange),
                    const SizedBox(height: 9),
                    Text(moduleLabel(context, m),
                        textAlign: TextAlign.center, maxLines: 2, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
                  ]),
                ),
              ),
          ]);
        }),
      ],
    ]);
  }

  static String _template(String t) => switch (t) {
        'owner' => 'Owner',
        'manager' => 'Manager',
        'accountant' => 'Accountant',
        'cashier' || 'counter' || 'cash_counter' => 'Cash counter',
        'store' || 'storekeeper' => 'Storekeeper',
        'salary' => 'Salary manager',
        '' => 'Staff',
        _ => t[0].toUpperCase() + t.substring(1).replaceAll('_', ' '),
      };
}

class SubscriptionStrip extends StatelessWidget {
  const SubscriptionStrip({super.key, required this.session});
  final Session session;
  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    final sub = p.subscription;
    if (p.readOnly) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Banner2(
          '${sub.label}: read-only. Your records are safe and can be viewed and exported; new entries need an active plan '
          '(the owner can renew on the website).',
          color: brandRedBg,
          icon: Icons.lock_clock_outlined,
        ),
      );
    }
    if (sub.trialing && sub.daysLeft != null) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Banner2('Free trial · ${sub.daysLeft} day(s) left (ends ${sub.endsIst}).', icon: Icons.hourglass_bottom_rounded),
      );
    }
    return const SizedBox.shrink();
  }
}

/// A module key this app version does not know (server newer than the app): honest message, no fake screen.
class UnknownModuleScreen extends StatelessWidget {
  const UnknownModuleScreen({super.key, required this.module});
  final String module;
  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.all(20), children: [
        EmptyState(
          icon: Icons.system_update_alt_rounded,
          title: '${moduleDefs[module]?.label ?? module} needs a newer app',
          subtitle: 'Your permission for this screen is real, but this version of the app does not have it yet. '
              'Update the app, or use the Finance 360 website meanwhile.',
        ),
      ]);
}
