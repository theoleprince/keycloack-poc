import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/app_error.dart';
import '../state/session.dart';
import 'widgets.dart';

/// Étape 1 : saisie de la clé société -> découverte (backend + Keycloak).
class CompanyKeyScreen extends ConsumerStatefulWidget {
  const CompanyKeyScreen({super.key, this.error});

  final AppError? error;

  @override
  ConsumerState<CompanyKeyScreen> createState() => _CompanyKeyScreenState();
}

class _CompanyKeyScreenState extends ConsumerState<CompanyKeyScreen> {
  final _key = TextEditingController();

  @override
  void dispose() {
    _key.dispose();
    super.dispose();
  }

  void _submit() => ref.read(sessionProvider.notifier).submitCompanyKey(_key.text);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Clé société')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text('Saisissez la clé fournie par votre société.'),
            const SizedBox(height: 16),
            TextField(
              key: const Key('company-key-field'),
              controller: _key,
              textCapitalization: TextCapitalization.characters,
              autocorrect: false,
              decoration: const InputDecoration(labelText: 'Clé société', border: OutlineInputBorder()),
              onSubmitted: (_) => _submit(),
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: _submit, child: const Text('Continuer')),
            if (widget.error != null) ...[
              const SizedBox(height: 16),
              ErrorBanner(widget.error!),
            ],
          ],
        ),
      ),
    );
  }
}
