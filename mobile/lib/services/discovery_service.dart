import 'package:dio/dio.dart';

import '../config.dart';
import '../models/app_error.dart';
import '../models/discovery_config.dart';

/// Mock du "serveur X" : clé société -> backend + Keycloak (sans email/OTP).
class DiscoveryService {
  DiscoveryService({Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: AppConfig.discoveryUrl,
              connectTimeout: AppConfig.httpTimeout,
              receiveTimeout: AppConfig.httpTimeout,
              headers: {'Accept': 'application/json'},
            ));

  final Dio _dio;

  Future<DiscoveryConfig> discover(String key) async {
    final trimmed = key.trim();
    if (trimmed.isEmpty) throw AppError.unknownCompanyKey;

    try {
      final r = await _dio.get<Map<String, dynamic>>('/api/discover', queryParameters: {'key': trimmed});
      return DiscoveryConfig.fromJson(r.data ?? const {});
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == null) {
        throw const AppError(AppErrorKind.apiUnreachable, 'Serveur de découverte injoignable.');
      }
      if (status == 404) throw AppError.unknownCompanyKey;
      final data = e.response?.data;
      throw AppError.fromApi(status, data is Map ? data['error'] as String? : null);
    } on FormatException {
      throw const AppError(AppErrorKind.other, 'Réponse de découverte invalide.');
    }
  }
}
