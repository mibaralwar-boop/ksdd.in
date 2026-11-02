import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:finance360/core/api.dart';
class MemoryVault implements TokenVault {
  Map<String,dynamic>? value;
  @override
  Future<Map<String,dynamic>?> read()async=>value;
  @override
  Future<void> write(Map<String,dynamic> tokens)async{value=tokens;}
  @override
  Future<void> clear()async{value=null;}
}
void main(){
  test('Selected scope uses explicit headers and bearer token',()async{
    final vault=MemoryVault()..value={'access_token':'access','refresh_token':'refresh'};
    final api=FinanceApi(endpoint:Uri.parse('https://fixture.invalid/f28-api.php'),vault:vault,client:MockClient((request)async{
      expect(request.headers['Authorization'],'Bearer access');
      expect(request.headers['X-Tenant-ID'],'11');expect(request.headers['X-Branch-ID'],'12');
      expect(request.url.queryParameters['route'],'/v1/context');
      return http.Response(jsonEncode({'data':{'authorized':true}}),200);
    }));
    await api.restore();api.selectScope(11,12);
    expect((await api.request('context'))['authorized'],true);
    api.close();
  });
  test('A denied request does not retry or infer owner access',()async{
    final vault=MemoryVault()..value={'access_token':'access','refresh_token':'refresh'};
    var calls=0;
    final api=FinanceApi(endpoint:Uri.parse('https://fixture.invalid/f28-api.php'),vault:vault,client:MockClient((request)async{
      calls++;return http.Response(jsonEncode({'error':{'code':'scope_denied','message':'Denied'}}),403);
    }));
    await api.restore();
    await expectLater(api.request('context'),throwsA(isA<ApiFailure>().having((e)=>e.code,'code','scope_denied')));
    expect(calls,1);api.close();
  });
  test('Expired access rotates refresh once and persists new tokens',()async{
    final vault=MemoryVault()..value={'access_token':'old','refresh_token':'refresh'};
    var refreshes=0;
    final api=FinanceApi(endpoint:Uri.parse('https://fixture.invalid/f28-api.php'),vault:vault,client:MockClient((request)async{
      if(request.url.queryParameters['route']=='/v1/refresh'){
        refreshes++;return http.Response(jsonEncode({'data':{'access_token':'new','refresh_token':'new-refresh'}}),200);
      }
      if(request.headers['Authorization']=='Bearer old')return http.Response(jsonEncode({'error':{'code':'unauthenticated','message':'Expired'}}),401);
      return http.Response(jsonEncode({'data':{'ok':true}}),200);
    }));
    await api.restore();expect((await api.request('memberships'))['ok'],true);
    expect(refreshes,1);expect(vault.value!['access_token'],'new');api.close();
  });
  test('Local sign-out clears tokens even when server is unreachable',()async{
    final vault=MemoryVault()..value={'access_token':'old','refresh_token':'refresh'};
    final api=FinanceApi(endpoint:Uri.parse('https://fixture.invalid/f28-api.php'),vault:vault,client:MockClient((_)async=>throw http.ClientException('offline')));
    await api.restore();api.selectScope(1,2);
    await expectLater(api.logout(),throwsA(isA<ApiFailure>()));
    expect(vault.value,isNull);expect(api.tenantId,isNull);expect(api.branchId,isNull);api.close();
  });
}
