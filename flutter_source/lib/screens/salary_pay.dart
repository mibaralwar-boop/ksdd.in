// Record Salary Payment (V38 §2.4). This RECORDS a payment already made in cash or online; the app never moves money.
// Method (Cash / Online) AND amount are both mandatory; partial payments are allowed; the status changes only after the
// server confirms (`POST salary/payments`, idempotent with one key per payment attempt).
import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../widgets/common.dart';

class SalaryPayResult {
  SalaryPayResult({required this.balance, required this.payNo});
  final int balance;
  final String payNo;
  bool get fullyPaid => balance <= 0;
}

/// Validates the payment form. Returns an error text or null. [due] is the server's remaining due in paise.
String? validateSalaryPayment({required String? method, required String amount, required int due, String? online}) {
  if (method == null) return 'Choose how the salary was paid: Cash or Online.';
  if (method == 'Online' && online == null) return 'Choose UPI or Bank transfer.';
  if (amount.trim().isEmpty) return 'Enter the amount paid.';
  final p = parseRupees(amount);
  if (p == null) return 'Enter a valid amount (max 2 decimals).';
  if (p <= 0) return 'Amount must be more than ₹0.';
  if (p > due) return 'Amount ${rupees(p)} is more than the remaining due ${rupees(due)}.';
  return null;
}

class SalaryPayScreen extends StatefulWidget {
  const SalaryPayScreen({super.key, required this.session, required this.period, required this.line, this.historyOnly = false});
  final Session session;
  final String period;
  final Map<String, dynamic> line;
  final bool historyOnly;
  @override
  State<SalaryPayScreen> createState() => _SalaryPayScreenState();
}

class _SalaryPayScreenState extends State<SalaryPayScreen> {
  Api get api => widget.session.api;
  Permissions get perms => widget.session.permissions!;
  Map<String, dynamic>? slip;
  Object? err;
  String? method; // Cash | Online
  String? online; // UPI | Bank
  final amount = TextEditingController();
  final reference = TextEditingController();
  final note = TextEditingController();
  String? error;
  bool busy = false;
  String _key = randomKey();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      err = null;
      slip = null;
    });
    try {
      final r = await api.get('salary/payslip', {'period': widget.period, 'employee_id': '${widget.line['employee_id']}'});
      setState(() => slip = r);
    } catch (e) {
      setState(() => err = e);
    }
  }

  Map<String, dynamic> get l => ((slip?['line'] as Map?) ?? widget.line).cast<String, dynamic>();
  int get due => (l['balance'] as num?)?.toInt() ?? 0;
  String get mode => method == 'Cash' ? 'Cash' : (online ?? '');

  Future<void> _pay() async {
    final e = validateSalaryPayment(method: method, amount: amount.text, due: due, online: online);
    setState(() => error = e);
    if (e != null) return;
    final paise = parseRupees(amount.text)!;
    final emp = slip?['employee'] as Map? ?? const {};
    final ok = await confirmDialog(
      context,
      'Record ${rupees(paise)} salary payment?',
      '${widget.line['name']} (${emp['code'] ?? widget.line['code'] ?? ''}) · ${widget.period}\n'
          'Paid by: ${method == 'Cash' ? 'Cash from this branch’s cash account' : '$online (${online == 'UPI' ? 'UPI / Paytm account' : 'Bank account'})'}\n'
          '${reference.text.trim().isNotEmpty ? 'Reference: ${reference.text.trim()}\n' : ''}'
          'Remaining due after this: ${rupees(due - paise)}\n\n'
          'Only record money that was actually paid. This is posted to the salary ledger and the ${method == 'Cash' ? 'cash' : 'bank / UPI'} book.',
      ok: 'Confirm & record',
    );
    if (!ok || !mounted) return;
    setState(() => busy = true);
    try {
      final r = await api.post(
          'salary/payments',
          {
            'period': widget.period,
            'employee_id': widget.line['employee_id'],
            'amount': rupeeInput(paise),
            'mode': mode,
            'reference': reference.text.trim(),
            'date': perms.businessDate,
            'client_key': _key,
          },
          idem: _key);
      final res = SalaryPayResult(balance: (r['balance'] as num?)?.toInt() ?? 0, payNo: (r['pay_no'] ?? '').toString());
      _key = randomKey(); // the next payment is a new request
      if (!mounted) return;
      setState(() => busy = false);
      await showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          icon: const Icon(Icons.check_circle_rounded, color: brandGreen, size: 48),
          title: Text(res.fullyPaid ? 'Salary fully paid' : 'Part payment recorded'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            SumRow('Receipt', res.payNo),
            SumRow('Paid now', rupees(paise)),
            SumRow('Method', mode),
            SumRow('Remaining due', rupees(res.balance), bold: true),
          ]),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: Text(res.fullyPaid ? 'Next employee' : 'Done'))],
        ),
      );
      if (mounted) Navigator.pop(context, res);
    } on ApiError catch (e) {
      if (!mounted) return;
      // A lost reply is retried with the SAME key (never paid twice); a business refusal reloads the server figures.
      setState(() => error = e.isNetwork ? '${e.message} Tap Confirm again — the same payment will not be recorded twice.' : e.message);
      if (e.isConflict || e.status == 422) await _load();
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canCash = perms.can('pay.cash');
    final canOnline = perms.can('pay.upi') || perms.can('pay.bank');
    return Scaffold(
      appBar: AppBar(title: Text(widget.historyOnly ? 'Payments & slip' : 'Record salary payment')),
      body: err != null
          ? ErrorPanel(error: err!, onRetry: _load)
          : slip == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
                  PageTitle('${widget.line['name']}',
                      subtitle: '${(slip!['employee'] as Map?)?['code'] ?? widget.line['code'] ?? ''} · ${widget.period} · '
                          '${widget.session.restaurantName} · ${perms.branchName}'),
                  SectionCard(title: 'Salary for this month', icon: Icons.receipt_long_outlined, children: [
                    SumRow('Net salary this month', rupees(l['net_payable'])),
                    SumRow('+ Earlier salary still owed (arrears)', rupees(l['carried_forward'])),
                    SumRow('= Total payable', rupees(((l['net_payable'] as num?) ?? 0) + ((l['carried_forward'] as num?) ?? 0)),
                        bold: true),
                    SumRow('− Already paid', rupees(l['paid'])),
                    SumRow('Remaining due', rupees(due), bold: true, color: due > 0 ? brandRed : brandGreen),
                    if (((l['advance_recovery'] as num?) ?? 0) > 0)
                      SumRow('(Advance recovered inside this salary)', rupees(l['advance_recovery'])),
                  ]),
                  _history(),
                  if (!widget.historyOnly && due > 0 && perms.can('payroll.pay') && !perms.readOnly) ...[
                    SectionCard(title: 'Payment', icon: Icons.payments_outlined, children: [
                      const Text('How was it paid? *', style: TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(height: 6),
                      SegmentedButton<String>(
                        emptySelectionAllowed: true,
                        showSelectedIcon: false,
                        segments: [
                          ButtonSegment(
                              value: 'Cash', label: const Text('Cash'), icon: const Icon(Icons.payments_outlined), enabled: canCash),
                          ButtonSegment(
                              value: 'Online', label: const Text('Online'), icon: const Icon(Icons.qr_code_2_rounded), enabled: canOnline),
                        ],
                        selected: {if (method != null) method!},
                        onSelectionChanged: (v) => setState(() {
                          method = v.isEmpty ? null : v.first;
                          error = null;
                        }),
                      ),
                      if (method == 'Online') ...[
                        const SizedBox(height: 10),
                        Wrap(spacing: 8, children: [
                          if (perms.can('pay.upi'))
                            ChoiceChip(
                                label: const Text('UPI'), selected: online == 'UPI', onSelected: (_) => setState(() => online = 'UPI')),
                          if (perms.can('pay.bank'))
                            ChoiceChip(
                                label: const Text('Bank transfer'),
                                selected: online == 'Bank',
                                onSelected: (_) => setState(() => online = 'Bank')),
                        ]),
                        const SizedBox(height: 10),
                        TextField(controller: reference, decoration: const InputDecoration(labelText: 'UTR / reference number')),
                      ],
                      if (method == 'Cash')
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text('Paid from this branch’s cash account only.', style: Theme.of(context).textTheme.bodySmall),
                        ),
                      const SizedBox(height: 12),
                      MoneyField(controller: amount, label: 'Amount paid *', big: true, onChanged: (_) => setState(() => error = null)),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton(
                            onPressed: () => setState(() => amount.text = rupeeInput(due)),
                            child: Text('Full remaining due ${rupees(due)}')),
                      ),
                      Text('Part payments are allowed. The rest stays due and the person stays in “Part paid”.',
                          style: Theme.of(context).textTheme.bodySmall),
                    ]),
                    if (error != null) Banner2(error!, color: brandRedBg, icon: Icons.error_outline_rounded),
                    const SizedBox(height: 8),
                    FilledButton.icon(
                      icon: busy
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Icon(Icons.check_rounded),
                      label: const Text('Confirm & record payment'),
                      onPressed: busy ? null : _pay,
                    ),
                  ],
                  if (!widget.historyOnly && due <= 0) const Banner2('Nothing is due for this month.', icon: Icons.task_alt_rounded),
                ]),
    );
  }

  Widget _history() {
    final pays = [for (final x in (slip?['payments'] as List? ?? const [])) (x as Map).cast<String, dynamic>()];
    return SectionCard(title: 'Payments recorded (${pays.length})', icon: Icons.history_rounded, children: [
      if (pays.isEmpty) const Text('No payment yet for this month.'),
      for (final p in pays)
        SumRow(
          '${p['date']} · ${p['pay_no']} · ${p['mode']}${(p['reference'] ?? '') != '' ? ' · ${p['reference']}' : ''}${p['voided'] == 1 ? ' · cancelled' : ''}',
          rupees(p['amount']),
          color: p['voided'] == 1 ? brandMuted : null,
        ),
      if (pays.isNotEmpty)
        Text('A wrong payment is cancelled on the website (Salary → Payments) with a reason; it is never deleted.',
            style: Theme.of(context).textTheme.bodySmall),
    ]);
  }
}
