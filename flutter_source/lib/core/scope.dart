class RestaurantScope {
  RestaurantScope(this.data);
  final Map<String, dynamic> data;
  String get restaurant => data['restaurant_name'] as String;
  String get branch => data['branch_name'] as String;
  String get state => (data['subscription'] as Map<String, dynamic>)['state'] as String;
  String get verification => data['verification'] as String;
  bool can(String permission) => (data['permissions'] as List<dynamic>).contains(permission);
  bool get financialReady => data['financial_scope_state'] == 'ready';
  List<String> get modules => [
    if (can('tenant.view')) 'account',
    if (can('branches.view')) 'branches',
    if (can('subscription.view')) 'subscription',
  ];
}
