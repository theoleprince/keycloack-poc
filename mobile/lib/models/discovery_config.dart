/// Résultat de la découverte : clé société -> backend + Keycloak.
class DiscoveryConfig {
  const DiscoveryConfig({
    required this.companySlug,
    required this.apiUrl,
    required this.keycloakIssuer,
    required this.clientId,
    required this.orgAlias,
  });

  final String companySlug;
  final String apiUrl;
  final String keycloakIssuer;
  final String clientId;
  final String orgAlias;

  factory DiscoveryConfig.fromJson(Map<String, dynamic> json) {
    String req(String key) {
      final value = json[key];
      if (value is! String || value.isEmpty) {
        throw FormatException('Champ de découverte manquant : $key');
      }
      return value;
    }

    return DiscoveryConfig(
      companySlug: req('company_slug'),
      apiUrl: req('api_url'),
      keycloakIssuer: req('keycloak_issuer'),
      clientId: req('client_id'),
      orgAlias: req('org_alias'),
    );
  }

  Map<String, dynamic> toJson() => {
        'company_slug': companySlug,
        'api_url': apiUrl,
        'keycloak_issuer': keycloakIssuer,
        'client_id': clientId,
        'org_alias': orgAlias,
      };

  /// Scopes demandés : `organization:<alias>` présélectionne la société
  /// (vérifié sur Keycloak 26.7 : si l'utilisateur n'en est pas membre, le
  /// claim `organization` est absent et l'API répond NO_COMPANY).
  List<String> get scopes => [
        'openid',
        'profile',
        'email',
        'offline_access',
        'organization:$orgAlias',
      ];
}
