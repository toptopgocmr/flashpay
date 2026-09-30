import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../l10n/language_picker.dart';
import '../../models/user.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_logo.dart';
import 'login_screen.dart';
import '../../l10n/l10n.dart';

/// Écran d'entrée : « Qui êtes-vous ? » en grille 2 × 2 (Client, Marchand,
/// Agent, Caissier). Chaque profil est un compte distinct côté API — ce
/// choix détermine le formulaire de connexion et l'espace ouvert ensuite.
class ProfileSelectScreen extends StatelessWidget {
  const ProfileSelectScreen({super.key});

  void _open(BuildContext context, FpProfile p) =>
      Navigator.push(context, MaterialPageRoute(builder: (_) => LoginScreen(profile: p)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButtonLocation: FloatingActionButtonLocation.endTop,
      floatingActionButton: const Padding(padding: EdgeInsets.only(top: 8), child: FpLanguageButton()),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            child: FpAuthCard(
              children: [
                Center(child: FpLogo(size: 76)),
                SizedBox(height: 18),
                Text(tr('FlashPay'), textAlign: TextAlign.center, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w500, color: FpColors.ink)),
                SizedBox(height: 6),
                Text(tr('Envoyez, payez et encaissez simplement'),
                    textAlign: TextAlign.center, style: TextStyle(fontSize: 15.5, color: FpColors.muted)),
                SizedBox(height: 30),
                Text(tr('Qui êtes-vous ?'), style: TextStyle(fontSize: 16, color: FpColors.ink)),
                SizedBox(height: 14),
                GridView.count(
                  crossAxisCount: 2,
                  shrinkWrap: true,
                  physics: NeverScrollableScrollPhysics(),
                  mainAxisSpacing: 14,
                  crossAxisSpacing: 14,
                  childAspectRatio: .86,
                  children: [
                    _RoleCard(icon: Icons.person_outline_rounded, tone: FpTone.blue, title: tr('Client'), subtitle: tr('Envoyer, payer, recevoir'), onTap: () => _open(context, FpProfile.client)),
                    _RoleCard(icon: Icons.storefront_outlined, tone: FpTone.red, title: tr('Marchand'), subtitle: tr('Encaisser, gérer ma boutique'), onTap: () => _open(context, FpProfile.merchant)),
                    _RoleCard(icon: Icons.work_outline_rounded, tone: FpTone.blue, title: tr('Agent'), subtitle: tr('Dépôts et retraits'), onTap: () => _open(context, FpProfile.agent)),
                    _RoleCard(icon: Icons.point_of_sale_outlined, tone: FpTone.red, title: tr('Caissier'), subtitle: tr('Encaisser pour un marchand'), onTap: () => _open(context, FpProfile.cashier)),
                  ],
                ),
                SizedBox(height: 24),
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.lock_outline_rounded, color: FpColors.muted, size: 17),
                  SizedBox(width: 8),
                  Flexible(child: Text(tr('Paiements sécurisés · MTN, Airtel et plus'), style: TextStyle(color: FpColors.muted, fontSize: 13.5))),
                ]),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RoleCard extends StatelessWidget {
  final IconData icon;
  final FpTone tone;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  const _RoleCard({required this.icon, required this.tone, required this.title, required this.subtitle, required this.onTap});

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(24),
          child: Container(
            padding: EdgeInsets.symmetric(horizontal: 10, vertical: 14),
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(24), border: Border.all(color: Color(0xFFD1D5DB), width: 1.4)),
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              FpPastille(icon, tone: tone, size: 64),
              SizedBox(height: 12),
              Text(tr(title), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600, color: FpColors.ink)),
              SizedBox(height: 6),
              Text(tr(subtitle), textAlign: TextAlign.center, maxLines: 2, style: TextStyle(fontSize: 13.5, color: FpColors.muted, height: 1.3)),
            ]),
          ),
        ),
      );
}
