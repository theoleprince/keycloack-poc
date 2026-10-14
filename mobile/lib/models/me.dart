class Me {
  const Me({
    required this.localUserId,
    required this.name,
    required this.roles,
    required this.companySlug,
    required this.companyName,
    required this.authMethod,
    this.username,
    this.sub,
  });

  final int localUserId;
  final String name;
  final String? username;
  final String? sub;
  final List<String> roles;
  final String companySlug;
  final String companyName;
  final String authMethod;

  factory Me.fromJson(Map<String, dynamic> json) {
    final company = (json['company'] as Map?)?.cast<String, dynamic>() ?? {};
    return Me(
      localUserId: json['local_user_id'] as int,
      name: (json['name'] ?? '') as String,
      username: json['username'] as String?,
      sub: json['sub'] as String?,
      roles: ((json['roles'] as List?) ?? const []).whereType<String>().toList(),
      companySlug: (company['slug'] ?? '') as String,
      companyName: (company['name'] ?? '') as String,
      authMethod: (json['auth_method'] ?? '') as String,
    );
  }
}
