import 'package:flutter/material.dart';

import 'format.dart';

/// Finance 360 V36 theme: warm cream background, orange accent, deep-brown ink, white rounded cards, 48 dp targets.
ThemeData financeTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: brandOrange,
    primary: brandOrange,
    onPrimary: Colors.white,
    surface: brandPaper,
    onSurface: brandDark,
  );
  final r15 = BorderRadius.circular(15);
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: brandCream,
    textTheme: const TextTheme(
      headlineLarge: TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: brandDark, letterSpacing: -0.6),
      headlineMedium: TextStyle(fontSize: 23, fontWeight: FontWeight.w800, color: brandDark),
      titleLarge: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: brandDark),
      titleMedium: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: brandDark),
      bodyLarge: TextStyle(fontSize: 15, color: brandDark),
      bodyMedium: TextStyle(fontSize: 14, color: brandDark),
      bodySmall: TextStyle(fontSize: 12, color: brandMuted),
      labelLarge: TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: brandCream,
      surfaceTintColor: Colors.transparent,
      foregroundColor: brandDark,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: brandDark),
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      margin: const EdgeInsets.symmetric(vertical: 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: const BorderSide(color: brandLine)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: brandOrange,
        foregroundColor: Colors.white,
        minimumSize: const Size(48, 52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(17)),
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: brandOrange,
        foregroundColor: Colors.white,
        elevation: 0,
        minimumSize: const Size(48, 52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(17)),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: brandBurnt,
        side: const BorderSide(color: brandOrange, width: 1.2),
        minimumSize: const Size(48, 50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
    ),
    textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(foregroundColor: brandBurnt, minimumSize: const Size(48, 48))),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      hintStyle: const TextStyle(color: brandMuted),
      border: OutlineInputBorder(borderRadius: r15, borderSide: const BorderSide(color: brandLine)),
      enabledBorder: OutlineInputBorder(borderRadius: r15, borderSide: const BorderSide(color: brandLine)),
      focusedBorder: OutlineInputBorder(borderRadius: r15, borderSide: const BorderSide(color: brandOrange, width: 1.5)),
      errorBorder: OutlineInputBorder(borderRadius: r15, borderSide: const BorderSide(color: brandRed)),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: Colors.white,
      selectedColor: brandOrange,
      secondarySelectedColor: brandOrange,
      side: const BorderSide(color: brandLine),
      labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: brandDark),
      secondaryLabelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: Colors.white),
      checkmarkColor: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(30)),
    ),
    dividerTheme: const DividerThemeData(color: brandLine, thickness: 1, space: 1),
    listTileTheme: const ListTileThemeData(iconColor: brandBurnt, minVerticalPadding: 10),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Colors.white,
      indicatorColor: brandPeach,
      height: 72,
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? brandBurnt : brandMuted)),
      labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(
          fontSize: 11.5,
          fontWeight: s.contains(WidgetState.selected) ? FontWeight.w800 : FontWeight.w600,
          color: s.contains(WidgetState.selected) ? brandBurnt : brandMuted)),
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: brandCream,
      showDragHandle: true,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
    ),
    splashColor: brandPeach,
  );
}
