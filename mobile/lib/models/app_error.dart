/// Erreur présentable à l'utilisateur.
class AppError implements Exception {
  const AppError(this.kind, this.message, {this.code});

  final AppErrorKind kind;
  final String message;

  /// Code d'erreur renvoyé par l'API (NO_COMPANY, COMPANY_MISMATCH…).
  final String? code;

  /// Toute erreur qui interdit l'accès aux données (pas de mode dégradé).
  bool get blocksAccess =>
      kind == AppErrorKind.authUnavailable ||
      kind == AppErrorKind.sessionExpired ||
      kind == AppErrorKind.companyDenied;

  @override
  String toString() => message;

  static const cancelled = AppError(AppErrorKind.cancelled, 'Connexion annulée.');

  static const authUnavailable = AppError(
    AppErrorKind.authUnavailable,
    'Service d\'authentification indisponible. Réessayez plus tard.',
    code: 'AUTH_PROVIDER_UNAVAILABLE',
  );

  static const apiUnreachable = AppError(
    AppErrorKind.apiUnreachable,
    'Serveur de l\'application injoignable. Vérifiez votre connexion.',
  );

  static const sessionExpired = AppError(
    AppErrorKind.sessionExpired,
    'Session expirée : veuillez vous reconnecter.',
  );

  static const unknownCompanyKey = AppError(
    AppErrorKind.notFound,
    'Clé société inconnue.',
  );

  /// Traduit une réponse d'erreur de l'API (statut + code JSON).
  static AppError fromApi(int? status, String? code) {
    switch (code) {
      case 'AUTH_PROVIDER_UNAVAILABLE':
        return authUnavailable;
      case 'NO_COMPANY':
        return const AppError(
          AppErrorKind.companyDenied,
          'Votre compte n\'est rattaché à aucune société valide pour cette application.',
          code: 'NO_COMPANY',
        );
      case 'COMPANY_MISMATCH':
        return const AppError(
          AppErrorKind.companyDenied,
          'La société de votre compte ne correspond pas à la société sélectionnée.',
          code: 'COMPANY_MISMATCH',
        );
      case 'MULTIPLE_COMPANIES':
        return const AppError(
          AppErrorKind.companyDenied,
          'Votre compte est rattaché à plusieurs sociétés : non supporté.',
          code: 'MULTIPLE_COMPANIES',
        );
      case 'EMAIL_NOT_VERIFIED':
        return const AppError(
          AppErrorKind.companyDenied,
          'Email non vérifié : impossible de rattacher votre compte existant.',
          code: 'EMAIL_NOT_VERIFIED',
        );
      case 'ACCOUNT_CONFLICT':
        return const AppError(
          AppErrorKind.companyDenied,
          'Un autre compte utilise déjà cet email dans la société. Contactez l\'administrateur.',
          code: 'ACCOUNT_CONFLICT',
        );
      case 'FORBIDDEN_ROLE':
        return const AppError(
          AppErrorKind.forbidden,
          'Accès refusé : droits administrateur requis.',
          code: 'FORBIDDEN_ROLE',
        );
      case 'TOO_MANY_REQUESTS':
        return const AppError(AppErrorKind.other, 'Trop de tentatives, patientez une minute.');
    }

    if (status == 401) return sessionExpired;
    if (status == 503) return authUnavailable;
    if (status == 404) {
      return const AppError(AppErrorKind.notFound, 'Ressource introuvable.');
    }
    if (status == 403) {
      return const AppError(AppErrorKind.forbidden, 'Accès refusé.');
    }
    return AppError(AppErrorKind.other, 'Erreur inattendue (HTTP ${status ?? '?'}).');
  }
}

enum AppErrorKind {
  cancelled,
  authUnavailable,
  apiUnreachable,
  sessionExpired,
  companyDenied,
  forbidden,
  notFound,
  other,
}
