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
    setState(() {
      _f = f;
    });
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
    final (icon, title) = switch (e) {
      ApiError(isNetwork: true) => (Icons.wifi_off_rounded, 'You are offline'),
      ApiError(isForbidden: true) => (Icons.lock_outline_rounded, 'No permission'),
      ApiError(isReadOnly: true) => (Icons.lock_clock_outlined, 'Read-only subscription'),
      ApiError(status: 404) => (Icons.search_off_rounded, 'Not found'),
      _ => (Icons.error_outline_rounded, 'Could not load'),
    };
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: EmptyState(
          icon: icon,
          title: title,
          subtitle: e is ApiError ? e.message : 'Something went wrong. Please try again.',
          action: onRetry == null
              ? null
              : OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh_rounded), label: const Text('Try again')),
        ),
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
      decoration: BoxDecoration(color: m.$2.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(30)),
      child: Text(m.$1, style: TextStyle(color: m.$2, fontWeight: FontWeight.w800, fontSize: 11.5)),
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
  const SectionCard({super.key, this.title, required this.children, this.icon, this.trailing, this.color});
  final String? title;
  final List<Widget> children;
  final IconData? icon;
  final Widget? trailing;
  final Color? color;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: SoftCard(
          color: color,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title != null) ...[
              Row(children: [
                if (icon != null) ...[Icon(icon, size: 20, color: brandOrange), const SizedBox(width: 8)],
                Expanded(child: Text(title!, style: Theme.of(context).textTheme.titleMedium)),
                if (trailing != null) trailing!,
              ]),
              const SizedBox(height: 10),
            ],
            ...children,
          ]),
        ),
      );
}

class Banner2 extends StatelessWidget {
  const Banner2(this.text, {super.key, this.color = brandPeach, this.icon = Icons.info_outline_rounded});
  final String text;
  final Color color;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        margin: const EdgeInsets.symmetric(vertical: 6),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(17)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 21, color: brandBurnt),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: brandDark))),
        ]),
      );
}

// ------------------------------------------------------------------ V36 design components

/// White rounded card with a hairline border (V36 "SoftCard").
class SoftCard extends StatelessWidget {
  const SoftCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.color, this.onTap});
  final Widget child;
  final EdgeInsets padding;
  final Color? color;
  final VoidCallback? onTap;
  @override
  Widget build(BuildContext context) {
    final box = Container(
      width: double.infinity,
      padding: padding,
      decoration: BoxDecoration(
        color: color ?? Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color == null ? brandLine : Colors.transparent),
      ),
      // Own Material so ListTiles / ink inside the card paint correctly on its background.
      child: Material(type: MaterialType.transparency, child: child),
    );
    if (onTap == null) return box;
    return Material(color: Colors.transparent, child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(20), child: box));
  }
}

/// Large page heading with optional subtitle and trailing widget.
class PageTitle extends StatelessWidget {
  const PageTitle(this.text, {super.key, this.subtitle, this.trailing});
  final String text;
  final String? subtitle;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(text, style: Theme.of(context).textTheme.headlineMedium),
              if (subtitle != null) ...[
                const SizedBox(height: 3),
                Text(subtitle!, style: const TextStyle(color: brandMuted, fontSize: 12.5)),
              ],
            ]),
          ),
          if (trailing != null) trailing!,
        ]),
      );
}

enum PillTone { neutral, good, bad, info }

/// Small rounded status label (colour is never the only signal: the text says it).
class StatusPill extends StatelessWidget {
  const StatusPill(this.text, {super.key, this.tone = PillTone.neutral});
  final String text;
  final PillTone tone;
  @override
  Widget build(BuildContext context) {
    final (bg, fg) = switch (tone) {
      PillTone.good => (brandGreenBg, brandGreen),
      PillTone.bad => (brandRedBg, brandRed),
      PillTone.info => (const Color(0xFFE6EEF9), const Color(0xFF1F4F8F)),
      PillTone.neutral => (brandPeach, brandBurnt),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(30)),
      child: Text(text, style: TextStyle(color: fg, fontSize: 11.5, fontWeight: FontWeight.w800)),
    );
  }
}

/// Compact metric card: icon, value, label.
class MetricTile extends StatelessWidget {
  const MetricTile({super.key, required this.label, required this.value, required this.icon, this.onTap, this.sub});
  final String label;
  final String value;
  final IconData icon;
  final VoidCallback? onTap;
  final String? sub;
  @override
  Widget build(BuildContext context) => SoftCard(
        onTap: onTap,
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          CircleAvatar(radius: 17, backgroundColor: brandPeach, child: Icon(icon, size: 18, color: brandBurnt)),
          const SizedBox(height: 10),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(value, style: const TextStyle(fontSize: 19, color: brandDark, fontWeight: FontWeight.w900)),
          ),
          Text(label, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: brandMuted, fontSize: 12)),
          if (sub != null) Text(sub!, style: const TextStyle(color: brandBurnt, fontSize: 11, fontWeight: FontWeight.w700)),
        ]),
      );
}

/// Orange gradient summary card (V36 hero). [parts] are label → value pairs shown in the lower strip.
class HeroCard extends StatelessWidget {
  const HeroCard(
      {super.key, required this.title, required this.value, this.parts = const [], this.icon = Icons.bar_chart_rounded, this.onTap});
  final String title;
  final String value;
  final List<(String, String)> parts;
  final IconData icon;
  final VoidCallback? onTap;
  @override
  Widget build(BuildContext context) => Semantics(
        label: '$title $value',
        child: GestureDetector(
          onTap: onTap,
          child: Container(
            width: double.infinity,
            margin: const EdgeInsets.symmetric(vertical: 6),
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              gradient:
                  const LinearGradient(colors: [brandOrange, Color(0xFFCF5413)], begin: Alignment.topLeft, end: Alignment.bottomRight),
              borderRadius: BorderRadius.circular(22),
              boxShadow: [BoxShadow(color: brandOrange.withAlpha(45), blurRadius: 16, offset: const Offset(0, 7))],
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Icon(icon, color: Colors.white),
                const SizedBox(width: 8),
                Expanded(child: Text(title, style: const TextStyle(color: Colors.white, fontSize: 13.5, fontWeight: FontWeight.w600))),
                if (onTap != null) const Icon(Icons.chevron_right_rounded, color: Colors.white),
              ]),
              const SizedBox(height: 10),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(value, style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w900)),
              ),
              if (parts.isNotEmpty) ...[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: Colors.white.withAlpha(36), borderRadius: BorderRadius.circular(13)),
                  child: Row(children: [
                    for (final p in parts)
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(p.$1, style: const TextStyle(color: Colors.white70, fontSize: 11.5)),
                          FittedBox(
                            fit: BoxFit.scaleDown,
                            alignment: Alignment.centerLeft,
                            child: Text(p.$2, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                          ),
                        ]),
                      ),
                  ]),
                ),
              ],
            ]),
          ),
        ),
      );
}

/// Helpful empty state: icon, heading, explanation and an optional primary action (no fake figures).
class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, required this.subtitle, this.action});
  final IconData icon;
  final String title;
  final String subtitle;
  final Widget? action;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 28, horizontal: 12),
        child: Column(children: [
          CircleAvatar(radius: 36, backgroundColor: brandPeach, child: Icon(icon, color: brandOrange, size: 36)),
          const SizedBox(height: 14),
          Text(title, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 6),
          Text(subtitle, textAlign: TextAlign.center, style: const TextStyle(color: brandMuted, height: 1.45)),
          if (action != null) ...[const SizedBox(height: 16), action!],
        ]),
      );
}

/// List row with a peach icon circle.
class MenuTile extends StatelessWidget {
  const MenuTile({super.key, required this.title, required this.icon, this.subtitle, this.onTap, this.trailing});
  final String title;
  final String? subtitle;
  final IconData icon;
  final VoidCallback? onTap;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
        onTap: onTap,
        leading: CircleAvatar(backgroundColor: brandPeach, child: Icon(icon, size: 21, color: brandBurnt)),
        title: Text(title, style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w700)),
        subtitle: subtitle != null ? Text(subtitle!, maxLines: 2, overflow: TextOverflow.ellipsis) : null,
        trailing: trailing ?? (onTap != null ? const Icon(Icons.chevron_right_rounded, color: brandMuted) : null),
      );
}

/// Search field.
class SearchBox extends StatelessWidget {
  const SearchBox({super.key, this.hint = 'Search', this.onChanged});
  final String hint;
  final ValueChanged<String>? onChanged;
  @override
  Widget build(BuildContext context) =>
      TextField(onChanged: onChanged, decoration: InputDecoration(prefixIcon: const Icon(Icons.search_rounded), hintText: hint));
}

/// Business date helpers (dates come from the server as YYYY-MM-DD in Asia/Kolkata).
String ymd(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
DateTime parseYmd(String s) => DateTime.tryParse(s) ?? DateTime.now();

/// A from–to period picked with chips (This month / Last month / 30 days) or the date range picker.
class PeriodBar extends StatelessWidget {
  const PeriodBar({super.key, required this.from, required this.to, required this.businessDate, required this.onChanged});
  final String from;
  final String to;
  final String businessDate;
  final void Function(String from, String to) onChanged;

  @override
  Widget build(BuildContext context) {
    final bd = parseYmd(businessDate);
    final thisFrom = ymd(DateTime(bd.year, bd.month, 1));
    final lastFrom = ymd(DateTime(bd.year, bd.month - 1, 1));
    final lastTo = ymd(DateTime(bd.year, bd.month, 0));
    final d30 = ymd(bd.subtract(const Duration(days: 30)));
    final options = <(String, String, String)>[
      ('This month', thisFrom, businessDate),
      ('Last month', lastFrom, lastTo),
      ('30 days', d30, businessDate),
    ];
    final custom = !options.any((o) => o.$2 == from && o.$3 == to);
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(children: [
        for (final o in options)
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(label: Text(o.$1), selected: o.$2 == from && o.$3 == to, onSelected: (_) => onChanged(o.$2, o.$3)),
          ),
        ChoiceChip(
          avatar: Icon(Icons.date_range_rounded, size: 18, color: custom ? Colors.white : brandBurnt),
          label: Text(custom ? '$from → $to' : 'Dates'),
          selected: custom,
          onSelected: (_) async {
            final r = await showDateRangePicker(
              context: context,
              firstDate: DateTime(2020),
              lastDate: bd,
              initialDateRange: DateTimeRange(start: parseYmd(from), end: parseYmd(to).isAfter(bd) ? bd : parseYmd(to)),
            );
            if (r != null) onChanged(ymd(r.start), ymd(r.end));
          },
        ),
      ]),
    );
  }
}

/// Right-hand amount + status of a list row. Scales down instead of overflowing with large system text.
class TrailingStack extends StatelessWidget {
  const TrailingStack({super.key, required this.children});
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 140),
        child: FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerRight,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: children),
        ),
      );
}
