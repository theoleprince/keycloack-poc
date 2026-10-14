import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'screens/company_key_screen.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'state/session.dart';

void main() {
  runApp(const ProviderScope(child: PocApp()));
}

class PocApp extends StatelessWidget {
  const PocApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'POC Keycloak',
      theme: ThemeData(colorSchemeSeed: Colors.indigo, useMaterial3: true),
      home: const SessionGate(),
    );
  }
}

/// Affiche l'écran correspondant à l'état de session.
class SessionGate extends ConsumerWidget {
  const SessionGate({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);

    return switch (session) {
      AsyncData(value: NeedsCompanyKey(:final error)) => CompanyKeyScreen(error: error),
      AsyncData(value: NeedsLogin(:final config, :final error)) => LoginScreen(config: config, error: error),
      AsyncData(value: Authenticated()) => const HomeScreen(),
      AsyncError() => const CompanyKeyScreen(),
      _ => const Scaffold(body: Center(child: CircularProgressIndicator())),
    };
  }
}
