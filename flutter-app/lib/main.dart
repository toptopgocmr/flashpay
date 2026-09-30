import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:intl/intl.dart';
import 'app.dart';
import 'l10n/l10n.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Formats de date/nombre en français (DateFormat(..., 'fr_FR'))
  await initializeDateFormatting('fr_FR');
  Intl.defaultLocale = 'fr_FR';
  await L10n.load(); // langue choisie par l'utilisateur (français par défaut)
  runApp(const FlashPayApp());
}
