import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class Brand extends StatelessWidget {
  const Brand({super.key});
  @override
  Widget build(BuildContext context) => Container(
        width: 64,
        height: 64,
        decoration: BoxDecoration(color: brandOrange, borderRadius: BorderRadius.circular(16)),
        child: const Icon(Icons.account_balance_wallet_outlined, color: Colors.white, size: 36),
      );
}

class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, required this.session, required this.onLanguage});
  final Session session;
  final VoidCallback onLanguage;
  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _busy = false;
  bool _hide = true;
  String? _error;

  Api get api => widget.session.api;

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.session.login(_email.text, _password.text);
    } catch (e) {
      setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _forgot() async {
    final email = await askText(context, tr(context, 'Reset password', 'पासवर्ड रीसेट'),
        tr(context, 'We will email a reset link if an account exists.', 'अगर अकाउंट है तो रीसेट लिंक ईमेल होगा।'),
        label: 'Email');
    if (email == null) return;
    try {
      final r = await api.publicPost('auth/password-forgot', {'email': email});
      if (mounted) showMessage(context, (r['message'] ?? 'Check your email.').toString());
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ok = api.configured;
    return Scaffold(
      appBar: AppBar(title: const Text('Finance 360'), actions: [
        TextButton(onPressed: widget.onLanguage, child: const Text('हिन्दी / EN')),
      ]),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Form(
              key: _form,
              child: ListView(padding: const EdgeInsets.all(24), children: [
                const Center(child: Brand()),
                const SizedBox(height: 20),
                Text(tr(context, 'Your restaurant. A clear view.', 'आपका रेस्टोरेंट। साफ़ हिसाब।'),
                    style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 16),
                if (!ok)
                  const Banner2('This build has no configured secure server. Sign-in will be available after server configuration.',
                      icon: Icons.cloud_off),
                TextFormField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  autofillHints: const [AutofillHints.email],
                  decoration: const InputDecoration(labelText: 'Email'),
                  validator: (v) => (v ?? '').contains('@') ? null : 'Enter your email',
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _password,
                  obscureText: _hide,
                  autofillHints: const [AutofillHints.password],
                  decoration: InputDecoration(
                    labelText: tr(context, 'Password', 'पासवर्ड'),
                    suffixIcon: IconButton(
                      tooltip: _hide ? 'Show password' : 'Hide password',
                      icon: Icon(_hide ? Icons.visibility : Icons.visibility_off),
                      onPressed: () => setState(() => _hide = !_hide),
                    ),
                  ),
                  validator: (v) => (v ?? '').isEmpty ? 'Enter your password' : null,
                  onFieldSubmitted: (_) => _submit(),
                ),
                if (_error != null)
                  Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: brandRed))),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: ok && !_busy ? _submit : null,
                  child: _busy
                      ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(tr(context, 'Sign in', 'साइन इन')),
                ),
                const SizedBox(height: 10),
                OutlinedButton(
                  onPressed: ok ? () => Navigator.push(context, MaterialPageRoute(builder: (_) => RegisterScreen(api: api))) : null,
                  child: Text(tr(context, 'Register restaurant', 'रेस्टोरेंट रजिस्टर करें')),
                ),
                TextButton(onPressed: ok ? _forgot : null, child: Text(tr(context, 'Forgot password?', 'पासवर्ड भूल गए?'))),
                TextButton(
                  onPressed: ok ? () => Navigator.push(context, MaterialPageRoute(builder: (_) => PlansScreen(api: api))) : null,
                  child: Text(tr(context, 'View plans', 'प्लान देखें')),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

class PlansScreen extends StatelessWidget {
  const PlansScreen({super.key, required this.api});
  final Api api;
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Plans')),
        body: Loader<Map<String, dynamic>>(
          load: () => api.publicGet('subscriptions/plans'),
          builder: (context, d, _) {
            final plans = (d['plans'] as List? ?? const []);
            return ListView(padding: const EdgeInsets.all(16), children: [
              Banner2('New restaurants start with a ${d['trial_days'] ?? 30}-day free trial. No payment is taken in the app.'),
              if (plans.isEmpty) const SectionCard(children: [Text('No paid plans are published yet.')]),
              for (final p in plans)
                SectionCard(title: (p as Map)['name']?.toString() ?? 'Plan', children: [
                  if (p['description'] != null) Text(p['description'].toString()),
                  for (final v in (p['terms'] as List? ?? const []))
                    SumRow(
                        '${(v as Map)['term']} · up to ${v['max_branches']} branch(es), ${v['max_users']} users', rupees(v['price_paise'])),
                ]),
            ]);
          },
        ),
      );
}

/// Restaurant self-registration (V29 `POST tenants/register`). The restaurant is created only after the owner
/// verifies the email link, so nothing here claims the account is active.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key, required this.api});
  final Api api;
  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final c = {
    for (final k in [
      'owner_name',
      'mobile',
      'email',
      'password',
      'restaurant',
      'contact_phone',
      'address',
      'city',
      'state',
      'pincode',
      'branch_name'
    ])
      k: TextEditingController(),
  };
  int _cutoff = 4;
  bool _terms = false;
  bool _busy = false;
  String? _done;

  @override
  void initState() {
    super.initState();
    c['branch_name']!.text = 'Main';
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    if (!_terms) {
      showMessage(context, 'Please accept the terms and privacy policy.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final r = await widget.api.publicPost('tenants/register', {
        for (final e in c.entries) e.key: e.value.text.trim(),
        'cutoff_hour': _cutoff,
        'accept_terms': true,
      });
      setState(() => _done = (r['message'] ?? 'Check your email to verify and finish setting up your restaurant.').toString());
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _f(String k, String label, {TextInputType? type, bool req = false, bool obscure = false, String? Function(String)? check}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextFormField(
          controller: c[k],
          keyboardType: type,
          obscureText: obscure,
          decoration: InputDecoration(labelText: req ? '$label *' : label),
          validator: (v) {
            final t = (v ?? '').trim();
            if (req && t.isEmpty) return 'Required';
            return t.isEmpty ? null : check?.call(t);
          },
        ),
      );

  @override
  Widget build(BuildContext context) {
    if (_done != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Register restaurant')),
        body: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(children: [
            const Icon(Icons.mark_email_read_outlined, size: 64, color: brandOrange),
            const SizedBox(height: 16),
            Text(_done!, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            const Text(
                'Your 30-day free trial starts when the restaurant is created after verification. '
                'Mobile number is stored unverified until an OTP service is connected.',
                textAlign: TextAlign.center),
            const SizedBox(height: 24),
            FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Back to sign in')),
          ]),
        ),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Register restaurant')),
      body: Form(
        key: _form,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Text('Owner', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          _f('owner_name', 'Full name', req: true),
          _f('mobile', 'Mobile (10 digits)',
              type: TextInputType.phone,
              req: true,
              check: (t) => RegExp(r'^[6-9]\d{9}$').hasMatch(t) ? null : 'Enter a 10-digit Indian mobile'),
          _f('email', 'Email', type: TextInputType.emailAddress, req: true, check: (t) => t.contains('@') ? null : 'Enter a valid email'),
          _f('password', 'Password (min 10 characters)',
              obscure: true, req: true, check: (t) => t.length >= 10 ? null : 'At least 10 characters'),
          Text('Restaurant', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          _f('restaurant', 'Restaurant name', req: true),
          _f('contact_phone', 'Restaurant phone', type: TextInputType.phone),
          _f('address', 'Address'),
          _f('city', 'City', req: true),
          _f('state', 'State'),
          _f('pincode', 'PIN code', type: TextInputType.number, check: (t) => RegExp(r'^[1-9]\d{5}$').hasMatch(t) ? null : '6-digit PIN'),
          Text('First branch', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          _f('branch_name', 'Branch name', req: true),
          DropdownButtonFormField<int>(
            initialValue: _cutoff,
            decoration: const InputDecoration(labelText: 'Business day ends at (next morning)'),
            items: [for (var h = 0; h <= 12; h++) DropdownMenuItem(value: h, child: Text('${h.toString().padLeft(2, '0')}:00'))],
            onChanged: (v) => setState(() => _cutoff = v ?? 4),
          ),
          const SizedBox(height: 12),
          const Banner2('Default free trial: 30 calendar days, once per owner. After it ends, records stay readable and exportable; '
              'new entries need an active plan.'),
          CheckboxListTile(
            value: _terms,
            onChanged: (v) => setState(() => _terms = v ?? false),
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text('I accept the Terms and Privacy Policy and consent to processing of my restaurant data.'),
          ),
          const SizedBox(height: 8),
          FilledButton(onPressed: _busy ? null : _submit, child: Text(_busy ? 'Sending…' : 'Create restaurant')),
          const SizedBox(height: 24),
        ]),
      ),
    );
  }
}

/// Restaurants and branches come only from explicit memberships returned by the server.
class ChooseBranchScreen extends StatefulWidget {
  const ChooseBranchScreen({super.key, required this.session});
  final Session session;
  @override
  State<ChooseBranchScreen> createState() => _ChooseBranchScreenState();
}

class _ChooseBranchScreenState extends State<ChooseBranchScreen> {
  bool _busy = false;

  Future<void> _open(int t, int b) async {
    setState(() => _busy = true);
    try {
      await widget.session.select(t, b);
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ms = widget.session.memberships;
    return Scaffold(
      appBar: AppBar(title: const Text('Choose restaurant'), actions: [
        IconButton(tooltip: 'Sign out', onPressed: widget.session.logout, icon: const Icon(Icons.logout)),
      ]),
      body: _busy
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(16), children: [
              Text('Signed in as ${widget.session.user?['email'] ?? ''}'),
              const SizedBox(height: 8),
              if (ms.isEmpty)
                const Banner2('Your account is not a member of any restaurant yet. Ask the owner to invite you, '
                    'or register your own restaurant.'),
              for (final m in ms)
                SectionCard(title: m['name'].toString(), children: [
                  Row(children: [
                    Text('${m['role']}'),
                    const Spacer(),
                    Text(Subscription((m['subscription'] as Map?)?.cast<String, dynamic>() ?? const {}).label),
                  ]),
                  for (final b in (m['branches'] as List? ?? const []))
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.storefront_outlined),
                      title: Text((b as Map)['name'].toString()),
                      subtitle: Text([b['city'], if (b['primary'] == true) 'Primary branch'].whereType<String>().join(' · ')),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => _open((m['id'] as num).toInt(), (b['id'] as num).toInt()),
                    ),
                ]),
            ]),
    );
  }
}
