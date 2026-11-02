// Daily cash closing (V29 §8): the counter user finalises the previous business day directly — no admin approval.
// Sales → expense review → Actual Cash → review → Finalize (locks the day) → optional daybook photo.
// Every figure shown after saving comes from the server; the app never posts money itself.
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class ClosingScreen extends StatefulWidget {
  const ClosingScreen({super.key, required this.session, this.date});
  final Session session;
  final String? date;
  @override
  State<ClosingScreen> createState() => _ClosingScreenState();
}

class _ClosingScreenState extends State<ClosingScreen> {
  Api get api => widget.session.api;
  Permissions get perms => widget.session.permissions!;

  Map<String, dynamic>? s; // server closing state
  Object? _loadError;
  int step = 1; // 1 sales, 2 expenses, 3 cash, 4 review
  bool busy = false;
  String? _finalizeKey; // reused on retry so a lost reply cannot finalise twice
  Map<String, dynamic>? done; // finalize result

  final cash = TextEditingController();
  final card = TextEditingController();
  final upi = TextEditingController();
  final actual = TextEditingController();
  final reason = TextEditingController();
  final denoms = {for (final d in [500, 200, 100, 50, 20, 10]) d: TextEditingController()};
  final coins = TextEditingController();
  String? expConfirmed;
  bool confirm = false;
  final _form = GlobalKey<FormState>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load([String? date]) async {
    setState(() {
      _loadError = null;
      s = null;
    });
    try {
      final r = await api.get('closing', {if ((date ?? widget.date) != null) 'date': (date ?? widget.date)!});
      _apply(r);
    } catch (e) {
      setState(() => _loadError = e);
    }
  }

  void _apply(Map<String, dynamic> r) {
    final sales = (r['sales'] as Map?) ?? const {};
    cash.text = rupeeInput(sales['cash']);
    card.text = rupeeInput(sales['card']);
    upi.text = rupeeInput(sales['upi']);
    actual.text = rupeeInput(r['actual_cash']);
    reason.text = (r['diff_reason'] ?? '').toString();
    expConfirmed = r['expenses_confirmed'] as String?;
    setState(() {
      s = r;
      final st = (r['step'] as num?)?.toInt() ?? 1;
      step = st >= 6 ? 4 : st >= 5 ? 3 : st >= 4 ? 2 : 1;
    });
  }

  int? _p(TextEditingController c) => c.text.trim().isEmpty ? null : parseRupees(c.text);
  int get totalSale => (_p(cash) ?? 0) + (_p(card) ?? 0) + (_p(upi) ?? 0);
  int get cashOut => ((s?['cash_out'] as num?) ?? 0).toInt();

  /// Owner's operational difference: Actual Cash − (Total Sale − cash expenses & payouts). Positive = Cash Acess.
  int? get opDiff {
    final a = _p(actual);
    return a == null ? null : a - (totalSale - cashOut);
  }

  static const _serverStep = {1: 2, 2: 4, 3: 5, 4: 6};

  Future<bool> _save(int next) async {
    if (!(_form.currentState?.validate() ?? true)) return false;
    final body = <String, dynamic>{'date': s!['date'], 'version': s!['version'], 'step': _serverStep[next]};
    if (step == 1) {
      for (final e in {'cash_sale': cash, 'card_sale': card, 'upi_sale': upi}.entries) {
        if (e.value.text.trim().isEmpty && next > 1) {
          showMessage(context, 'Enter all three sales (0 if none).', error: true);
          return false;
        }
        body[e.key] = e.value.text.trim();
      }
    }
    if (step == 2) {
      if (expConfirmed == null && next > 2) {
        showMessage(context, 'Tick that you reviewed the expenses, or “No expenses”.', error: true);
        return false;
      }
      body['expenses_confirmed'] = expConfirmed;
    }
    if (step == 3) {
      if (actual.text.trim().isEmpty && next > 3) {
        showMessage(context, 'Enter the Actual Cash you counted.', error: true);
        return false;
      }
      body['actual_cash'] = actual.text.trim();
      body['diff_reason'] = reason.text.trim();
    }
    setState(() => busy = true);
    try {
      final r = await api.post('closing/save', body);
      _apply(r);
      setState(() => step = next);
      return true;
    } on ApiError catch (e) {
      if (!mounted) return false;
      showMessage(context, e.message, error: true);
      if (e.isConflict) await _load(s!['date'] as String);
      return false;
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _finalize() async {
    if (!confirm) {
      showMessage(context, 'Tick “I confirm these figures are correct”.', error: true);
      return;
    }
    setState(() => busy = true);
    _finalizeKey ??= randomKey();
    try {
      final r = await api.post('closing/finalize', {'date': s!['date'], 'version': s!['version'], 'confirm': true}, idem: _finalizeKey);
      _finalizeKey = null;
      setState(() => done = r);
      widget.session.reloadPermissions().catchError((_) {});
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.isNetwork) {
        showMessage(context, '${e.message} Tap Finalize again — it will not be saved twice.', error: true);
      } else {
        _finalizeKey = null;
        showMessage(context, e.message, error: true);
        if (e.isConflict) await _load(s!['date'] as String);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loadError != null) return ErrorPanel(error: _loadError!, onRetry: _load);
    if (s == null) return const Center(child: CircularProgressIndicator());
    if (done != null) return _success();
    if (s!['locked'] == true || s!['can_edit'] == false) return _lockedView();
    return Form(
      key: _form,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        _header(),
        if (step == 1) ..._sales(),
        if (step == 2) ..._expenses(),
        if (step == 3) ..._cash(),
        if (step == 4) ..._review(),
        const SizedBox(height: 12),
        Row(children: [
          if (step > 1) Expanded(child: OutlinedButton(onPressed: busy ? null : () => _save(step - 1), child: const Text('‹ Previous'))),
          if (step > 1) const SizedBox(width: 12),
          Expanded(
            child: step < 4
                ? FilledButton(onPressed: busy ? null : () => _save(step + 1), child: Text(busy ? 'Saving…' : 'Save & Next ›'))
                : FilledButton(
                    onPressed: busy || !perms.can('closing.finalize') ? null : _finalize,
                    child: Text(busy ? 'Saving…' : 'Finalize & lock')),
          ),
        ]),
        if (step == 4 && !perms.can('closing.finalize'))
          const Banner2('You can enter figures but your account cannot finalize. Ask an authorised user.', icon: Icons.lock_outline),
      ]),
    );
  }

  Widget _header() => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text('Business day ${s!['date']}', style: Theme.of(context).textTheme.titleLarge)),
          StatusChip(s!['status'] as String?),
        ]),
        const SizedBox(height: 4),
        LinearProgressIndicator(value: step / 4, color: brandOrange, backgroundColor: const Color(0xFFFFE2C7)),
        const SizedBox(height: 4),
        Text('Step $step of 4 · ${['Sales', 'Expenses', 'Actual Cash', 'Review'][step - 1]}', style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 8),
      ]);

  List<Widget> _sales() => [
        SectionCard(title: "Yesterday's sale · कल की सेल", children: [
          MoneyField(controller: cash, label: 'Cash Sale', onChanged: (_) => setState(() {})),
          const SizedBox(height: 12),
          MoneyField(controller: card, label: 'Card Sale', onChanged: (_) => setState(() {})),
          const SizedBox(height: 12),
          MoneyField(controller: upi, label: 'UPI Sale', onChanged: (_) => setState(() {})),
          const Divider(height: 24),
          SumRow('Total Sale (automatic)', rupees(totalSale), bold: true),
        ]),
      ];

  List<Widget> _expenses() {
    final ex = (s!['expenses'] as List? ?? const []);
    final pays = (s!['payouts'] as List? ?? const []);
    return [
      SectionCard(title: 'Expenses for ${s!['date']}', children: [
        if (ex.isEmpty) const Text('No expenses are recorded for this day.'),
        for (final e in ex) SumRow('${(e as Map)['category'] ?? e['label']} · ${e['account'] ?? ''}', rupees(e['total'])),
        if (ex.isNotEmpty) SumRow('Total expenses', rupees(s!['expenses_total']), bold: true),
        if (pays.isNotEmpty) ...[
          const Divider(),
          for (final p in pays) SumRow('${(p as Map)['label']} ${p['party'] ?? ''}', rupees(p['total'])),
        ],
        const SizedBox(height: 8),
        RadioGroup<String>(
          groupValue: expConfirmed,
          onChanged: (v) => setState(() => expConfirmed = v),
          child: const Column(children: [
            RadioListTile<String>(value: 'reviewed', title: Text('I reviewed these expenses')),
            RadioListTile<String>(value: 'none', title: Text('No expenses today')),
          ]),
        ),
        if (perms.can('expenses.create'))
          const Text('To add a missed expense, use Expenses, then come back.', style: TextStyle(fontSize: 12)),
      ]),
    ];
  }

  void _fromDenoms() {
    var t = 0;
    denoms.forEach((d, c) => t += (int.tryParse(c.text) ?? 0) * d * 100);
    t += parseRupees(coins.text) ?? 0;
    actual.text = rupeeInput(t);
    setState(() {});
  }

  List<Widget> _cash() {
    final d = opDiff;
    final need = ((s!['reason_required_above'] as num?) ?? -1).toInt();
    return [
      SectionCard(title: 'Count the Actual Cash · गिना हुआ नकद', children: [
        const Text('Count the notes and coins physically in the counter now. Card and UPI are not cash.'),
        const SizedBox(height: 12),
        MoneyField(controller: actual, label: 'Actual Cash (counted)', big: true, onChanged: (_) => setState(() {})),
        ExpansionTile(
          tilePadding: EdgeInsets.zero,
          title: const Text('Count by notes (optional)'),
          children: [
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final e in denoms.entries)
                SizedBox(width: 140, child: NumberField(controller: e.value, label: '₹${e.key} ×', decimals: false, onChanged: (_) => _fromDenoms())),
              SizedBox(width: 140, child: NumberField(controller: coins, label: 'Coins ₹', onChanged: (_) => _fromDenoms())),
            ]),
            const SizedBox(height: 8),
          ],
        ),
        const Divider(),
        SumRow('Total Sale', rupees(totalSale)),
        SumRow('− Cash expenses & payouts', rupees(cashOut)),
        SumRow('Total Sale − cash expenses', rupees(totalSale - cashOut), bold: true),
        if (d != null)
          SumRow(d > 0 ? 'Cash Acess' : d < 0 ? 'Cash Short' : 'Difference', rupees(d.abs()),
              bold: true, color: d > 0 ? const Color(0xFF1B7F3B) : d < 0 ? const Color(0xFFB3261E) : null),
        const SizedBox(height: 12),
        TextFormField(
          controller: reason,
          maxLines: 2,
          decoration: InputDecoration(
              labelText: need >= 0 ? 'Reason for difference (required above ${rupees(need)})' : 'Reason for difference (optional)'),
          validator: (v) => d != null && need >= 0 && d.abs() > need && (v ?? '').trim().isEmpty ? 'Write a reason for the difference' : null,
        ),
      ]),
    ];
  }

  List<Widget> _review() {
    final sales = (s!['sales'] as Map?) ?? const {};
    final d = opDiff;
    final issues = <String>[
      for (final k in ['cash', 'card', 'upi'])
        if (sales[k] == null) '${k.toUpperCase()} Sale is not entered.',
      if (s!['expenses_confirmed'] == null) 'Review expenses or confirm “No expenses”.',
      if (s!['actual_cash'] == null) 'Actual Cash is not entered.',
    ];
    return [
      SectionCard(title: 'Check and finalize', children: [
        SumRow('Cash Sale', rupees(sales['cash'])),
        SumRow('Card Sale', rupees(sales['card'])),
        SumRow('UPI Sale', rupees(sales['upi'])),
        SumRow('Total Sale', rupees(sales['total']), bold: true),
        SumRow('Expenses (${(s!['expenses'] as List?)?.length ?? 0})', rupees(s!['expenses_total'])),
        SumRow('All cash paid out', rupees(s!['cash_out'])),
        SumRow('Actual Cash', rupees(s!['actual_cash']), bold: true),
        if (d != null) SumRow(d > 0 ? 'Cash Acess' : d < 0 ? 'Cash Short' : 'Difference', rupees(d.abs()), bold: true),
      ]),
      for (final i in issues) Banner2(i, color: const Color(0xFFFDE2E1), icon: Icons.block),
      if ((s!['legacy_sales'] as List? ?? const []).isNotEmpty)
        const Banner2('Books already has sale entries for this day — the server will hold your sales for admin review.'),
      const Banner2('After finalizing, this day is LOCKED. Corrections need an admin with a reason. No admin approval is needed to finalize.',
          icon: Icons.lock_outline),
      CheckboxListTile(
        value: confirm,
        onChanged: issues.isEmpty ? (v) => setState(() => confirm = v ?? false) : null,
        controlAffinity: ListTileControlAffinity.leading,
        title: const Text('I confirm these figures are correct.'),
      ),
    ];
  }

  Widget _success() {
    final sum = (done!['summary'] as Map?) ?? const {};
    return ListView(padding: const EdgeInsets.all(16), children: [
      const Icon(Icons.check_circle, color: Color(0xFF1B7F3B), size: 64),
      Text(done!['final'] == true ? 'Closing final' : 'Closing submitted',
          textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall),
      Text((done!['message'] ?? '').toString(), textAlign: TextAlign.center),
      const SizedBox(height: 12),
      SectionCard(children: [
        SumRow('Cash Sale', rupees(sum['cash'])),
        SumRow('Card Sale', rupees(sum['card'])),
        SumRow('UPI Sale', rupees(sum['upi'])),
        SumRow('Total Sale', rupees(sum['total_sale']), bold: true),
        SumRow('Expenses', rupees(sum['expenses'])),
        SumRow('Actual Cash', rupees(sum['actual_cash']), bold: true),
      ]),
      DaybookPhotos(api: api, date: (done!['date'] ?? s!['date']).toString(), canAdd: true),
      const SizedBox(height: 12),
      OutlinedButton(onPressed: () => setState(() {
            done = null;
            _load();
          }), child: const Text('Done')),
    ]);
  }

  Widget _lockedView() {
    final sales = (s!['sales'] as Map?) ?? const {};
    final calc = (s!['calc'] as Map?) ?? const {};
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text('Business day ${s!['date']}', style: Theme.of(context).textTheme.titleLarge)),
        StatusChip(s!['status'] as String?),
      ]),
      if (s!['block_reason'] != null) Banner2(s!['block_reason'].toString(), icon: Icons.lock_outline),
      SectionCard(title: 'Locked closing', children: [
        SumRow('Cash Sale', rupees(sales['cash'])),
        SumRow('Card Sale', rupees(sales['card'])),
        SumRow('UPI Sale', rupees(sales['upi'])),
        SumRow('Total Sale', rupees(sales['total']), bold: true),
        SumRow('Expenses', rupees(s!['expenses_total'])),
        SumRow('Actual Cash', rupees(s!['actual_cash']), bold: true),
        if (calc['operational_label'] != null) SumRow(calc['operational_label'].toString(), rupees(calc['operational_abs']), bold: true),
      ]),
      DaybookPhotos(api: api, date: s!['date'].toString(), canAdd: perms.can('closing.entry') || perms.can('closing.finalize')),
      if (s!['can_correction'] == true)
        OutlinedButton.icon(
          icon: const Icon(Icons.edit_note),
          label: const Text('Request correction'),
          onPressed: () async {
            final why = await askText(context, 'Request correction', 'This day is locked. An admin will review your reason.');
            if (why == null) return;
            try {
              await api.post('closing/correction', {'date': s!['date'], 'reason': why});
              if (mounted) showMessage(context, 'Correction request sent.');
            } catch (e) {
              if (mounted) showMessage(context, errorText(e), error: true);
            }
          },
        ),
      const SizedBox(height: 8),
      OutlinedButton(onPressed: () => _load(), child: const Text('Back to pending closing')),
    ]);
  }
}

/// Optional photo(s) of the hand-written daybook. Never required; a failed upload never affects the closing.
class DaybookPhotos extends StatefulWidget {
  const DaybookPhotos({super.key, required this.api, required this.date, required this.canAdd, this.picker});
  final Api api;
  final String date;
  final bool canAdd;
  final ImagePicker? picker;
  @override
  State<DaybookPhotos> createState() => _DaybookPhotosState();
}

class _DaybookPhotosState extends State<DaybookPhotos> {
  List photos = const [];
  int max = 6;
  bool busy = false;
  String? note;

  @override
  void initState() {
    super.initState();
    _list();
  }

  Future<void> _list() async {
    try {
      final r = await widget.api.get('closing/photos', {'date': widget.date});
      setState(() {
        photos = r['photos'] as List? ?? const [];
        max = (r['max'] as num?)?.toInt() ?? 6;
      });
    } catch (_) {}
  }

  Future<void> _add(ImageSource src) async {
    final x = await (widget.picker ?? ImagePicker()).pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 82);
    if (x == null) return;
    final bytes = await x.readAsBytes();
    if (bytes.length > 1500000) {
      setState(() => note = 'Photo is larger than 1.5 MB even after compression. Try again closer to the page.');
      return;
    }
    setState(() {
      busy = true;
      note = null;
    });
    try {
      await widget.api.post('closing/photos', {
        'date': widget.date,
        'file': {'name': x.name.isEmpty ? 'daybook.jpg' : x.name, 'data': base64Encode(bytes)},
      });
      setState(() => note = 'Photo saved on the server.');
      await _list();
    } catch (e) {
      setState(() => note = '${errorText(e)} — your closing is safe; try the photo again.');
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => SectionCard(title: 'Daybook photo (optional)', children: [
        Text(photos.isEmpty ? 'No photo added.' : '${photos.length} photo(s) attached to ${widget.date}.'),
        if (note != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(note!)),
        if (widget.canAdd && photos.length < max) ...[
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
                child: OutlinedButton.icon(
                    onPressed: busy ? null : () => _add(ImageSource.camera), icon: const Icon(Icons.photo_camera), label: const Text('Camera'))),
            const SizedBox(width: 8),
            Expanded(
                child: OutlinedButton.icon(
                    onPressed: busy ? null : () => _add(ImageSource.gallery), icon: const Icon(Icons.photo_library), label: const Text('Gallery'))),
          ]),
        ],
      ]);
}
