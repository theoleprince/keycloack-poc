class Note {
  const Note({required this.id, required this.title, this.body});

  final int id;
  final String title;
  final String? body;

  factory Note.fromJson(Map<String, dynamic> json) => Note(
        id: json['id'] as int,
        title: (json['title'] ?? '') as String,
        body: json['body'] as String?,
      );
}
