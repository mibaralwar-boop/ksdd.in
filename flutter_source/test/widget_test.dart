import 'package:flutter_test/flutter_test.dart';
import 'package:finance360/main.dart';
import 'package:finance360/core/api.dart';
import 'api_test.dart' show MemoryVault;
void main(){
  testWidgets('Unconfigured build clearly blocks sign-in and shows platform identity',(tester)async{
    final api=FinanceApi(endpoint:Uri(),vault:MemoryVault());
    await tester.pumpWidget(FinanceApp(api:api));await tester.pumpAndSettle();
    expect(find.text('Finance 360'),findsOneWidget);
    expect(find.textContaining('no configured secure server'),findsOneWidget);
    expect(find.text('Salary'),findsNothing);
    expect(find.text('Stock'),findsNothing);
    api.close();
  });
}
