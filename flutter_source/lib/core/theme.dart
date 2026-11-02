import 'package:flutter/material.dart';

import 'format.dart';

ThemeData financeTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: brandOrange, primary: brandOrange, surface: Colors.white);
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: brandCream,
    appBarTheme: const AppBarTheme(backgroundColor: brandCream, foregroundColor: brandDark, centerTitle: false),
    cardTheme: const CardThemeData(color: Colors.white, elevation: 0, margin: EdgeInsets.symmetric(vertical: 6),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(14)))),
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
      filled: true,
      fillColor: Colors.white,
      contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 16),
    ),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(minimumSize: const Size(48, 52))),
    outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(minimumSize: const Size(48, 52))),
    navigationBarTheme: const NavigationBarThemeData(backgroundColor: Colors.white, indicatorColor: Color(0xFFFFE2C7)),
  );
}
