import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/app_error.dart';
import '../models/discovery_config.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../services/discovery_service.dart';
import '../services/secure_store.dart';

final secureStoreProvider = Provider<SecureStore>((ref) => SecureStore());
final discoveryServiceProvider = Provider<DiscoveryService>((ref) => DiscoveryService());
final authServiceProvider = Provider<AuthService>((ref) => AuthService(ref.watch(secureStoreProvider)));

/// Étapes de l'app : clé société -> login Keycloak -> accueil.
sealed class SessionState {
  const SessionState();
}

class NeedsCompanyKey extends SessionState {
  const NeedsCompanyKey([this.error]);
  final AppError? error;
}

class NeedsLogin extends SessionState {
  const NeedsLogin(this.config, [this.error]);
  final DiscoveryConfig config;
  final AppError? error;
}

class Authenticated extends SessionState {
  const Authenticated(this.config);
  final DiscoveryConfig config;
}

class SessionController extends AsyncNotifier<SessionState> {
  SecureStore get _store => ref.read(secureStoreProvider);
  AuthService get _auth => ref.read(authServiceProvider);

  @override
  Future<SessionState> build() async {
    final config = await _store.readDiscovery();
    if (config == null) return const NeedsCompanyKey();
    return await _auth.hasSession() ? Authenticated(config) : NeedsLogin(config);
  }

  Future<void> submitCompanyKey(String key) async {
    state = const AsyncLoading();
    try {
      final config = await ref.read(discoveryServiceProvider).discover(key);
      await _store.purgeAll();
      await _store.saveDiscovery(config);
      state = AsyncData(NeedsLogin(config));
    } on AppError catch (e) {
      state = AsyncData(NeedsCompanyKey(e));
    }
  }

  Future<void> login() async {
    final current = state.value;
    if (current is! NeedsLogin) return;
    state = const AsyncLoading();
    try {
      await _auth.login(current.config);
      state = AsyncData(Authenticated(current.config));
    } on AppError catch (e) {
      state = AsyncData(NeedsLogin(current.config, e));
    }
  }

  /// L'API ou Keycloak a refusé la session : retour à l'écran de login
  /// avec un message explicite (aucune donnée conservée à l'écran).
  Future<void> sessionLost(AppError error) async {
    final current = state.value;
    if (current is! Authenticated) return;
    if (error.kind == AppErrorKind.sessionExpired) await _store.clearTokens();
    state = AsyncData(NeedsLogin(current.config, error));
  }

  Future<void> logout() async {
    final current = state.value;
    if (current is! Authenticated) return;
    state = const AsyncLoading();
    await _auth.logout(current.config);
    state = AsyncData(NeedsLogin(current.config));
  }

  /// "Changer de société" : purge complète (jetons + configuration).
  Future<void> changeCompany() async {
    final current = state.value;
    if (current is Authenticated) await _auth.logout(current.config);
    await _store.purgeAll();
    state = const AsyncData(NeedsCompanyKey());
  }
}

final sessionProvider = AsyncNotifierProvider<SessionController, SessionState>(SessionController.new);

/// Client API de la société courante (disponible une fois authentifié).
final apiClientProvider = Provider<ApiClient?>((ref) {
  final session = ref.watch(sessionProvider).value;
  if (session is! Authenticated) return null;
  return ApiClient(session.config, ref.watch(authServiceProvider));
});
