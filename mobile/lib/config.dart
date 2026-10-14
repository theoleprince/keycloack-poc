/// Configuration statique de l'app (le reste vient de la découverte).
class AppConfig {
  /// Serveur de découverte (mock du "serveur X"). Émulateur Android -> hôte.
  /// Surcharge : flutter run --dart-define=DISCOVERY_URL=http://...
  static const discoveryUrl = String.fromEnvironment(
    'DISCOVERY_URL',
    defaultValue: 'http://10.0.2.2:8000',
  );

  static const redirectUrl = 'com.example.pocapp://login-callback';
  static const postLogoutRedirectUrl = 'com.example.pocapp://logout-callback';

  /// DEV : Keycloak et l'API sont en HTTP. À passer à false avec HTTPS.
  static const allowInsecureConnections = bool.fromEnvironment(
    'ALLOW_INSECURE',
    defaultValue: true,
  );

  /// Rafraîchir le jeton s'il expire dans moins de ce délai.
  static const refreshMargin = Duration(seconds: 30);

  static const httpTimeout = Duration(seconds: 8);
}
