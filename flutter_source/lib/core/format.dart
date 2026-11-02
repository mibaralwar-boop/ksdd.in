import 'package:flutter/material.dart';

/// Integer paise → "₹1,23,456.50" (Indian digit grouping). Null → "—".
String rupees(Object? paise, {bool showPaise = true}) {
  if (paise == null) return '—';
  final p = (paise as num).round();
  final neg = p < 0;
  final abs = p.abs();
  final whole = abs ~/ 100;
  final frac = (abs % 100).toString().padLeft(2, '0');
  final s = whole.toString();
  String grouped;
  if (s.length <= 3) {
    grouped = s;
  } else {
    final last3 = s.substring(s.length - 3);
    var rest = s.substring(0, s.length - 3);
    final parts = <String>[];
    while (rest.length > 2) {
      parts.insert(0, rest.substring(rest.length - 2));
      rest = rest.substring(0, rest.length - 2);
    }
    if (rest.isNotEmpty) parts.insert(0, rest);
    grouped = '${parts.join(',')},$last3';
  }
  final body = showPaise && frac != '00' ? '$grouped.$frac' : grouped;
  return '${neg ? '−' : ''}₹$body';
}

/// Paise → plain rupee string for an input field ("1234.5" style, no grouping).
String rupeeInput(Object? paise) {
  if (paise == null) return '';
  final p = (paise as num).round();
  final whole = p ~/ 100;
  final frac = (p.abs() % 100);
  return frac == 0 ? '$whole' : '$whole.${frac.toString().padLeft(2, '0')}';
}

/// Validates a rupee amount typed by the user (max 2 decimals). Returns paise or null when invalid/empty.
int? parseRupees(String s) {
  final t = s.replaceAll(RegExp(r'[,₹\s]'), '');
  if (!RegExp(r'^\d{1,9}(\.\d{1,2})?$').hasMatch(t)) return null;
  final parts = t.split('.');
  final frac = parts.length > 1 ? parts[1].padRight(2, '0') : '00';
  return int.parse(parts[0]) * 100 + int.parse(frac);
}

String tr(BuildContext context, String en, String hi) =>
    Localizations.localeOf(context).languageCode == 'hi' ? hi : en;

const brandOrange = Color(0xFFE87520);
const brandDark = Color(0xFF191919);
const brandCream = Color(0xFFFFF8ED);
