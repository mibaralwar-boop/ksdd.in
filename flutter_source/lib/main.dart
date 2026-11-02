import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'core/api.dart';
import 'core/theme.dart';
import 'screens/auth.dart';
import 'screens/workspace.dart';

void main(){WidgetsFlutterBinding.ensureInitialized();runApp(FinanceApp(api:FinanceApi(endpoint:Uri.parse(const String.fromEnvironment('API_BASE')),vault:const SecureTokenVault())));}
class FinanceApp extends StatefulWidget {
  const FinanceApp({super.key,required this.api});final FinanceApi api;
  @override
  State<FinanceApp> createState()=>_FinanceAppState();
}
class _FinanceAppState extends State<FinanceApp> {
  Locale _locale=const Locale('en');
  @override
  Widget build(BuildContext context)=>MaterialApp(
    debugShowCheckedModeBanner:false,title:'Finance 360',theme:financeTheme(),locale:_locale,supportedLocales:const [Locale('en'),Locale('hi')],localizationsDelegates:GlobalMaterialLocalizations.delegates,
    home:SessionGate(api:widget.api,onLanguage:()=>setState(()=>_locale=Locale(_locale.languageCode=='en'?'hi':'en'))),
  );
}
class SessionGate extends StatefulWidget {
  const SessionGate({super.key,required this.api,required this.onLanguage});final FinanceApi api;final VoidCallback onLanguage;
  @override
  State<SessionGate> createState()=>_SessionGateState();
}
class _SessionGateState extends State<SessionGate> {
  late Future<void> _restore;
  @override
  void initState(){super.initState();_restore=widget.api.restore();}
  @override
  Widget build(BuildContext context)=>FutureBuilder<void>(future:_restore,builder:(context,snapshot){
    if(snapshot.connectionState!=ConnectionState.done)return const Scaffold(body:Center(child:CircularProgressIndicator()));
    if(snapshot.hasError)return Scaffold(body:ErrorPanel(message:'Secure session storage is unavailable. Please retry.',onRetry:()=>setState(()=>_restore=widget.api.restore())));
    return widget.api.signedIn?MembershipScreen(api:widget.api,onLogout:()=>setState((){})):AuthScreen(api:widget.api,onAuthenticated:()=>setState((){}),onLanguage:widget.onLanguage);
  });
}
