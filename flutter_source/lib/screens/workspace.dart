import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/scope.dart';
import '../core/strings.dart';

class MembershipScreen extends StatefulWidget {
  const MembershipScreen({super.key, required this.api, required this.onLogout});
  final FinanceApi api;
  final VoidCallback onLogout;
  @override
  State<MembershipScreen> createState() => _MembershipScreenState();
}
class _MembershipScreenState extends State<MembershipScreen> {
  late Future<Map<String, dynamic>> _future;
  @override
  void initState() { super.initState(); _reload(); }
  void _reload() { _future = widget.api.request('memberships'); }
  Future<void> _logout() async {
    try { await widget.api.logout(); }
    catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Signed out of this device. Server revocation could not be confirmed.')));
    }
    if (mounted) widget.onLogout();
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(tr(context,'Choose restaurant','रेस्टोरेंट चुनें')), actions:[IconButton(tooltip:tr(context,'Sign out','साइन आउट'),onPressed:_logout,icon:const Icon(Icons.logout))]),
    body: FutureBuilder<Map<String,dynamic>>(future:_future,builder:(context,snapshot){
      if(snapshot.connectionState!=ConnectionState.done)return const Center(child:CircularProgressIndicator());
      if(snapshot.hasError)return ErrorPanel(message:snapshot.error.toString(),onRetry:()=>setState(_reload));
      final memberships=snapshot.data!['memberships'] as List<dynamic>;
      if(memberships.isEmpty)return ErrorPanel(message:tr(context,'No restaurant or branch is currently assigned to you.','अभी आपको कोई रेस्टोरेंट या ब्रांच नहीं दी गई है।'),onRetry:()=>setState(_reload));
      return Center(child:ConstrainedBox(constraints:const BoxConstraints(maxWidth:680),child:ListView.separated(
        padding:const EdgeInsets.all(20),itemCount:memberships.length,separatorBuilder:(_,index)=>const SizedBox(height:12),
        itemBuilder:(context,index){final m=memberships[index] as Map<String,dynamic>;return Card(child:ListTile(
          minVerticalPadding:20,leading:const Icon(Icons.storefront),title:Text(m['restaurant_name'] as String),subtitle:Text(m['branch_name'] as String),trailing:const Icon(Icons.chevron_right),onTap:() async {
            widget.api.selectScope(int.parse('${m['tenant_id']}'),int.parse('${m['branch_id']}'));
            await Navigator.of(context).push<void>(MaterialPageRoute(builder:(_)=>WorkspaceScreen(api:widget.api,onLogout:widget.onLogout)));
            widget.api.tenantId=null;widget.api.branchId=null;
            if(mounted && widget.api.signedIn)setState(_reload);
          },
        ));},
      )));
    }),
  );
}

class WorkspaceScreen extends StatefulWidget {
  const WorkspaceScreen({super.key,required this.api,required this.onLogout});
  final FinanceApi api;
  final VoidCallback onLogout;
  @override
  State<WorkspaceScreen> createState()=>_WorkspaceScreenState();
}
class _WorkspaceScreenState extends State<WorkspaceScreen> with WidgetsBindingObserver {
  late Future<Map<String,dynamic>> _context;
  int _selected=0;
  @override
  void initState(){super.initState();WidgetsBinding.instance.addObserver(this);_reload();}
  @override
  void dispose(){WidgetsBinding.instance.removeObserver(this);super.dispose();}
  @override
  void didChangeAppLifecycleState(AppLifecycleState state){if(state==AppLifecycleState.resumed && mounted)setState(_reload);}
  void _reload(){_context=widget.api.request('context');}
  Future<void> _logout()async{
    try{await widget.api.logout();}catch(_){if(mounted)ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content:Text('Local session cleared. Server revocation could not be confirmed.')));}
    if(mounted){Navigator.of(context).pop();widget.onLogout();}
  }
  @override
  Widget build(BuildContext context)=>FutureBuilder<Map<String,dynamic>>(future:_context,builder:(context,snapshot){
    if(snapshot.connectionState!=ConnectionState.done)return Scaffold(appBar:AppBar(title:const Text('Finance 360')),body:const Center(child:CircularProgressIndicator()));
    if(snapshot.hasError)return Scaffold(appBar:AppBar(title:const Text('Finance 360')),body:ErrorPanel(message:snapshot.error.toString(),onRetry:()=>setState(_reload)));
    final scope=RestaurantScope(snapshot.data!);final modules=scope.modules;
    final index=_selected>=modules.length?0:_selected;
    final current=modules.isEmpty?null:modules[index];
    String label(String module)=>switch(module){'branches'=>tr(context,'Branches','ब्रांच'),'subscription'=>tr(context,'Subscription','सब्सक्रिप्शन'),_=>tr(context,'Restaurant','रेस्टोरेंट')};
    IconData icon(String module)=>switch(module){'branches'=>Icons.account_tree_outlined,'subscription'=>Icons.event_available_outlined,_=>Icons.storefront_outlined};
    final page=Padding(padding:const EdgeInsets.all(20),child:Center(child:ConstrainedBox(constraints:const BoxConstraints(maxWidth:760),child:
      current=='branches'?BranchPanel(api:widget.api,scope:scope):current=='subscription'?SubscriptionPanel(scope:scope):current=='account'?AccountPanel(api:widget.api,scope:scope,onChanged:()=>setState(_reload)):ErrorPanel(message:tr(context,'No modules are currently authorized. Contact your administrator.','अभी कोई module अधिकृत नहीं है। Administrator से संपर्क करें।'),onRetry:()=>setState(_reload))
    )));
    return Scaffold(
      appBar:AppBar(title:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(scope.restaurant,overflow:TextOverflow.ellipsis),Text('${scope.branch} · Finance 360',style:Theme.of(context).textTheme.bodySmall)]),actions:[IconButton(tooltip:tr(context,'Refresh permissions','Permissions refresh करें'),onPressed:()=>setState(_reload),icon:const Icon(Icons.refresh)),IconButton(tooltip:tr(context,'Sign out','साइन आउट'),onPressed:_logout,icon:const Icon(Icons.logout))]),
      body:LayoutBuilder(builder:(context,constraints){
        if(constraints.maxWidth<840 || modules.length<2)return page;
        return Row(children:[NavigationRail(selectedIndex:index,labelType:NavigationRailLabelType.all,onDestinationSelected:(v)=>setState(()=>_selected=v),destinations:[for(final m in modules)NavigationRailDestination(icon:Icon(icon(m)),label:Text(label(m)))]),const VerticalDivider(width:1),Expanded(child:page)]);
      }),
      bottomNavigationBar:MediaQuery.sizeOf(context).width<840 && modules.length>1?NavigationBar(selectedIndex:index,onDestinationSelected:(v)=>setState(()=>_selected=v),destinations:[for(final m in modules)NavigationDestination(icon:Icon(icon(m)),label:label(m))]):null,
    );
  });
}

class ErrorPanel extends StatelessWidget {
  const ErrorPanel({super.key,required this.message,required this.onRetry});
  final String message;final VoidCallback onRetry;
  @override
  Widget build(BuildContext context)=>Center(child:Padding(padding:const EdgeInsets.all(24),child:Column(mainAxisSize:MainAxisSize.min,children:[const Icon(Icons.info_outline,size:40),const SizedBox(height:16),Semantics(liveRegion:true,child:Text(message,textAlign:TextAlign.center)),const SizedBox(height:16),FilledButton(onPressed:onRetry,child:Text(tr(context,'Try again','फिर कोशिश करें')))])));
}

class AccountPanel extends StatefulWidget {
  const AccountPanel({super.key,required this.api,required this.scope,required this.onChanged});
  final FinanceApi api;final RestaurantScope scope;final VoidCallback onChanged;
  @override
  State<AccountPanel> createState()=>_AccountPanelState();
}
class _AccountPanelState extends State<AccountPanel> {
  bool _busy=false;String? _message;
  Future<void> _rename()async{
    final controller=TextEditingController(text:widget.scope.restaurant);
    final name=await showDialog<String>(context:context,builder:(context)=>AlertDialog(title:Text(tr(context,'Restaurant name','रेस्टोरेंट का नाम')),content:TextField(controller:controller,maxLength:160,decoration:const InputDecoration(labelText:'Name')),actions:[TextButton(onPressed:()=>Navigator.pop(context),child:Text(tr(context,'Cancel','रद्द करें'))),FilledButton(onPressed:()=>Navigator.pop(context,controller.text.trim()),child:Text(tr(context,'Save','सेव करें')))]));
    // Defer disposal until the dialog route has left the tree.
    WidgetsBinding.instance.addPostFrameCallback((_){controller.dispose();});
    if(name==null||name.isEmpty||!mounted)return;
    setState(()=>_busy=true);
    try{await widget.api.request('branding',body:{'name':name,'language':widget.scope.data['language']});if(mounted)widget.onChanged();}
    catch(e){if(mounted)setState(()=>_message=e.toString());}
    finally{if(mounted)setState(()=>_busy=false);}
  }
  Future<void> _deleteRequest()async{
    final accepted=await showDialog<bool>(context:context,builder:(context)=>AlertDialog(title:Text(tr(context,'Request account deletion?','Account deletion request?')),content:const Text('Your request will be reviewed. Financial retention requirements may apply. Records will not be silently deleted.'),actions:[TextButton(onPressed:()=>Navigator.pop(context,false),child:const Text('Cancel')),FilledButton(onPressed:()=>Navigator.pop(context,true),child:const Text('Request review'))]));
    if(accepted!=true||!mounted)return;
    setState(()=>_busy=true);
    try{final r=await widget.api.request('account/deletion-request',body:{});if(mounted)setState(()=>_message=r['notice'] as String);}
    catch(e){if(mounted)setState(()=>_message=e.toString());}
    finally{if(mounted)setState(()=>_busy=false);}
  }
  @override
  Widget build(BuildContext context)=>ListView(children:[
    Text(widget.scope.restaurant,style:Theme.of(context).textTheme.headlineMedium),const SizedBox(height:8),Text(widget.scope.branch),const SizedBox(height:20),
    if(widget.scope.verification!='verified')Card(child:Padding(padding:const EdgeInsets.all(20),child:Text(tr(context,'Identity verification is pending. Contact the platform administrator with your restaurant details.','पहचान का verification बाकी है। अपने restaurant details के साथ platform administrator से संपर्क करें।')))),
    if(!widget.scope.financialReady)Card(child:Padding(padding:const EdgeInsets.all(20),child:Text(tr(context,'Your financial workspace is not available in this early-access build. Existing financial records have not been imported into this app.','इस early-access build में financial workspace उपलब्ध नहीं है। Existing financial records इस app में import नहीं किए गए हैं।')))),
    const SizedBox(height:16),
    if(widget.scope.can('tenant.branding'))FilledButton.icon(onPressed:_busy?null:_rename,icon:const Icon(Icons.edit_outlined),label:Text(tr(context,'Edit restaurant name','रेस्टोरेंट का नाम बदलें'))),
    const SizedBox(height:12),TextButton(onPressed:_busy?null:_deleteRequest,child:Text(tr(context,'Request account deletion','Account deletion request भेजें'))),
    if(_message!=null)Semantics(liveRegion:true,child:Text(_message!)),
  ]);
}

class SubscriptionPanel extends StatelessWidget {
  const SubscriptionPanel({super.key,required this.scope});final RestaurantScope scope;
  String indiaTime(String iso){final d=DateTime.parse(iso).toUtc().add(const Duration(hours:5,minutes:30));return '${d.toIso8601String().substring(0,16).replaceAll('T',' ')} IST';}
  @override
  Widget build(BuildContext context){final s=scope.data['subscription'] as Map<String,dynamic>;return ListView(children:[
    Text(tr(context,'Your subscription','आपका सब्सक्रिप्शन'),style:Theme.of(context).textTheme.headlineMedium),const SizedBox(height:20),
    Card(child:Padding(padding:const EdgeInsets.all(20),child:Column(crossAxisAlignment:CrossAxisAlignment.start,children:[Text(scope.state,style:Theme.of(context).textTheme.titleLarge),const SizedBox(height:12),Text('${tr(context,'Trial ends','ट्रायल समाप्त')}: ${indiaTime(s['trial_ends_at'] as String)}'),const SizedBox(height:8),Text('${tr(context,'Branch limit','ब्रांच सीमा')}: ${s['branch_limit']}'),Text('${tr(context,'User limit','यूज़र सीमा')}: ${s['user_limit']}')]))),
    const SizedBox(height:12),Text(tr(context,'Paid checkout is not enabled in this build. No payment is collected. When trial access expires, authorized records remain available to view; new posting is blocked.','इस build में paid checkout चालू नहीं है। कोई payment नहीं लिया जाएगा। Trial खत्म होने पर अधिकृत records देखने को मिलेंगे; नई posting बंद रहेगी।')),
  ]);}
}

class BranchPanel extends StatefulWidget {
  const BranchPanel({super.key,required this.api,required this.scope});final FinanceApi api;final RestaurantScope scope;
  @override
  State<BranchPanel> createState()=>_BranchPanelState();
}
class _BranchPanelState extends State<BranchPanel> {
  late Future<Map<String,dynamic>> _future;bool _busy=false;String? _error;
  @override
  void initState(){super.initState();_future=widget.api.request('branches');}
  Future<void> _create()async{
    final name=TextEditingController();final address=TextEditingController();
    final data=await showDialog<Map<String,dynamic>>(context:context,builder:(context)=>AlertDialog(title:Text(tr(context,'New branch','नई ब्रांच')),content:SingleChildScrollView(child:Column(mainAxisSize:MainAxisSize.min,children:[TextField(controller:name,maxLength:160,decoration:const InputDecoration(labelText:'Branch name')),const SizedBox(height:12),TextField(controller:address,maxLength:500,decoration:const InputDecoration(labelText:'Address'))])),actions:[TextButton(onPressed:()=>Navigator.pop(context),child:const Text('Cancel')),FilledButton(onPressed:()=>Navigator.pop(context,{'name':name.text.trim(),'address':address.text.trim(),'cutoff_hour':4}),child:const Text('Create'))]));
    WidgetsBinding.instance.addPostFrameCallback((_){name.dispose();address.dispose();});
    if(data==null||!mounted)return;
    setState((){_busy=true;_error=null;});
    try{await widget.api.request('branches/create',body:data);if(mounted)setState(()=>_future=widget.api.request('branches'));}
    catch(e){if(mounted)setState(()=>_error=e.toString());}
    finally{if(mounted)setState(()=>_busy=false);}
  }
  @override
  Widget build(BuildContext context)=>Column(crossAxisAlignment:CrossAxisAlignment.start,children:[
    Text(tr(context,'Branches','ब्रांच'),style:Theme.of(context).textTheme.headlineMedium),const SizedBox(height:16),
    if(widget.scope.can('branches.create'))FilledButton.icon(onPressed:_busy?null:_create,icon:const Icon(Icons.add),label:Text(tr(context,'Add branch','ब्रांच जोड़ें'))),
    if(_error!=null)Padding(padding:const EdgeInsets.symmetric(vertical:12),child:Text(_error!)),const SizedBox(height:16),
    Expanded(child:FutureBuilder<Map<String,dynamic>>(future:_future,builder:(context,snapshot){
      if(snapshot.connectionState!=ConnectionState.done)return const Center(child:CircularProgressIndicator());
      if(snapshot.hasError)return ErrorPanel(message:snapshot.error.toString(),onRetry:()=>setState(()=>_future=widget.api.request('branches')));
      final rows=snapshot.data!['branches'] as List<dynamic>;
      return ListView(children:[for(final row in rows)Card(child:ListTile(leading:const Icon(Icons.storefront),title:Text(row['name'] as String),subtitle:Text(row['address'] as String)))]);
    })),
  ]);
}
