import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:flutter_appauth/flutter_appauth.dart';
import 'package:jwt_decoder/jwt_decoder.dart';

import '../config.dart';
import '../models/app_error.dart';
import '../models/discovery_config.dart';
import 'secure_store.dart';

/// OIDC avec Keycloak : Authorization Code + PKCE (S256, géré par AppAuth),
/// mot de passe + TOTP saisis dans le navigateur système (jamais dans l'app).
///
/// Fail-closed : Keycloak injoignable -> AppError.authUnavailable, aucune
/// donnée n'est servie et aucun mode dégradé n'est proposé.
class AuthService {
  AuthService(this._store, {FlutterAppAuth? appAuth, Dio? probe})
      : _appAuth = appAuth ?? const FlutterAppAuth(),
        _probe = probe ??
            Dio(BaseOptions(
              connectTimeout: const Duration(seconds: 5),
              receiveTimeout: const Duration(seconds: 5),
            ));

  final SecureStore _store;
  final FlutterAppAuth _appAuth;
  final Dio _probe;

  Future<String>? _refreshing;

  /// Vrai si le jeton doit être rafraîchi avant usage.
  static bool needsRefresh(String accessToken, DateTime now, {Duration margin = AppConfig.refreshMargin}) {
    try {
      return JwtDecoder.getExpirationDate(accessToken).isBefore(now.add(margin));
    } catch (_) {
      return true;
    }
  }

  Future<bool> hasSession() async => (await _store.refreshToken) != null || (await _store.accessToken) != null;

  Future<void> login(DiscoveryConfig cfg) async {
    await _ensureProviderReachable(cfg);

    try {
      final result = await _appAuth.authorizeAndExchangeCode(
        AuthorizationTokenRequest(
          cfg.clientId,
          AppConfig.redirectUrl,
          issuer: cfg.keycloakIssuer,
          scopes: cfg.scopes,
          allowInsecureConnections: AppConfig.allowInsecureConnections,
        ),
      );
      final access = result.accessToken;
      if (access == null) throw AppError.authUnavailable;
      await _store.saveTokens(accessToken: access, refreshToken: result.refreshToken, idToken: result.idToken);
    } on FlutterAppAuthUserCancelledException {
      throw AppError.cancelled;
    } on PlatformException {
      // Échec réseau pendant le flux -> on distingue panne / autre erreur.
      await _ensureProviderReachable(cfg);
      throw const AppError(AppErrorKind.other, 'Échec de la connexion. Réessayez.');
    }
  }

  /// Jeton valide pour appeler l'API (rafraîchi si proche de l'expiration).
  Future<String> validAccessToken(DiscoveryConfig cfg, {bool forceRefresh = false}) async {
    final access = await _store.accessToken;
    if (!forceRefresh && access != null && !needsRefresh(access, DateTime.now())) {
      return access;
    }
    // Une seule requête de refresh à la fois (appels API concurrents).
    return _refreshing ??= _refresh(cfg).whenComplete(() => _refreshing = null);
  }

  Future<String> _refresh(DiscoveryConfig cfg) async {
    final refreshToken = await _store.refreshToken;
    if (refreshToken == null) throw AppError.sessionExpired;

    try {
      final result = await _appAuth.token(TokenRequest(
        cfg.clientId,
        AppConfig.redirectUrl,
        refreshToken: refreshToken,
        issuer: cfg.keycloakIssuer,
        scopes: cfg.scopes,
        allowInsecureConnections: AppConfig.allowInsecureConnections,
      ));
      final access = result.accessToken;
      if (access == null) throw AppError.sessionExpired;
      await _store.saveTokens(accessToken: access, refreshToken: result.refreshToken, idToken: result.idToken);
      return access;
    } on PlatformException {
      // Keycloak injoignable : aucun accès, on garde les jetons pour réessayer.
      await _ensureProviderReachable(cfg);
      // Keycloak répond mais refuse le refresh (invalid_grant, session révoquée).
      await _store.clearTokens();
      throw AppError.sessionExpired;
    }
  }

  /// Déconnexion : end-session Keycloak (best effort) puis purge des jetons.
  Future<void> logout(DiscoveryConfig cfg) async {
    final idToken = await _store.idToken;
    try {
      await _appAuth.endSession(EndSessionRequest(
        idTokenHint: idToken,
        postLogoutRedirectUrl: AppConfig.postLogoutRedirectUrl,
        issuer: cfg.keycloakIssuer,
        allowInsecureConnections: AppConfig.allowInsecureConnections,
      ));
    } catch (_) {
      // Même si Keycloak est injoignable, la purge locale a lieu.
    } finally {
      await _store.clearTokens();
    }
  }

  Future<void> _ensureProviderReachable(DiscoveryConfig cfg) async {
    try {
      final r = await _probe.get<dynamic>('${cfg.keycloakIssuer}/.well-known/openid-configuration');
      if (r.statusCode != 200) throw AppError.authUnavailable;
    } on DioException {
      throw AppError.authUnavailable;
    }
  }
}
