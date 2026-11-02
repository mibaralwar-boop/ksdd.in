import 'package:flutter_test/flutter_test.dart';
import 'package:finance360/core/scope.dart';
void main(){
  test('Modules come only from explicit backend permissions',(){
    final scope=RestaurantScope({'permissions':['branches.view'],'financial_scope_state':'blocked'});
    expect(scope.modules,['branches']);
    expect(scope.can('payroll.view'),false);
  });
  test('Revoked membership has no visible modules',(){
    final scope=RestaurantScope({'permissions':<String>[],'financial_scope_state':'blocked'});
    expect(scope.modules,isEmpty);
  });
  test('No financial readiness is inferred from owner role',(){
    final scope=RestaurantScope({'role':'owner','permissions':['tenant.view'],'financial_scope_state':'blocked'});
    expect(scope.financialReady,false);
    expect(scope.can('closing.finalize'),false);
  });
}
