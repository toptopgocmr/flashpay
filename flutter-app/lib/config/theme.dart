import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Charte FlashPay v2 (maquettes « rôles & habilitations ») :
///  - bleu marine franc pour les en-têtes, soldes et boutons de validation ;
///  - rouge FlashPay pour l'accent (onglet actif, boutons « Continuer »,
///    liens, cases cochées, étapes) ;
///  - pastilles d'icônes rondes qui alternent rose (icône rouge) et
///    bleu pâle (icône marine) ;
///  - fond neutre, cartes à grands arrondis, bordures fines.
///
/// Les noms historiques sont conservés pour ne pas casser les écrans :
///  - `navy`   = bleu marine principal
///  - `orange` = accent (désormais le rouge FlashPay)
///  - `soft`   = pastille bleu pâle
class FpColors {
  static const navy = Color(0xFF1E3A8A);
  static const navyDark = Color(0xFF172E6E);
  static const red = Color(0xFFE11D2A);
  static const redSoft = Color(0xFFE5424F); // boutons « Continuer » des parcours
  static const orange = red;
  static const teal = Color(0xFF14B8A6);
  static const ink = Color(0xFF111827); // texte principal
  static const muted = Color(0xFF6B7280); // texte secondaire
  static const soft = Color(0xFFE6EDFB); // pastille bleu pâle
  static const rose = Color(0xFFFCE7EA); // pastille rose
  static const line = Color(0xFFE5E7EB); // bordures, soulignements
  static const surface = Colors.white;
  static const background = Color(0xFFF4F5F7);
  static const heroGradient = LinearGradient(
    colors: [Color(0xFF1E3A8A), Color(0xFF2446A6)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );
  static const danger = Color(0xFFDC2626);
  static const success = Color(0xFF16A34A);
}

/// Alias — certains écrans du projet utilisent `FlashPayColors` plutôt que
/// `FpColors`. Les deux pointent vers la même palette.
class FlashPayColors {
  static const navy = FpColors.navy;
  static const orange = FpColors.orange;
  static const red = FpColors.red;
  static const teal = FpColors.teal;
  static const ink = FpColors.ink;
  static const soft = FpColors.soft;
  static const background = FpColors.background;
  static const danger = FpColors.danger;
  static const success = FpColors.success;
}

class FpTheme {
  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: FpColors.navy,
      primary: FpColors.navy,
      secondary: FpColors.red,
      error: FpColors.danger,
      surface: FpColors.surface,
    );
    final base = ThemeData(useMaterial3: true, colorScheme: scheme);
    const pill = StadiumBorder();
    return base.copyWith(
      scaffoldBackgroundColor: FpColors.background,
      textTheme: GoogleFonts.interTextTheme(base.textTheme).apply(bodyColor: FpColors.ink, displayColor: FpColors.ink),
      appBarTheme: AppBarTheme(
        backgroundColor: FpColors.background,
        foregroundColor: FpColors.ink,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: GoogleFonts.inter(fontSize: 19, fontWeight: FontWeight.w700, color: FpColors.ink),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: FpColors.navy,
          foregroundColor: Colors.white,
          disabledBackgroundColor: FpColors.navy.withOpacity(.35),
          disabledForegroundColor: Colors.white70,
          elevation: 0,
          minimumSize: const Size.fromHeight(54),
          shape: pill,
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: FpColors.navy,
          foregroundColor: Colors.white,
          shape: pill,
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: FpColors.navy,
          side: const BorderSide(color: FpColors.line, width: 1.2),
          shape: pill,
          minimumSize: const Size(0, 48),
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: FpColors.red, textStyle: const TextStyle(fontWeight: FontWeight.w600)),
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: FpColors.red,
        foregroundColor: Colors.white,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
        prefixIconColor: FpColors.navy,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: FpColors.line)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: FpColors.line)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: FpColors.navy, width: 1.6)),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: Colors.white,
        margin: const EdgeInsets.symmetric(vertical: 6),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: const BorderSide(color: FpColors.line)),
      ),
      checkboxTheme: CheckboxThemeData(
        fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? FpColors.red : Colors.transparent),
        side: const BorderSide(color: FpColors.muted, width: 1.4),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
      ),
      listTileTheme: const ListTileThemeData(iconColor: FpColors.navy),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: FpColors.rose,
        elevation: 2,
        labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(
              fontSize: 12,
              fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
              color: s.contains(WidgetState.selected) ? FpColors.red : FpColors.muted,
            )),
        iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? FpColors.red : FpColors.muted)),
      ),
      tabBarTheme: const TabBarThemeData(
        labelColor: FpColors.red,
        unselectedLabelColor: FpColors.muted,
        indicatorColor: FpColors.red,
        dividerColor: FpColors.line,
      ),
      chipTheme: base.chipTheme.copyWith(shape: const StadiumBorder(), side: BorderSide.none, backgroundColor: FpColors.soft),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: Colors.white,
        showDragHandle: true,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      ),
      dialogTheme: DialogThemeData(backgroundColor: Colors.white, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22))),
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: FpColors.navy),
      dividerTheme: const DividerThemeData(color: FpColors.line, thickness: 1),
    );
  }
}

/// Alias — voir `FlashPayColors` ci-dessus (même logique pour le thème).
class FlashPayTheme {
  static ThemeData light() => FpTheme.light();
}
