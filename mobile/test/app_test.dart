import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pocapp/models/app_error.dart';
import 'package:pocapp/models/discovery_config.dart';
import 'package:pocapp/models/me.dart';
import 'package:pocapp/screens/company_key_screen.dart';
import 'package:pocapp/services/api_client.dart';
import 'package:pocapp/services/auth_service.dart';
import 'package:pocapp/services/discovery_service.dart';
import 'package:pocapp/services/secure_store.dart';

const _config = DiscoveryConfig(
  companySlug: 'acme',
  apiUrl: 'http://api.test',
  keycloakIssuer: 'http://kc.test/realms/plateforme',
  clientId: 'projet-1',
  orgAlias: 'acme',
);

String _jwtExpiringIn(Duration d) {
  String b64(Map<String, dynamic> m) => base64Url.encode(utf8.encode(jsonEncode(m))).replaceAll('=', '');
  final exp = DateTime.now().add(d).millisecondsSinceEpoch ~/ 1000;
  return '${b64({'alg': 'RS256', 'typ': 'JWT'})}.${b64({'exp': exp, 'sub': 'x'})}.sig';
}

/// Adaptateur HTTP scripté : (méthode, chemin, Authorization) -> (statut, corps).
class _ScriptedAdapter implements HttpClientAdapter {
  _ScriptedAdapter(this.handler);

  final (int, Object?) Function(RequestOptions) handler;
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    final (status, body) = handler(options);
    if (status == 0) {
      throw DioException.connectionError(requestOptions: options, reason: 'refused');
    }
    return ResponseBody.fromString(jsonEncode(body), status, headers: {
      Headers.contentTypeHeader: ['application/json'],
    });
  }

  @override
  void close({bool force = false}) {}
}

/// AuthService factice : jetons "old" puis "new" après refresh forcé.
class _FakeAuth extends AuthService {
  _FakeAuth({this.refreshError}) : super(SecureStore());

  final AppError? refreshError;
  int refreshes = 0;
  String current = 'old-token';

  @override
  Future<String> validAccessToken(DiscoveryConfig cfg, {bool forceRefresh = false}) async {
    if (forceRefresh) {
      refreshes++;
      if (refreshError != null) throw refreshError!;
      current = 'new-token';
    }
    return current;
  }
}

ApiClient _api(_ScriptedAdapter adapter, AuthService auth) {
  final dio = Dio(BaseOptions(baseUrl: _config.apiUrl))..httpClientAdapter = adapter;
  return ApiClient(_config, auth, dio: dio);
}

void main() {
  group('DiscoveryConfig', () {
    test('parse la réponse et demande le scope organisation présélectionné', () {
      final cfg = DiscoveryConfig.fromJson(_config.toJson());
      expect(cfg.orgAlias, 'acme');
      expect(cfg.scopes, containsAll(['openid', 'offline_access', 'organization:acme']));
    });

    test('rejette une réponse incomplète', () {
      expect(() => DiscoveryConfig.fromJson({'company_slug': 'acme'}), throwsFormatException);
    });
  });

  group('AppError.fromApi', () {
    test('codes société explicites et bloquants', () {
      for (final code in ['NO_COMPANY', 'COMPANY_MISMATCH', 'MULTIPLE_COMPANIES']) {
        final e = AppError.fromApi(403, code);
        expect(e.code, code);
        expect(e.kind, AppErrorKind.companyDenied);
        expect(e.blocksAccess, isTrue);
      }
    });

    test('503 = service d\'authentification indisponible, aucun accès', () {
      final e = AppError.fromApi(503, 'AUTH_PROVIDER_UNAVAILABLE');
      expect(e.message, contains('Service d\'authentification indisponible'));
      expect(e.blocksAccess, isTrue);
    });

    test('403 de rôle non bloquant', () {
      expect(AppError.fromApi(403, 'FORBIDDEN_ROLE').blocksAccess, isFalse);
    });
  });

  group('AuthService.needsRefresh', () {
    test('rafraîchit avant expiration', () {
      expect(AuthService.needsRefresh(_jwtExpiringIn(const Duration(seconds: 10)), DateTime.now()), isTrue);
      expect(AuthService.needsRefresh(_jwtExpiringIn(const Duration(minutes: 5)), DateTime.now()), isFalse);
    });

    test('jeton illisible -> refresh', () {
      expect(AuthService.needsRefresh('garbage', DateTime.now()), isTrue);
    });
  });

  group('ApiClient', () {
    test('sur 401 : refresh puis un seul nouvel essai', () async {
      final adapter = _ScriptedAdapter((o) => o.headers['Authorization'] == 'Bearer new-token'
          ? (200, {'local_user_id': 1, 'name': 'Alice', 'roles': ['user'], 'company': {'slug': 'acme', 'name': 'ACME'}, 'auth_method': 'keycloak'})
          : (401, {'error': 'INVALID_TOKEN'}));
      final auth = _FakeAuth();

      final Me me = await _api(adapter, auth).me();

      expect(me.localUserId, 1);
      expect(auth.refreshes, 1);
      expect(adapter.requests, hasLength(2));
    });

    test('refresh impossible -> session expirée', () async {
      final adapter = _ScriptedAdapter((_) => (401, {'error': 'INVALID_TOKEN'}));
      final auth = _FakeAuth(refreshError: AppError.sessionExpired);

      expect(() => _api(adapter, auth).notes(), throwsA(AppError.sessionExpired));
    });

    test('503 côté API -> erreur bloquante, aucune donnée', () async {
      final adapter = _ScriptedAdapter((_) => (503, {'error': 'AUTH_PROVIDER_UNAVAILABLE'}));

      await expectLater(
        _api(adapter, _FakeAuth()).notes(),
        throwsA(isA<AppError>().having((e) => e.kind, 'kind', AppErrorKind.authUnavailable)),
      );
    });

    test('NO_COMPANY / COMPANY_MISMATCH remontés tels quels', () async {
      for (final code in ['NO_COMPANY', 'COMPANY_MISMATCH']) {
        final adapter = _ScriptedAdapter((_) => (403, {'error': code}));
        await expectLater(
          _api(adapter, _FakeAuth()).me(),
          throwsA(isA<AppError>().having((e) => e.code, 'code', code)),
        );
      }
    });

    test('API injoignable', () async {
      final adapter = _ScriptedAdapter((_) => (0, null));
      await expectLater(_api(adapter, _FakeAuth()).me(), throwsA(AppError.apiUnreachable));
    });
  });

  group('DiscoveryService', () {
    test('clé inconnue -> message générique', () async {
      final dio = Dio(BaseOptions(baseUrl: 'http://disco.test'))
        ..httpClientAdapter = _ScriptedAdapter((_) => (404, {'error': 'NOT_FOUND'}));
      await expectLater(DiscoveryService(dio: dio).discover('NOPE'), throwsA(AppError.unknownCompanyKey));
    });

    test('clé connue -> configuration', () async {
      final dio = Dio(BaseOptions(baseUrl: 'http://disco.test'))
        ..httpClientAdapter = _ScriptedAdapter((o) => (200, _config.toJson()));
      final cfg = await DiscoveryService(dio: dio).discover(' ACME-7K2P-DEV ');
      expect(cfg.clientId, 'projet-1');
    });
  });

  testWidgets('écran clé société affiche l\'erreur', (tester) async {
    await tester.pumpWidget(const ProviderScope(
      child: MaterialApp(home: CompanyKeyScreen(error: AppError.unknownCompanyKey)),
    ));

    expect(find.byKey(const Key('company-key-field')), findsOneWidget);
    expect(find.text('Clé société inconnue.'), findsOneWidget);
  });
}
