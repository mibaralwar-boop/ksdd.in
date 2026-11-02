import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/format.dart';

/// Loads data once, shows loading / error-with-retry / content. Pull to refresh reloads.
class Loader<T> extends StatefulWidget {
  const Loader({super.key, required this.load, required this.builder});
  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;
  @override
  State<Loader<T>> createState() => _LoaderState<T>();
}

class _LoaderState<T> extends State<Loader<T>> {
  late Future<T> _f;
  @override
  void initState() {
    super.initState();
    _f = widget.load();
  }

  Future<void> _reload() async {
    final f = widget.load();
    setState(() => _f = f);
    try {
      await f;
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<T>(
        future: _f,
        builder: (context, s) {
          if (s.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (s.hasError) return ErrorPanel(error: s.error!, onRetry: _reload);
          return RefreshIndicator(onRefresh: _reload, child: widget.builder(context, s.data as T, _reload));
        },
      );
}

class ErrorPanel extends StatelessWidget {
  const ErrorPanel({super.key, required this.error, this.onRetry});
  final Object error;
  final VoidCallback? onRetry;
  @override
  Widget build(BuildContext context) {
    final e = error;
    final icon = e is ApiError && e.isNetwork
        ? Icons.wifi_off
        : e is ApiError && e.isForbidden
            ? Icons.lock_outline
            : Icons.error_outline;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 48, color: brandOrange),
          const SizedBox(height: 12),
          Text(e is ApiError ? e.message : 'Something went wrong. Please try again.', textAlign: TextAlign.center),
          if (onRetry != null) ...[
            const SizedBox(height: 16),
            OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Try again')),
          ],
        ]),
      ),
    );
  }
}

void showMessage(BuildContext context, String text, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(text), backgroundColor: error ? const Color(0xFFB3261E) : null));
}

String errorText(Object e) => e is ApiError ? e.message : 'Something went wrong. Please try again.';

Future<bool> confirmDialog(BuildContext context, String title, String body, {String ok = 'Confirm'}) async =>
    await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(title),
        content: SingleChildScrollView(child: Text(body)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(ok)),
        ],
      ),
    ) ??
    false;

Future<String?> askText(BuildContext context, String title, String body, {String label = 'Reason'}) {
  final ctl = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (c) => AlertDialog(
      title: Text(title),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(body),
        const SizedBox(height: 12),
        TextField(controller: ctl, decoration: InputDecoration(labelText: label), autofocus: true),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c), child: const Text('Cancel')),
        FilledButton(onPressed: () => ctl.text.trim().isEmpty ? null : Navigator.pop(c, ctl.text.trim()), child: const Text('Continue')),
      ],
    ),
  );
}

class MoneyField extends StatelessWidget {
  const MoneyField({super.key, required this.controller, required this.label, this.onChanged, this.big = false});
  final TextEditingController controller;
  final String label;
  final ValueChanged<String>? onChanged;
  final bool big;
  @override
  Widget build(BuildContext context) => TextFormField(
        controller: controller,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
        style: big ? const TextStyle(fontSize: 22, fontWeight: FontWeight.w600) : null,
        decoration: InputDecoration(labelText: label, prefixText: '₹ '),
        validator: (v) => v == null || v.trim().isEmpty || parseRupees(v) != null ? null : 'Enter a valid amount (max 2 decimals)',
        onChanged: onChanged,
      );
}

class NumberField extends StatelessWidget {
  const NumberField({super.key, required this.controller, required this.label, this.onChanged, this.decimals = true});
  final TextEditingController controller;
  final String label;
  final ValueChanged<String>? onChanged;
  final bool decimals;
  @override
  Widget build(BuildContext context) => TextFormField(
        controller: controller,
        keyboardType: TextInputType.numberWithOptions(decimal: decimals),
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(decimals ? r'[0-9.]' : r'[0-9]'))],
        decoration: InputDecoration(labelText: label),
        onChanged: onChanged,
      );
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key});
  final String? status;
  static const _map = {
    'not_started': ('Not started', Color(0xFFB3261E)),
    'pending': ('Pending', Color(0xFFB26A00)),
    'draft': ('Draft', Color(0xFFB26A00)),
    'completed': ('Completed', Color(0xFF1B7F3B)),
    'done': ('Completed', Color(0xFF1B7F3B)),
    'approved': ('Final · locked', Color(0xFF1B7F3B)),
    'submitted': ('Submitted', Color(0xFF1565C0)),
    'posted': ('Posted', Color(0xFF1B7F3B)),
    'paid': ('Paid', Color(0xFF1B7F3B)),
    'partially_paid': ('Partly paid', Color(0xFFB26A00)),
    'unpaid': ('Unpaid', Color(0xFFB3261E)),
    'locked': ('Locked', brandDark),
    'skipped': ('Skipped', Color(0xFFB26A00)),
    'later': ('Add later', Color(0xFFB26A00)),
    'excluded': ('Excluded', Colors.grey),
  };
  @override
  Widget build(BuildContext context) {
    final m = _map[status] ?? (status ?? '—', Colors.grey);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: m.$2.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(20)),
      child: Text(m.$1, style: TextStyle(color: m.$2, fontWeight: FontWeight.w600, fontSize: 12)),
    );
  }
}

class SumRow extends StatelessWidget {
  const SumRow(this.label, this.value, {super.key, this.bold = false, this.color});
  final String label;
  final String value;
  final bool bold;
  final Color? color;
  @override
  Widget build(BuildContext context) {
    final st = TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.normal, fontSize: bold ? 16 : 14, color: color);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(flex: 3, child: Text(label, style: st)),
        const SizedBox(width: 8),
        Flexible(flex: 2, child: Text(value, style: st, textAlign: TextAlign.end)),
      ]),
    );
  }
}

class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, required this.children});
  final String? title;
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title != null) ...[Text(title!, style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 10)],
            ...children,
          ]),
        ),
      );
}

class Banner2 extends StatelessWidget {
  const Banner2(this.text, {super.key, this.color = const Color(0xFFFFE9D6), this.icon = Icons.info_outline});
  final String text;
  final Color color;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        margin: const EdgeInsets.symmetric(vertical: 6),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(12)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(text)),
        ]),
      );
}
