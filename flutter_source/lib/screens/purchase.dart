// Supplier-first purchase bill (V28 §8 / V29 §11): supplier → ledger balance + last bill + last-delivered items with last
// rate → user mostly fills quantity → server posts once. A changed rate needs explicit confirmation (409 needs=rates_ok).
// No GST fields on the entry form. Credit purchase ≠ cash expense: payment is optional and posted by the server.
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class PurchaseScreen extends StatelessWidget {
  const PurchaseScreen({super.key, required this.session});
  final Session session;

  @override
  Widget build(BuildContext context) {
    final p = session.permissions!;
    return Loader<Map<String, dynamic>>(
      load: () => session.api.get('purchase-bills'),
      builder: (context, d, reload) {
        final bills = d['bills'] as List? ?? const [];
        final counts = (d['counts'] as Map?) ?? const {};
        return ListView(padding: const EdgeInsets.all(16), children: [
          if (p.can('purchase.create') && !p.readOnly)
            FilledButton.icon(
              icon: const Icon(Icons.add),
              label: const Text('New purchase bill'),
              onPressed: () async {
                await Navigator.push(context, MaterialPageRoute(builder: (_) => BillEditor(session: session)));
                await reload();
              },
            ),
          const SizedBox(height: 8),
          SectionCard(children: [
            SumRow('This month', '${(d['month'] as Map?)?['count'] ?? 0} bills · ${rupees((d['month'] as Map?)?['total'])}'),
            SumRow('Drafts / for review', '${counts['drafts'] ?? 0} / ${counts['review'] ?? 0}'),
          ]),
          if (bills.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('No posted bills yet.')),
          for (final b in bills)
            Card(
              child: ListTile(
                title: Text('${(b as Map)['supplier']} · ${b['invoice_no'] ?? b['bill_no']}'),
                subtitle: Text('${b['invoice_date']} · ${b['n_lines']} item(s) · ${b['receipt_status']}'),
                trailing: TrailingStack(children: [
                  Text(rupees(b['grand_total']), style: const TextStyle(fontWeight: FontWeight.w600)),
                  StatusChip((b['payment_status'] ?? b['status'])?.toString()),
                ]),
              ),
            ),
        ]);
      },
    );
  }
}

class BillLine {
  BillLine({required this.itemId, required this.name, required this.unit, this.lastRate, this.lastQty, this.otherSuppliers = const []})
      : rate = TextEditingController(text: rupeeInput(lastRate)),
        qty = TextEditingController();
  final int itemId;
  final String name;
  String unit;
  final int? lastRate;
  final num? lastQty;
  final List otherSuppliers;
  final TextEditingController qty;
  final TextEditingController rate;
  int? id;

  int get total {
    final q = double.tryParse(qty.text.trim()) ?? 0;
    final r = parseRupees(rate.text) ?? 0;
    return (q * r).round();
  }
}

class BillEditor extends StatefulWidget {
  const BillEditor({super.key, required this.session, this.picker});
  final Session session;
  final ImagePicker? picker;
  @override
  State<BillEditor> createState() => _BillEditorState();
}

class _BillEditorState extends State<BillEditor> {
  Api get api => widget.session.api;
  Permissions get perms => widget.session.permissions!;
  Map<String, dynamic>? supplier;
  Map<String, dynamic>? suggest;
  final lines = <BillLine>[];
  final invoiceNo = TextEditingController();
  late final TextEditingController invoiceDate = TextEditingController(text: widget.session.permissions!.businessDate);
  final photos = <Map<String, String>>[];
  int? billId;
  int? version;
  bool busy = false;
  bool payNow = false;
  final payAmount = TextEditingController();
  String payMode = 'Cash';
  String? _submitKey;

  int get grand => lines.fold(0, (a, l) => a + l.total);

  Future<void> _pickSupplier() async {
    final picked = await Navigator.push<Map<String, dynamic>>(context,
        MaterialPageRoute(builder: (_) => PickerScreen(api: api, path: 'suppliers', listKey: 'suppliers', title: 'Choose supplier')));
    if (picked == null) return;
    setState(() {
      supplier = picked;
      suggest = null;
      lines.clear();
    });
    try {
      final s = await api.get('suppliers/suggest', {'supplier_id': '${picked['id']}'});
      setState(() {
        suggest = s;
        for (final it in (s['suggested_items'] as List? ?? const [])) {
          final m = it as Map;
          lines.add(BillLine(
              itemId: (m['item_id'] as num).toInt(),
              name: m['item_name'].toString(),
              unit: (m['unit'] ?? '').toString(),
              lastRate: (m['last_rate'] as num?)?.toInt(),
              lastQty: m['last_qty'] as num?,
              otherSuppliers: m['other_suppliers'] as List? ?? const []));
        }
      });
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    }
  }

  Future<void> _addItem() async {
    final it = await Navigator.push<Map<String, dynamic>>(
        context, MaterialPageRoute(builder: (_) => PickerScreen(api: api, path: 'items', listKey: 'items', title: 'Add item')));
    if (it == null) return;
    setState(
        () => lines.add(BillLine(itemId: (it['id'] as num).toInt(), name: it['name'].toString(), unit: (it['unit'] ?? '').toString())));
  }

  Future<void> _photo(ImageSource src) async {
    final x = await (widget.picker ?? ImagePicker()).pickImage(source: src, maxWidth: 1600, maxHeight: 1600, imageQuality: 82);
    if (x == null) return;
    final b = await x.readAsBytes();
    if (b.length > 1500000) {
      if (mounted) showMessage(context, 'Photo is larger than 1.5 MB. Try again.', error: true);
      return;
    }
    setState(() => photos.add({'name': x.name.isEmpty ? 'bill.jpg' : x.name, 'data': base64Encode(b), 'kind': 'bill'}));
  }

  /// Only lines with a quantity are sent; suggestions the user left empty are dropped (never auto-ordered).
  List<BillLine> get filled => lines.where((l) => (double.tryParse(l.qty.text.trim()) ?? 0) > 0).toList();

  Future<bool> _saveDraft() async {
    if (supplier == null) {
      showMessage(context, 'Choose the supplier first.', error: true);
      return false;
    }
    final f = filled;
    for (final l in f) {
      if (parseRupees(l.rate.text) == null) {
        showMessage(context, 'Enter the rate for ${l.name}.', error: true);
        return false;
      }
    }
    final body = {
      if (billId != null) 'id': billId,
      if (version != null) 'version': version,
      'supplier_id': supplier!['id'],
      'invoice_no': invoiceNo.text.trim(),
      'invoice_date': invoiceDate.text.trim(),
      'location': 'Main Store',
      'lines': [
        for (final l in f)
          {if (l.id != null) 'id': l.id, 'item_id': l.itemId, 'unit': l.unit, 'qty': l.qty.text.trim(), 'rate': l.rate.text.trim()},
      ],
      if (photos.isNotEmpty) 'files_add': photos,
    };
    final r = await api.post('purchase-bills', body);
    final bill = (r['bill'] as Map).cast<String, dynamic>();
    billId = (bill['id'] as num).toInt();
    version = (bill['version'] as num).toInt();
    final saved = (r['lines'] as List? ?? const []);
    for (var i = 0; i < saved.length && i < f.length; i++) {
      f[i].id = ((saved[i] as Map)['id'] as num?)?.toInt();
    }
    photos.clear();
    return true;
  }

  Future<void> _draft() async {
    setState(() => busy = true);
    try {
      if (await _saveDraft() && mounted) showMessage(context, 'Draft saved on the server.');
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _submit([Map<String, dynamic> extra = const {}]) async {
    if (filled.isEmpty) {
      showMessage(context, 'Enter the quantity for at least one item.', error: true);
      return;
    }
    setState(() => busy = true);
    try {
      if (extra.isEmpty && !await _saveDraft()) return;
      _submitKey ??= randomKey();
      final suffix =
          '${extra.containsKey('rates_ok') ? 'r' : ''}${extra.containsKey('dup_reason') ? 'd' : ''}${extra.containsKey('over_limit_ok') ? 'o' : ''}';
      final r = await api.post(
          'purchase-bills/submit',
          {
            'id': billId,
            'version': version,
            'receive_all': perms.can('stock.receive') ? 1 : 0,
            if (payNow && parseRupees(payAmount.text) != null) 'pay': {'amount': payAmount.text.trim(), 'mode': payMode, 'reference': ''},
            ...extra,
          },
          idem: '$_submitKey$suffix');
      _submitKey = null;
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text(r['status'] == 'posted' ? 'Bill posted' : 'Sent for approval'),
          content: Text(r['status'] == 'posted'
              ? 'Added to ${supplier!['name']}\'s account. Goods: ${(r['receipt'] as Map?)?['receipt_no'] ?? 'to be received'}. '
                  'Payment: ${(r['payment'] as Map?)?['pay_no'] ?? 'on credit'}.'
              : (r['message'] ?? '').toString()),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('OK'))],
        ),
      );
      if (mounted) Navigator.pop(context);
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.isConflict && e.needs == 'rates_ok') {
        final rows = (e.data['rate_changes'] as List? ?? const [])
            .map((x) =>
                '${(x as Map)['item']}: ${rupees(x['previous_per_stock_unit'])} → ${rupees(x['new_per_stock_unit'])} per ${x['stock_unit'] ?? 'unit'}'
                '${x['change_pct'] != null ? ' (${(x['change_pct'] as num) > 0 ? '+' : ''}${x['change_pct']}%)' : ''}\nlast bill ${x['previous_date'] ?? ''}')
            .join('\n\n');
        if (await confirmDialog(context, 'Rate changed', '$rows\n\nSave these as the latest supplier prices?',
            ok: 'Yes, rates are correct')) {
          setState(() => busy = false);
          return _submit({...extra, 'rates_ok': 1});
        }
      } else if (e.isConflict && e.needs == 'dup_reason') {
        final why = await askText(context, 'Duplicate invoice?', e.message);
        if (why != null) {
          setState(() => busy = false);
          return _submit({...extra, 'dup_reason': why});
        }
      } else if (e.isConflict && e.needs == 'over_limit_ok') {
        if (await confirmDialog(context, 'Above credit limit', e.message, ok: 'Continue')) {
          setState(() => busy = false);
          return _submit({...extra, 'over_limit_ok': 1});
        }
      } else {
        if (!e.isNetwork) _submitKey = null;
        showMessage(context, e.message, error: true);
      }
    } catch (e) {
      if (mounted) showMessage(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sg = suggest;
    return Scaffold(
      appBar: AppBar(title: const Text('Purchase bill')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        SectionCard(title: '1 · Supplier', children: [
          OutlinedButton.icon(
            icon: const Icon(Icons.local_shipping_outlined),
            label: Text(supplier == null ? 'Choose supplier' : supplier!['name'].toString()),
            onPressed: busy ? null : _pickSupplier,
          ),
          if (sg != null) ...[
            const SizedBox(height: 8),
            SumRow((sg['balance'] as num? ?? 0) >= 0 ? 'You owe this supplier' : 'Advance with supplier',
                rupees((sg['balance'] as num? ?? 0).abs()),
                bold: true),
            if (sg['last_bill'] != null)
              SumRow('Last bill ${(sg['last_bill'] as Map)['invoice_no'] ?? ''} · ${sg['last_bill']['date']}',
                  rupees(sg['last_bill']['total'])),
          ],
        ]),
        if (supplier != null) ...[
          SectionCard(title: '2 · Bill', children: [
            TextField(controller: invoiceNo, decoration: const InputDecoration(labelText: 'Supplier bill number')),
            const SizedBox(height: 12),
            TextField(controller: invoiceDate, decoration: const InputDecoration(labelText: 'Bill date (YYYY-MM-DD)')),
          ]),
          SectionCard(title: '3 · Items', children: [
            if (lines.isEmpty) const Text('No previous items for this supplier. Add items below.'),
            if (lines.isNotEmpty && sg != null && (sg['suggested_items'] as List? ?? const []).isNotEmpty)
              const Text('Suggested from this supplier’s last deliveries. Fill only the quantity; leave empty to skip.',
                  style: TextStyle(fontSize: 12)),
            for (final l in lines) _lineTile(l),
            TextButton.icon(onPressed: _addItem, icon: const Icon(Icons.add), label: const Text('Add item')),
            const Divider(),
            SumRow('Bill total (estimate — server calculates the final)', rupees(grand), bold: true),
          ]),
          SectionCard(title: '4 · Bill photo (optional)', children: [
            Text(photos.isEmpty ? 'No new photo.' : '${photos.length} photo(s) will be attached on save.'),
            Row(children: [
              Expanded(
                  child: OutlinedButton.icon(
                      onPressed: () => _photo(ImageSource.camera), icon: const Icon(Icons.photo_camera), label: const Text('Camera'))),
              const SizedBox(width: 8),
              Expanded(
                  child: OutlinedButton.icon(
                      onPressed: () => _photo(ImageSource.gallery), icon: const Icon(Icons.photo_library), label: const Text('Gallery'))),
            ]),
          ]),
          if (perms.can('supplier.pay'))
            SectionCard(title: '5 · Payment', children: [
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: payNow,
                onChanged: (v) => setState(() => payNow = v),
                title: Text(payNow ? 'Paid now' : 'On credit (pay later)'),
              ),
              if (payNow) ...[
                MoneyField(controller: payAmount, label: 'Amount paid'),
                const SizedBox(height: 8),
                DropdownButtonFormField<String>(
                  initialValue: payMode,
                  decoration: const InputDecoration(labelText: 'Paid by'),
                  items: [
                    for (final m in ['Cash', 'Bank', 'UPI', 'Card']) DropdownMenuItem(value: m, child: Text(m)),
                  ],
                  onChanged: (v) => setState(() => payMode = v ?? 'Cash'),
                ),
              ],
            ]),
          const SizedBox(height: 8),
          FilledButton(onPressed: busy ? null : () => _submit(), child: Text(busy ? 'Saving…' : 'Submit bill')),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: busy ? null : _draft, child: const Text('Save draft')),
        ],
      ]),
    );
  }

  Widget _lineTile(BillLine l) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(l.name, style: const TextStyle(fontWeight: FontWeight.w600))),
            IconButton(tooltip: 'Remove', onPressed: () => setState(() => lines.remove(l)), icon: const Icon(Icons.close)),
          ]),
          if (l.lastRate != null) Text('Last: ${l.lastQty ?? ''} ${l.unit} @ ${rupees(l.lastRate)}', style: const TextStyle(fontSize: 12)),
          for (final o in l.otherSuppliers.take(3))
            Text('Other: ${(o as Map)['supplier'] ?? o['name'] ?? ''} ${rupees(o['rate'] ?? o['last_rate'])}/${o['unit'] ?? l.unit}',
                style: const TextStyle(fontSize: 12)),
          const SizedBox(height: 6),
          Row(children: [
            Expanded(child: NumberField(controller: l.qty, label: 'Qty (${l.unit})', onChanged: (_) => setState(() {}))),
            const SizedBox(width: 8),
            Expanded(child: MoneyField(controller: l.rate, label: 'Rate / ${l.unit}', onChanged: (_) => setState(() {}))),
          ]),
          if (l.lastRate != null && parseRupees(l.rate.text) != null && parseRupees(l.rate.text) != l.lastRate)
            Text(
              'Old ${rupees(l.lastRate)} → New ${rupees(parseRupees(l.rate.text))} '
              '(${((parseRupees(l.rate.text)! - l.lastRate!) * 100 / (l.lastRate == 0 ? 1 : l.lastRate!)).toStringAsFixed(1)}%)',
              style: const TextStyle(color: brandBurnt, fontSize: 12),
            ),
          Align(alignment: Alignment.centerRight, child: Text('= ${rupees(l.total)}')),
        ]),
      );
}

/// Searchable picker for suppliers / items (server lists, permission-filtered by the server).
class PickerScreen extends StatefulWidget {
  const PickerScreen({super.key, required this.api, required this.path, required this.listKey, required this.title});
  final Api api;
  final String path;
  final String listKey;
  final String title;
  @override
  State<PickerScreen> createState() => _PickerScreenState();
}

class _PickerScreenState extends State<PickerScreen> {
  String q = '';
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: Column(children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Search'),
              onChanged: (v) => setState(() => q = v.toLowerCase()),
            ),
          ),
          Expanded(
            child: Loader<Map<String, dynamic>>(
              load: () => widget.api.get(widget.path),
              builder: (context, d, _) {
                final all = [for (final x in (d[widget.listKey] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
                final list = all.where((x) => x['active'] != 0 && '${x['name']} ${x['code'] ?? ''}'.toLowerCase().contains(q)).toList();
                return ListView(children: [
                  if (list.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('Nothing found.')),
                  for (final x in list)
                    ListTile(
                      title: Text(x['name'].toString()),
                      subtitle: Text([
                        x['code'],
                        if (x['balance'] != null) 'balance ${rupees(x['balance'])}',
                        if (x['unit'] != null) x['unit'],
                        if (x['qty'] != null) 'in stock ${x['qty']}',
                      ].whereType<Object>().join(' · ')),
                      onTap: () => Navigator.pop(context, x),
                    ),
                ]);
              },
            ),
          ),
        ]),
      );
}
