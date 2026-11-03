import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Private document inbox of this branch (`GET documents`, `POST documents`). Files are stored on the server's private
/// storage, never on a public URL; the server warns when the same file is already attached to a bill or expense.
class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({super.key, required this.session, this.picker});
  final Session session;
  final ImagePicker? picker;
  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  String status = 'open';
  bool uploading = false;

  @override
  Widget build(BuildContext context) {
    final p = widget.session.permissions!;
    return Loader<Map<String, dynamic>>(
      key: ValueKey(status),
      load: () => widget.session.api.get('documents', {'status': status}),
      builder: (context, d, reload) {
        final rows = [for (final x in (d['rows'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        final counts = (d['counts'] is Map ? d['counts'] as Map : const {});
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          if (!p.readOnly)
            FilledButton.icon(
              icon: uploading
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.upload_file_rounded),
              label: const Text('Upload bill / receipt photo'),
              onPressed: uploading ? null : () => _upload(reload),
            ),
          const SizedBox(height: 10),
          Wrap(spacing: 8, children: [
            for (final o in const [('open', 'To sort'), ('approved', 'Approved'), ('rejected', 'Rejected'), ('all', 'All')])
              ChoiceChip(
                label: Text(
                    '${o.$2}${o.$1 == 'open' ? ' (${((counts['unclassified'] ?? 0) as num).toInt() + ((counts['linked'] ?? 0) as num).toInt()})' : ''}'),
                selected: status == o.$1,
                onSelected: (_) => setState(() => status = o.$1),
              ),
          ]),
          if (rows.isEmpty)
            const EmptyState(
              icon: Icons.folder_open_rounded,
              title: 'No documents here',
              subtitle: 'Upload photos of supplier bills or receipts; the office sorts and links them on the website.',
            ),
          for (final r in rows)
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: brandPeach,
                  child: Icon((r['mime'] ?? '').toString().contains('pdf') ? Icons.picture_as_pdf_outlined : Icons.image_outlined,
                      color: brandBurnt),
                ),
                title: Text((r['original_name'] ?? 'Document').toString(), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text([
                  _kind(r['kind_guess']),
                  r['created_at']?.toString().substring(0, 10),
                  r['by_name'],
                  if (r['duplicate_note'] != null) '⚠ ${r['duplicate_note']}',
                  if (r['note'] != null) r['note'],
                ].where((x) => x != null && x != '').join(' · ')),
                trailing: _pill(r['status'].toString()),
              ),
            ),
        ]);
      },
    );
  }

  static String _kind(Object? k) => switch (k) { 'purchase' => 'Purchase bill', 'expense' => 'Expense receipt', _ => 'Other' };

  static Widget _pill(String s) => switch (s) {
        'approved' => const StatusPill('Approved', tone: PillTone.good),
        'rejected' || 'removed' => StatusPill(s == 'removed' ? 'Removed' : 'Rejected', tone: PillTone.bad),
        'linked' => const StatusPill('Linked', tone: PillTone.info),
        _ => const StatusPill('To sort'),
      };

  Future<void> _upload(Future<void> Function() reload) async {
    final src = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Camera'),
              onTap: () => Navigator.pop(c, ImageSource.camera)),
          ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Gallery'),
              onTap: () => Navigator.pop(c, ImageSource.gallery)),
        ]),
      ),
    );
    if (src == null || !mounted) return;
    final x = await (widget.picker ?? ImagePicker()).pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 82);
    if (x == null || !mounted) return;
    final kind = await showModalBottomSheet<String>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (final k in const [('purchase', 'Purchase bill'), ('expense', 'Expense receipt'), ('other', 'Other')])
            ListTile(title: Text(k.$2), onTap: () => Navigator.pop(c, k.$1)),
        ]),
      ),
    );
    if (kind == null || !mounted) return;
    setState(() => uploading = true);
    try {
      final bytes = await x.readAsBytes();
      final r = await widget.session.api.post('documents', {
        'kind': kind,
        'files': [
          {'name': x.name.isEmpty ? 'document.jpg' : x.name, 'data': base64Encode(bytes)},
        ],
      });
      if (!mounted) return;
      final dup = ((r['rows'] ?? r['files']) as List?)?.whereType<Map>().map((m) => m['duplicate_note']).whereType<String>().firstOrNull;
      showMessage(context, dup ?? 'Uploaded. It is private to this restaurant.');
      await reload();
    } on ApiError catch (e) {
      if (mounted) showMessage(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => uploading = false);
    }
  }
}
