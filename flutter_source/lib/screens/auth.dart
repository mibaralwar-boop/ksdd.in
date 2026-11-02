import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/strings.dart';

class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key, required this.api, required this.onAuthenticated, required this.onLanguage});
  final FinanceApi api;
  final VoidCallback onAuthenticated;
  final VoidCallback onLanguage;
  @override
  State<AuthScreen> createState() => _AuthScreenState();
}
class _AuthScreenState extends State<AuthScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _busy = false;
  String? _error;
  @override
  void dispose() { _email.dispose(); _password.dispose(); super.dispose(); }
  Future<void> _login() async {
    if (!_form.currentState!.validate() || _busy) return;
    setState(() { _busy = true; _error = null; });
    try {
      await widget.api.login(_email.text.trim(), _password.text);
      _password.clear();
      if (mounted) widget.onAuthenticated();
    } catch (e) { if (mounted) setState(() => _error = e.toString()); }
    finally { if (mounted) setState(() => _busy = false); }
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Finance 360'), actions: [
      TextButton(onPressed: widget.onLanguage, child: const Text('हिन्दी / EN')),
    ]),
    body: Center(child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 520),
      child: Form(key: _form, child: ListView(padding: const EdgeInsets.all(24), children: [
        const Icon(Icons.account_balance_wallet_rounded, size: 64, color: Color(0xFFE87520)),
        const SizedBox(height: 24),
        Text(tr(context, 'Your restaurant. A clear view.', 'आपके रेस्टोरेंट का साफ़ हिसाब।'), style: Theme.of(context).textTheme.headlineMedium),
        const SizedBox(height: 24),
        if (!widget.api.configured) const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('This build has no configured secure server. Sign-in will be available after server configuration.'))),
        TextFormField(controller: _email, keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.username], decoration: InputDecoration(labelText: tr(context,'Email','ईमेल')), validator: (v) => v != null && v.contains('@') ? null : tr(context,'Enter your email','ईमेल भरें')),
        const SizedBox(height: 16),
        TextFormField(controller: _password, obscureText: true, autofillHints: const [AutofillHints.password], decoration: InputDecoration(labelText: tr(context,'Password','पासवर्ड')), validator: (v) => v == null || v.isEmpty ? tr(context,'Enter your password','पासवर्ड भरें') : null, onFieldSubmitted: (_) => _login()),
        const SizedBox(height: 16),
        if (_error != null) Semantics(liveRegion: true, child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
        const SizedBox(height: 12),
        FilledButton(onPressed: _busy || !widget.api.configured ? null : _login, child: Text(_busy ? tr(context,'Signing in…','साइन इन हो रहा है…') : tr(context,'Sign in','साइन इन'))),
        const SizedBox(height: 8),
        OutlinedButton(onPressed: _busy || !widget.api.configured ? null : () async {
          final result = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => RegistrationScreen(api: widget.api)));
          if (result == true && mounted) widget.onAuthenticated();
        }, child: Text(tr(context,'Register restaurant','रेस्टोरेंट रजिस्टर करें'))),
        TextButton(onPressed: !widget.api.configured ? null : () async {
          try {
            final data = await widget.api.request('plans', authenticated: false);
            if (!context.mounted) return;
            await showDialog<void>(context: context, builder: (_) => AlertDialog(title: Text(tr(context,'Plans','प्लान')), content: Text(data['notice'] as String), actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('OK'))]));
          } catch (e) { if (mounted) setState(() => _error = e.toString()); }
        }, child: Text(tr(context,'View plans','प्लान देखें'))),
      ])),
    )),
  );
}

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({super.key, required this.api});
  final FinanceApi api;
  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}
class _RegistrationScreenState extends State<RegistrationScreen> {
  final _fields = <String, TextEditingController>{
    for (final key in ['owner_name','email','password','confirm','restaurant_name','address','city','branch_name']) key: TextEditingController(),
  };
  final _form = GlobalKey<FormState>();
  bool _consent = false;
  bool _busy = false;
  int _step = 0;
  String? _error;
  @override
  void initState() { super.initState(); _fields['branch_name']!.text = 'Main Branch'; }
  @override
  void dispose() { for (final f in _fields.values) { f.dispose(); } super.dispose(); }
  Widget field(String key, String en, String hi, {bool secret = false, int? max}) => Padding(
    padding: const EdgeInsets.only(bottom: 16),
    child: TextFormField(controller: _fields[key], obscureText: secret,
      maxLength: max, keyboardType: key == 'email' ? TextInputType.emailAddress : TextInputType.text,
      decoration: InputDecoration(labelText: tr(context,en,hi)),
      validator: (v) {
        if (v == null || v.trim().isEmpty) return tr(context,'Required','ज़रूरी');
        if (key == 'email' && !v.contains('@')) return tr(context,'Enter a valid email','सही ईमेल भरें');
        if (key == 'password' && v.length < 12) return tr(context,'Use at least 12 characters','कम से कम 12 अक्षर रखें');
        if (key == 'confirm' && v != _fields['password']!.text) return tr(context,'Passwords do not match','पासवर्ड समान नहीं हैं');
        return null;
      },
    ),
  );
  Future<void> _next() async {
    if (_busy || !_form.currentState!.validate()) return;
    if (_step < 3) { setState(() { _step++; _error = null; }); return; }
    if (!_consent) { setState(() => _error = tr(context,'Please accept the privacy notice.','Privacy notice स्वीकार करें।')); return; }
    setState(() { _busy = true; _error = null; });
    try {
      final data = <String, dynamic>{for (final e in _fields.entries) if (e.key != 'confirm') e.key: e.value.text.trim()};
      data['password'] = _fields['password']!.text;
      data['privacy_consent'] = true;
      data['language'] = Localizations.localeOf(context).languageCode;
      await widget.api.register(data);
      if (mounted) Navigator.pop(context,true);
    } catch (e) { if (mounted) setState(() => _error = e.toString()); }
    finally { if (mounted) setState(() => _busy = false); }
  }
  @override
  Widget build(BuildContext context) {
    final titles = [tr(context,'Owner details','मालिक का विवरण'),tr(context,'Restaurant','रेस्टोरेंट'),tr(context,'First branch','पहली ब्रांच'),tr(context,'Trial and privacy','ट्रायल और प्राइवेसी')];
    return Scaffold(appBar: AppBar(title: Text(titles[_step])), body: Center(child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 560), child: Form(key: _form, child: ListView(
        padding: const EdgeInsets.all(24), children: [
          Text('${_step+1} / 4', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8), LinearProgressIndicator(value: (_step+1)/4), const SizedBox(height: 24),
          if (_step == 0) ...[field('owner_name','Full name','पूरा नाम',max: 160),field('email','Email','ईमेल',max:190),field('password','Password','पासवर्ड',secret:true),field('confirm','Confirm password','पासवर्ड दोबारा',secret:true)],
          if (_step == 1) ...[field('restaurant_name','Restaurant name','रेस्टोरेंट का नाम',max:160),field('address','Business address','पता',max:500),field('city','City','शहर',max:100)],
          if (_step == 2) ...[field('branch_name','Branch name','ब्रांच का नाम',max:160),Text(tr(context,'The first branch uses the restaurant address.','पहली ब्रांच में रेस्टोरेंट वाला पता रहेगा।'))],
          if (_step == 3) ...[
            Text(tr(context,'30 calendar days free','30 कैलेंडर दिन मुफ्त'),style:Theme.of(context).textTheme.headlineSmall),const SizedBox(height:16),
            Text(tr(context,'Your server records the exact trial start and end. Manual identity verification is required before management actions are enabled. Financial features are not available in this foundation build. No payment will be collected.','ट्रायल का समय सर्वर तय करेगा। Management actions के लिए पहचान का manual verification ज़रूरी है। इस foundation build में financial features उपलब्ध नहीं हैं। कोई payment नहीं लिया जाएगा।')),
            const SizedBox(height:16),
            Text(tr(context,'Privacy notice: your account and restaurant details are stored by your configured server to provide this service. Contact your restaurant/platform administrator for data access and deletion requests. Financial retention may require review. This notice is a staging draft, not a published privacy policy.','Privacy notice: आपकी account और restaurant details सेवा देने के लिए configured server पर रखी जाएँगी। Data access या deletion के लिए administrator से संपर्क करें। Financial records के retention की समीक्षा हो सकती है। यह staging draft है।')),
            CheckboxListTile(value:_consent,onChanged:_busy?null:(v)=>setState(()=>_consent=v??false),title:Text(tr(context,'I accept processing for account setup.','मैं account बनाने के लिए data processing स्वीकार करता/करती हूँ।')),controlAffinity:ListTileControlAffinity.leading),
          ],
          if(_error!=null)Semantics(liveRegion:true,child:Text(_error!,style:TextStyle(color:Theme.of(context).colorScheme.error))),
          const SizedBox(height:24),
          FilledButton(onPressed:_busy?null:_next,child:Text(_busy?tr(context,'Creating…','बन रहा है…'):_step==3?tr(context,'Create restaurant','रेस्टोरेंट बनाएँ'):tr(context,'Continue','आगे'))),
          if(_step>0)TextButton(onPressed:_busy?null:()=>setState(()=>_step--),child:Text(tr(context,'Back','पीछे'))),
        ],
      )),
    )));
  }
}
