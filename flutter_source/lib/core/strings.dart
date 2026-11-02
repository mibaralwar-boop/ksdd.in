import 'package:flutter/material.dart';

String tr(BuildContext context, String en, String hi) =>
    Localizations.localeOf(context).languageCode == 'hi' ? hi : en;
