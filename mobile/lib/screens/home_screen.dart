import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/app_error.dart';
import '../models/me.dart';
import '../models/note.dart';
import '../state/session.dart';
import 'widgets.dart';

/// Étape 3 : profil (/api/me), notes de la société, création, route admin.
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  Me? _me;
  List<Note> _notes = const [];
  AppError? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = ref.read(apiClientProvider);
    if (api == null) return;
    setState(() => _loading = true);
    try {
      final me = await api.me();
      final notes = await api.notes();
      if (!mounted) return;
      setState(() {
        _me = me;
        _notes = notes;
        _error = null;
        _loading = false;
      });
    } on AppError catch (e) {
      if (!mounted) return;
      // Erreur bloquante : on efface toute donnée affichée (aucun accès).
      if (e.kind == AppErrorKind.sessionExpired || e.kind == AppErrorKind.companyDenied) {
        await ref.read(sessionProvider.notifier).sessionLost(e);
        return;
      }
      setState(() {
        _me = null;
        _notes = const [];
        _error = e;
        _loading = false;
      });
    }
  }

  Future<void> _createNote() async {
    final title = await showDialog<String>(context: context, builder: (_) => const _NoteDialog());
    if (title == null || title.trim().isEmpty) return;
    try {
      await ref.read(apiClientProvider)!.createNote(title.trim(), null);
      await _load();
    } on AppError catch (e) {
      _snack(e.message);
    }
  }

  Future<void> _admin() async {
    try {
      final count = await ref.read(apiClientProvider)!.adminUsersCount();
      _snack('Route admin OK : $count utilisateur(s) dans votre société.');
    } on AppError catch (e) {
      _snack(e.message);
    }
  }

  void _snack(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final session = ref.read(sessionProvider.notifier);
    final me = _me;

    return Scaffold(
      appBar: AppBar(
        title: Text(me?.companyName ?? 'Accueil'),
        actions: [
          IconButton(tooltip: 'Recharger', onPressed: _load, icon: const Icon(Icons.refresh)),
          IconButton(tooltip: 'Se déconnecter', onPressed: session.logout, icon: const Icon(Icons.logout)),
        ],
      ),
      floatingActionButton: me == null
          ? null
          : FloatingActionButton.extended(onPressed: _createNote, icon: const Icon(Icons.add), label: const Text('Note')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(children: [
                    ErrorBanner(_error!),
                    const SizedBox(height: 16),
                    FilledButton(onPressed: _load, child: const Text('Réessayer')),
                    TextButton(onPressed: session.logout, child: const Text('Se déconnecter')),
                  ]),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (me != null) _Profile(me),
                      const SizedBox(height: 8),
                      OutlinedButton.icon(
                        onPressed: _admin,
                        icon: const Icon(Icons.admin_panel_settings_outlined),
                        label: const Text('Route admin'),
                      ),
                      const Divider(height: 32),
                      Text('Notes de la société (${_notes.length})', style: Theme.of(context).textTheme.titleMedium),
                      for (final n in _notes)
                        ListTile(title: Text(n.title), subtitle: n.body == null ? null : Text(n.body!)),
                    ],
                  ),
                ),
    );
  }
}

class _Profile extends StatelessWidget {
  const _Profile(this.me);

  final Me me;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(me.name, style: Theme.of(context).textTheme.titleLarge),
            Text('Utilisateur local #${me.localUserId}${me.username != null ? ' · ${me.username}' : ''}'),
            Text('Société : ${me.companyName} (${me.companySlug})'),
            Text('Rôles : ${me.roles.join(', ')}'),
            Text('Authentification : ${me.authMethod}'),
          ],
        ),
      ),
    );
  }
}

class _NoteDialog extends StatefulWidget {
  const _NoteDialog();

  @override
  State<_NoteDialog> createState() => _NoteDialogState();
}

class _NoteDialogState extends State<_NoteDialog> {
  final _title = TextEditingController();

  @override
  void dispose() {
    _title.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Nouvelle note'),
      content: TextField(controller: _title, autofocus: true, decoration: const InputDecoration(labelText: 'Titre')),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Annuler')),
        FilledButton(onPressed: () => Navigator.pop(context, _title.text), child: const Text('Créer')),
      ],
    );
  }
}
