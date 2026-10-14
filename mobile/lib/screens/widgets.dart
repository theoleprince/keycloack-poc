import 'package:flutter/material.dart';

import '../models/app_error.dart';

/// Message d'erreur lisible, mis en évidence s'il bloque l'accès.
class ErrorBanner extends StatelessWidget {
  const ErrorBanner(this.error, {super.key});

  final AppError error;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final blocking = error.blocksAccess;

    return Container(
      key: const Key('error-banner'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: blocking ? scheme.errorContainer : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(blocking ? Icons.lock_outline : Icons.info_outline,
              color: blocking ? scheme.onErrorContainer : scheme.onSurfaceVariant),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              error.message,
              style: TextStyle(color: blocking ? scheme.onErrorContainer : scheme.onSurfaceVariant),
            ),
          ),
        ],
      ),
    );
  }
}
