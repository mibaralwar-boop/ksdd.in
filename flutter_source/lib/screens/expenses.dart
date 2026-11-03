import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

/// Expenses for a period (`GET expenses?from&to`): Add Expense at the top, totals by category, void with reason
/// (`POST expenses/void`) where permitted and the day is not locked (server decides `editable`).
class ExpensesScreen extends StatefulWidget {
  const ExpensesScreen({super.key, required this.session});
  final Session session;
  @override
  State<ExpensesScreen> createState() => _ExpensesScreenState();
}

class _ExpensesScreenState extends State<ExpensesScreen> {
  late String from;
  late String to;

  @override
  void initState() {
    super.initState();
    final p = widget.session.permissions!;
    // A user who may only enter today's expenses sees today; others see this month.
    if (p.can('expenses.view') && !p.can('expenses.today') || p.can('reports.view')) {
      final bd = parseYmd(p.businessDate);
      from = ymd(DateTime(bd.year, bd.month, 1));
    } else {
      from = p.businessDate;
    }
    to = p.businessDate;
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    final p = session.permissions!;
    final canAdd = (p.can('expenses.create') || p.can('expenses.today')) && !p.readOnly;
    Future<void> add(Future<void> Function() reload) async {
      final saved = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => ExpenseForm(session: session)));
      if (saved == true) await reload();
    }

    return Loader<Map<String, dynamic>>(
      key: ValueKey('$from|$to'),
      load: () => session.api.get('expenses', {'from': from, 'to': to}),
      builder: (context, d, reload) {
        final rows = [for (final x in (d['rows'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
        final byCat = (d['by_category'] is Map ? (d['by_category'] as Map) : const {}).entries.toList()
          ..sort((a, b) => ((b.value as num?) ?? 0).compareTo((a.value as num?) ?? 0));
        return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
          if (canAdd)
            FilledButton.icon(icon: const Icon(Icons.add_rounded), label: const Text('Add expense'), onPressed: () => add(reload)),
          const SizedBox(height: 10),
          PeriodBar(
            from: from,
            to: to,
            businessDate: p.businessDate,
            onChanged: (f, t) => setState(() {
              from = f;
              to = t;
            }),
          ),
          SectionCard(children: [SumRow('Total (${d['count'] ?? rows.length})', rupees(d['total']), bold: true)]),
          if (byCat.length > 1)
            SectionCard(title: 'By category', icon: Icons.pie_chart_outline_rounded, children: [
              for (final e in byCat) SumRow(e.key.toString(), rupees(e.value)),
            ]),
          if (rows.isEmpty)
            EmptyState(
              icon: Icons.receipt_long_rounded,
              title: 'No expenses in this period.',
              subtitle: from == to ? 'Nothing was entered for $from.' : 'Nothing was entered from $from to $to.',
              action: canAdd
                  ? OutlinedButton.icon(icon: const Icon(Icons.add_rounded), label: const Text('Add expense'), onPressed: () => add(reload))
                  : null,
            ),
          for (final r in rows)
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: r['voided'] == 1 ? brandLine : brandPeach,
                  child: Icon(r['files'] is List && (r['files'] as List).isNotEmpty ? Icons.receipt_rounded : Icons.payments_outlined,
                      color: brandBurnt),
                ),
                title: Text('${r['category'] ?? ''}',
                    style: TextStyle(fontWeight: FontWeight.w700, decoration: r['voided'] == 1 ? TextDecoration.lineThrough : null)),
                subtitle: Text([
                  r['date'],
                  r['account'],
                  r['description'],
                  if (r['voided'] == 1) 'voided: ${r['void_reason'] ?? ''}',
                ].where((x) => x != null && x != '').join(' · ')),
                trailing: Text(rupees(r['total'] ?? r['amount']), style: const TextStyle(fontWeight: FontWeight.w800)),
                onLongPress: p.can('expenses.void') && r['voided'] != 1 && r['editable'] != false && !p.readOnly
                    ? () async {
                        final why = await askText(context, 'Void this expense?',
                            '${r['category']} ${rupees(r['total'])} on ${r['date']}. It stays in history as voided.');
                        if (why == null) return;
                        try {
                          await session.api.post('expenses/void', {'id': r['id'], 'reason': why});
                          await reload();
                        } on ApiError catch (e) {
                          if (context.mounted) showMessage(context, e.message, error: true);
                        }
                      }
                    : null,
              ),
            ),
          if (p.can('expenses.void') && rows.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('Press and hold an expense to void it.', style: Theme.of(context).textTheme.bodySmall),
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
      await api.post(
          'expenses',
          {
            'date': date.text.trim(),
            'category': category,
            'amount': amount.text.trim(),
            'mode': mode,
            'description': desc.text.trim(),
            if (photo != null) 'files': [photo],
            'confirm_duplicate': dupOk,
          },
          idem: dupOk ? '${_key}d' : _key);
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
                  items: [
                    for (final m in ['Cash', 'Bank', 'UPI', 'Card']) DropdownMenuItem(value: m, child: Text(m))
                  ],
                  onChanged: (v) => setState(() => mode = v ?? 'Cash'),
                ),
                const SizedBox(height: 12),
                TextFormField(controller: desc, decoration: const InputDecoration(labelText: 'Note (optional)')),
                const SizedBox(height: 12),
                Text(photo == null ? 'Receipt photo (optional)' : 'Receipt photo added'),
                Row(children: [
                  Expanded(
                      child: OutlinedButton.icon(
                          onPressed: () => _photo(ImageSource.camera), icon: const Icon(Icons.photo_camera), label: const Text('Camera'))),
                  const SizedBox(width: 8),
                  Expanded(
                      child: OutlinedButton.icon(
                          onPressed: () => _photo(ImageSource.gallery),
                          icon: const Icon(Icons.photo_library),
                          label: const Text('Gallery'))),
                ]),
                const SizedBox(height: 16),
                FilledButton(onPressed: busy ? null : () => _save(), child: Text(busy ? 'Saving…' : 'Save expense')),
              ]),
            );
          },
        ),
      );
}
