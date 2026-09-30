import 'package:shared_preferences/shared_preferences.dart';
import 'strings_en.dart';

/// Langues de l'application. Le français est la langue source : chaque texte
/// est écrit en français dans le code et traduit à l'affichage par [tr].
/// Pour ajouter une langue : créer strings_xx.dart (même principe que
/// strings_en.dart) et l'ajouter à [L10n.dictionaries] et [L10n.languages].
class L10n {
  static const languages = {'fr': 'Français', 'en': 'English'};
  static final Map<String, Map<String, String>> dictionaries = {'en': stringsEn};

  static String _lang = 'fr';
  static String get lang => _lang;

  static const _key = 'flashpay_lang';

  /// À appeler au démarrage (main.dart), avant runApp.
  static Future<void> load() async {
    try {
      final p = await SharedPreferences.getInstance();
      final v = p.getString(_key);
      if (v != null && languages.containsKey(v)) _lang = v;
    } catch (_) {}
  }

  static Future<void> set(String lang) async {
    if (!languages.containsKey(lang)) return;
    _lang = lang;
    try {
      final p = await SharedPreferences.getInstance();
      await p.setString(_key, lang);
    } catch (_) {}
  }
}

/// Traduit un texte français dans la langue choisie (repli : le texte français).
String tr(String fr) {
  if (L10n.lang == 'fr') return fr;
  return L10n.dictionaries[L10n.lang]?[fr] ?? fr;
}
