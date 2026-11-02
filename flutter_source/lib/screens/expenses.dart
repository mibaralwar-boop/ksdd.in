import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class ExpensesScreen extends StatelessWidget {
  const ExpensesScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    return Loader<Map<String, dynamic>>(
      load: () => session.api.get('expenses'),
      builder: (context, d, reload) {
        final rows = d['rows'] as List? ?? const [];
        return ListView(padding: const EdgeInsets.all(16), children: [
          if ((p.can('expenses.create') || p.can('expenses.today')) && !p.readOnly)
            FilledButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('Add expense'),
              onPressed: () async {
                final saved = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => ExpenseForm(session: session)));
                if (saved == true) await reload();
              },
            ),
          SectionCard(children: [SumRow('Total (${d['count'] ?? rows.length})', rupees(d['total']), bold: true)]),
          if (rows.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('No expenses in this period.')),
          for (final r in rows)
            Card(
              child: ListTile(
                title: Text('${(r as Map)['category'] ?? ''}'),
                subtitle: Text('${r['date'] ?? ''} · ${r['account'] ?? ''}${r['description'] != null ? ' · ${r['description']}' : ''}'),
                trailing: Text(rupees(r['total'] ?? r['amount'])),
              ),
            ),
        ]);
      },
    );
  }
}

class ExpenseForm extends StatefulWidget {
  const ExpenseForm({super.key, required this.session});
  final Session session;
  @override
  State<ExpenseForm> createState() => _ExpenseFormState();
}

class _ExpenseFormState extends State<ExpenseForm> {
  Api get api => widget.session.api;
  final _form = GlobalKey<FormState>();
  late final date = TextEditingController(text: widget.session.permissions!.businessDate);
  final amount = TextEditingController();
  final desc = TextEditingController();
  String? category;
  String mode = 'Cash';
  Map<String, String>? photo;
  bool busy = false;
  final String _key = randomKey();

  Future<void> _save({bool dupOk = false}) async {
    if (!_form.currentState!.validate() || category == null) {
      if (category == null) showMessage(context, 'Choose a category.', error: true);
      return;
    }
    setState(() => busy = true);
    try {
      await api.post('expenses', {
        'date': date.text.trim(),
        'category': category,
        'amount': amount.text.trim(),
        'mode': mode,
        'description': desc.text.trim(),
        if (photo != null) 'files': [photo],
        'confirm_duplicate': dupOk,
      }, idem: dupOk ? '${_key}d' : _key);
      if (mounted) Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.isConflict && e.data['duplicate'] == true) {
        if (await confirmDialog(context, 'Possible duplicate', e.message, ok: 'Yes, it is a different expense')) {
          setState(() => busy = false);
          return _save(dupOk: true);
        }
      } else {
        showMessage(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _photo(ImageSource src) async {
    final x = await ImagePicker().pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 82);
    if (x == null) return;
    final b = await x.readAsBytes();
    if (b.length > 1500000) {
      if (mounted) showMessage(context, 'Photo is larger than 1.5 MB.', error: true);
      return;
    }
    setState(() => photo = {'name': x.name.isEmpty ? 'receipt.jpg' : x.name, 'data': base64Encode(b)});
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Add expense')),
        body: Loader<Map<String, dynamic>>(
          load: () => api.get('expenses/categories'),
          builder: (context, d, _) {
            final cats = [for (final c in (d['categories'] as List? ?? const [])) (c as Map)['name'].toString()];
            return Form(
              key: _form,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                TextFormField(controller: date, decoration: const InputDecoration(labelText: 'Date (YYYY-MM-DD)')),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: category,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Category'),
                  items: [for (final c in cats) DropdownMenuItem(value: c, child: Text(c))],
                  onChanged: (v) => setState(() => category = v),
                ),
                const SizedBox(height: 12),
                MoneyField(controller: amount, label: 'Amount', big: true),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: mode,
                  decoration: const InputDecoration(labelText: 'Paid by'),
                  items: [for (final m in ['Cash', 'Bank', 'UPI', 'Card']) DropdownMenuItem(value: m, child: Text(m))],
                  onChanged: (v) => setState(() => mode = v ?? 'Cash'),
                ),
                const SizedBox(height: 12),
                TextFormField(controller: desc, decoration: const InputDecoration(labelText: 'Note (optional)')),
                const SizedBox(height: 12),
                Text(photo == null ? 'Receipt photo (optional)' : 'Receipt photo added'),
                Row(children: [
                  Expanded(child: OutlinedButton.icon(onPressed: () => _photo(ImageSource.camera), icon: const Icon(Icons.photo_camera), label: const Text('Camera'))),
                  const SizedBox(width: 8),
                  Expanded(child: OutlinedButton.icon(onPressed: () => _photo(ImageSource.gallery), icon: const Icon(Icons.photo_library), label: const Text('Gallery'))),
                ]),
                const SizedBox(height: 16),
                FilledButton(onPressed: busy ? null : () => _save(), child: Text(busy ? 'Saving…' : 'Save expense')),
              ]),
            );
          },
        ),
      );
}
