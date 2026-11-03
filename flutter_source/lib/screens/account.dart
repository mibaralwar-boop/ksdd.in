import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

const appVersion = '0.38.0';

/// Signed-in user, restaurant/branch, device session and sign-out.
class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key, required this.session});
  final Session session;
  @override
  Widget build(BuildContext context) {
    final s = session;
    final p = s.permissions!;
    final canSwitch =
        s.memberships.length > 1 || (s.memberships.isNotEmpty && ((s.memberships.first['branches'] as List?)?.length ?? 0) > 1);
    return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
      SoftCard(
        color: brandPeach,
        child: Row(children: [
          CircleAvatar(
            radius: 26,
            backgroundColor: brandOrange,
            child: Text(((s.user?['name'] ?? '?').toString()).characters.first.toUpperCase(),
                style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w900)),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text((s.user?['name'] ?? '').toString(), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
              Text((s.user?['email'] ?? '').toString(), style: const TextStyle(color: brandMuted)),
            ]),
          ),
        ]),
      ),
      SectionCard(title: 'Restaurant', icon: Icons.storefront_outlined, children: [
        SumRow('Restaurant', s.restaurantName),
        SumRow('Branch', p.branchName),
        SumRow('Business date', p.businessDate),
        SumRow('Plan', p.subscription.plan ?? p.subscription.label),
        if (canSwitch)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () {
                Navigator.of(context).popUntil((r) => r.isFirst);
                s.leaveBranch();
              },
              icon: const Icon(Icons.swap_horiz_rounded),
              label: const Text('Switch restaurant / branch'),
            ),
          ),
      ]),
      SectionCard(title: 'Security', icon: Icons.verified_user_outlined, children: [
        const Text('This phone keeps a 15-minute access token and a refresh token in Android encrypted storage. '
            'Signing out revokes this device on the server.'),
        const SizedBox(height: 6),
        SumRow('Device id', s.api.deviceId.length > 8 ? '…${s.api.deviceId.substring(s.api.deviceId.length - 6)}' : s.api.deviceId),
        SumRow('Server', s.api.base.host),
        SumRow('App version', appVersion),
      ]),
      const SizedBox(height: 8),
      OutlinedButton.icon(
        icon: const Icon(Icons.logout_rounded),
        label: const Text('Sign out'),
        onPressed: () async {
          if (!await confirmDialog(context, 'Sign out?', 'You will need your password to sign in again.', ok: 'Sign out')) return;
          if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
          await s.logout();
        },
      ),
    ]);
  }
}

/// Subscription state (`GET subscriptions/current`) and, for the owner, payment history (`GET subscriptions/payments`).
/// No in-app checkout: renewals are done on the website (Google Play payment policy).
class SubscriptionScreen extends StatelessWidget {
  const SubscriptionScreen({super.key, required this.session});
  final Session session;
  @override
  Widget build(BuildContext context) => Loader<(Map<String, dynamic>, List<Map<String, dynamic>>?)>(
        load: () async {
          final cur = await session.api.get('subscriptions/current');
          List<Map<String, dynamic>>? orders;
          try {
            final o = await session.api.get('subscriptions/payments');
            orders = [for (final x in (o['orders'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          } on ApiError catch (e) {
            if (!e.isForbidden) rethrow;
          }
          return (cur, orders);
        },
        builder: (context, d, _) {
          final (c, orders) = d;
          final state = (c['state'] ?? '').toString();
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            SoftCard(
              color: brandPeach,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                StatusPill((c['label'] ?? state).toString(),
                    tone: c['read_only'] == true
                        ? PillTone.bad
                        : (state == 'active' || state == 'trialing' ? PillTone.good : PillTone.neutral)),
                const SizedBox(height: 10),
                Text((c['plan'] ?? 'No plan').toString(), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
                const SizedBox(height: 6),
                if (c['period_end_ist'] != null) Text('Valid until ${c['period_end_ist']}', style: const TextStyle(color: brandMuted)),
                if (c['days_left'] != null) Text('${c['days_left']} day(s) left', style: const TextStyle(fontWeight: FontWeight.w700)),
              ]),
            ),
            if (c['renewal_warning'] == true)
              const Banner2('Your plan ends soon. Renew on the Finance 360 website to keep adding entries.',
                  icon: Icons.warning_amber_rounded),
            if (c['read_only'] == true)
              const Banner2('Read-only: all records stay safe and visible. New entries need an active plan.', color: brandRedBg),
            const Banner2('Plans and payments are managed by the owner on the website. The app never takes payments.'),
            if (orders != null) ...[
              const SizedBox(height: 8),
              Text('Payment history', style: Theme.of(context).textTheme.titleLarge),
              if (orders.isEmpty)
                const EmptyState(
                    icon: Icons.receipt_long_outlined, title: 'No payments yet', subtitle: 'Receipts appear here after a payment.'),
              for (final o in orders)
                Card(
                  child: ListTile(
                    title: Text(rupees(o['amount_paise'])),
                    subtitle: Text('${o['created_at'] ?? ''} · ${o['receipt_no'] ?? o['order'] ?? ''}'),
                    trailing: StatusChip(o['status'] as String?),
                  ),
                ),
            ],
          ]);
        },
      );
}

/// Users of this branch and their role templates (`GET f360/users`, needs users.manage). Read-only in the app:
/// inviting users and changing permissions stays on the website, where every change is audited.
class UsersScreen extends StatelessWidget {
  const UsersScreen({super.key, required this.session});
  final Session session;
  @override
  Widget build(BuildContext context) => Loader<Map<String, dynamic>>(
        load: () => session.api.get('f360/users'),
        builder: (context, d, _) {
          final users = [for (final x in (d['users'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
            const Banner2('Invite users and change permissions on the website (Settings → Users). '
                'Changes reach this app within minutes; removed access closes the screens immediately.'),
            for (final u in users)
              Card(
                child: ExpansionTile(
                  shape: const Border(),
                  leading: CircleAvatar(
                    backgroundColor: u['active'] == 1 ? brandPeach : brandLine,
                    child: Text(u['name'].toString().characters.first.toUpperCase(), style: const TextStyle(color: brandBurnt)),
                  ),
                  title: Text(u['name'].toString(), style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text('${u['template'] ?? u['role']} · ${u['active'] == 1 ? 'active' : 'inactive'}'
                      '${u['last_login'] != null ? ' · last sign-in ${u['last_login']}' : ''}'),
                  childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  children: [
                    Wrap(spacing: 6, runSpacing: 6, children: [
                      for (final p in (u['perms'] as List? ?? const []))
                        Chip(label: Text(p.toString()), visualDensity: VisualDensity.compact),
                    ]),
                  ],
                ),
              ),
          ]);
        },
      );
}
