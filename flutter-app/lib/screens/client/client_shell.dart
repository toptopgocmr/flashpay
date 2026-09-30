import 'package:flutter/material.dart';
import 'client_home_screen.dart';

/// Espace client (maquette v2) : l'accueil porte lui-même la navigation —
/// raccourcis Scanner / Payer / NFC / Compte dans l'en-tête, onglets
/// Principal / Services, et le menu « Compte » (historique, cartes,
/// sécurité, paramètres, support, déconnexion).
class ClientShell extends StatelessWidget {
  const ClientShell({super.key});

  @override
  Widget build(BuildContext context) => ClientHomeScreen();
}
