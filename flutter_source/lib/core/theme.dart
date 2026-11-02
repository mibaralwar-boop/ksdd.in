import 'package:flutter/material.dart';

ThemeData financeTheme() => ThemeData(
  useMaterial3: true,
  colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFFE87520)),
  scaffoldBackgroundColor: const Color(0xFFFFF8ED),
  appBarTheme: const AppBarTheme(backgroundColor: Color(0xFFFFF8ED)),
  inputDecorationTheme: const InputDecorationTheme(
    border: OutlineInputBorder(), filled: true, fillColor: Colors.white,
    contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 16),
  ),
  filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(
    minimumSize: const Size(48, 52),
  )),
);
