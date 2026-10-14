import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/app_error.dart';
import '../models/discovery_config.dart';
import '../state/session.dart';
import 'widgets.dart';

/// Étape 2 : connexion Keycloak (mot de passe + TOTP dans le navigateur système).
class LoginScreen extends ConsumerWidget {
  const LoginScreen({super.key, required this.config, this.error});

  final DiscoveryConfig config;
  final AppError? error;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.read(sessionProvider.notifier);

    return Scaffold(
      appBar: AppBar(title: const Text('Connexion')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Société : ${config.companySlug}', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            const Text('Vous serez redirigé vers la page de connexion sécurisée '
                '(mot de passe puis code de votre application d\'authentification).'),
            const SizedBox(height: 24),
            FilledButton.icon(
              key: const Key('login-button'),
              onPressed: session.login,
              icon: const Icon(Icons.login),
              label: const Text('Se connecter'),
            ),
            const SizedBox(height: 8),
            TextButton(onPressed: session.changeCompany, child: const Text('Changer de société')),
            if (error != null) ...[
              const SizedBox(height: 16),
              ErrorBanner(error!),
            ],
          ],
        ),
      ),
    );
  }
}
