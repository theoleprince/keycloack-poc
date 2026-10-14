import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/discovery_config.dart';

/// Jetons et configuration société, en stockage sécurisé
/// (Keystore Android / Keychain iOS). Jamais en SharedPreferences.
class SecureStore {
  SecureStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _kDiscovery = 'discovery';
  static const _kAccess = 'access_token';
  static const _kRefresh = 'refresh_token';
  static const _kId = 'id_token';

  Future<DiscoveryConfig?> readDiscovery() async {
    final raw = await _storage.read(key: _kDiscovery);
    if (raw == null) return null;
    try {
      return DiscoveryConfig.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      await _storage.delete(key: _kDiscovery);
      return null;
    }
  }

  Future<void> saveDiscovery(DiscoveryConfig config) =>
      _storage.write(key: _kDiscovery, value: jsonEncode(config.toJson()));

  Future<String?> get accessToken => _storage.read(key: _kAccess);
  Future<String?> get refreshToken => _storage.read(key: _kRefresh);
  Future<String?> get idToken => _storage.read(key: _kId);

  Future<void> saveTokens({required String accessToken, String? refreshToken, String? idToken}) async {
    await _storage.write(key: _kAccess, value: accessToken);
    if (refreshToken != null) await _storage.write(key: _kRefresh, value: refreshToken);
    if (idToken != null) await _storage.write(key: _kId, value: idToken);
  }

  /// Déconnexion : supprime les jetons, garde la société choisie.
  Future<void> clearTokens() async {
    await _storage.delete(key: _kAccess);
    await _storage.delete(key: _kRefresh);
    await _storage.delete(key: _kId);
  }

  /// "Changer de société" : purge complète.
  Future<void> purgeAll() => _storage.deleteAll();
}
