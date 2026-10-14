import 'package:dio/dio.dart';

import '../config.dart';
import '../models/app_error.dart';
import '../models/discovery_config.dart';
import '../models/me.dart';
import '../models/note.dart';
import 'auth_service.dart';

/// Appels à l'API Laravel de la société, avec jeton Keycloak.
///
/// - Avant chaque requête : jeton rafraîchi s'il expire bientôt.
/// - Sur 401 : un refresh forcé puis un seul nouvel essai.
/// - Toutes les erreurs sont converties en [AppError].
class ApiClient {
  ApiClient(this._config, this._auth, {Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: _config.apiUrl,
              connectTimeout: AppConfig.httpTimeout,
              receiveTimeout: AppConfig.httpTimeout,
              headers: {'Accept': 'application/json'},
            )) {
    _dio.interceptors.add(QueuedInterceptorsWrapper(
      onRequest: (options, handler) async {
        try {
          final token = await _auth.validAccessToken(_config);
          options.headers['Authorization'] = 'Bearer $token';
          handler.next(options);
        } on AppError catch (e) {
          handler.reject(DioException(requestOptions: options, error: e));
        }
      },
      onError: (error, handler) async {
        final retried = error.requestOptions.extra['retried'] == true;
        if (error.response?.statusCode == 401 && !retried) {
          try {
            final token = await _auth.validAccessToken(_config, forceRefresh: true);
            final options = error.requestOptions
              ..headers['Authorization'] = 'Bearer $token'
              ..extra['retried'] = true;
            return handler.resolve(await _dio.fetch(options));
          } on AppError catch (e) {
            return handler.next(DioException(requestOptions: error.requestOptions, error: e));
          } on DioException catch (e) {
            return handler.next(e);
          }
        }
        handler.next(error);
      },
    ));
  }

  final DiscoveryConfig _config;
  final AuthService _auth;
  final Dio _dio;

  Future<Me> me() async => Me.fromJson(await _get('/api/me'));

  Future<List<Note>> notes() async {
    final json = await _get('/api/notes');
    return ((json['data'] as List?) ?? const [])
        .map((e) => Note.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<Note> createNote(String title, String? body) async {
    try {
      final r = await _dio.post<Map<String, dynamic>>('/api/notes', data: {'title': title, 'body': body});
      return Note.fromJson((r.data!['data'] as Map).cast<String, dynamic>());
    } on DioException catch (e) {
      throw toAppError(e);
    }
  }

  /// Nombre d'utilisateurs de la société (route admin).
  Future<int> adminUsersCount() async {
    final json = await _get('/api/admin/users');
    return ((json['data'] as List?) ?? const []).length;
  }

  Future<Map<String, dynamic>> _get(String path) async {
    try {
      final r = await _dio.get<Map<String, dynamic>>(path);
      return r.data ?? const {};
    } on DioException catch (e) {
      throw toAppError(e);
    }
  }

  static AppError toAppError(DioException e) {
    if (e.error is AppError) return e.error as AppError;

    final response = e.response;
    if (response == null) return AppError.apiUnreachable;

    final data = response.data;
    final code = data is Map ? data['error'] as String? : null;
    return AppError.fromApi(response.statusCode, code);
  }
}
