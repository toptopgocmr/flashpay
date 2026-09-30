import 'package:flutter/material.dart';
import '../config/navigation.dart';
import '../screens/splash_screen.dart';
import '../services/features_service.dart';
import 'l10n.dart';

/// Change la langue de l'application puis relance l'interface (écran de
/// démarrage → accueil) pour que tous les écrans s'affichent dans la nouvelle
/// langue. La préférence est aussi envoyée au serveur (SMS, notifications).
Future<void> fpChangeLanguage(String lang) async {
  if (lang == L10n.lang) return;
  await L10n.set(lang);
  FeaturesService().setLanguage(lang).catchError((_) {});
  fpNavigatorKey.currentState?.pushAndRemoveUntil(
    MaterialPageRoute(builder: (_) => const SplashScreen()),
    (_) => false,
  );
}

/// Feuille de choix de la langue.
Future<void> fpShowLanguagePicker(BuildContext context) async {
  final picked = await showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (ctx) => SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Text(tr('Langue'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        ),
        for (final e in L10n.languages.entries)
          ListTile(
            leading: Text(e.key == 'fr' ? '🇫🇷' : '🇬🇧', style: const TextStyle(fontSize: 24)),
            title: Text(e.value),
            trailing: e.key == L10n.lang ? const Icon(Icons.check_circle, color: Colors.green) : null,
            onTap: () => Navigator.pop(ctx, e.key),
          ),
        const SizedBox(height: 8),
      ]),
    ),
  );
  if (picked != null) await fpChangeLanguage(picked);
}

/// Bouton « 🌐 FR / EN » pour les écrans d'accueil et de connexion.
class FpLanguageButton extends StatelessWidget {
  final Color? color;
  const FpLanguageButton({super.key, this.color});

  @override
  Widget build(BuildContext context) => TextButton.icon(
        onPressed: () => fpShowLanguagePicker(context),
        icon: Icon(Icons.language_rounded, color: color),
        label: Text(L10n.lang.toUpperCase(), style: TextStyle(color: color, fontWeight: FontWeight.w700)),
      );
}
