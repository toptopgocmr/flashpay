import 'package:flutter/material.dart';
import '../../widgets/fp_avatar.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../auth/profile_select_screen.dart';
import '../shared/kyc_screen.dart';
import '../shared/notifications_screen.dart';
import '../shared/security_screen.dart';
import '../shared/support_screen.dart';
import 'linked_accounts_screen.dart';
import '../../l10n/l10n.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionProvider>();
    final user = session.user;
    final tier = user?.kycTier ?? 0;
    void open(Widget w) => Navigator.push(context, MaterialPageRoute(builder: (_) => w));

    return Scaffold(
      appBar: AppBar(title: Text(tr('Mon profil'))),
      body: ListView(
        padding: EdgeInsets.all(20),
        children: [
          Center(child: FpUserAvatar(radius: 36, background: FpColors.navy, foreground: Colors.white)),
          SizedBox(height: 12),
          Center(child: Text(user?.fullName ?? '', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold))),
          Center(child: Text(user?.phone ?? '', style: TextStyle(color: Colors.grey))),
          SizedBox(height: 8),
          Center(
            child: ActionChip(
              avatar: Icon(tier >= 2 ? Icons.verified : Icons.shield_outlined, size: 18, color: tier >= 2 ? FpColors.success : Color(0xFFA16207)),
              label: Text(tier >= 2 ? 'KYC complet · palier 2' : 'Palier $tier · relever mes plafonds'),
              backgroundColor: tier >= 2 ? Color(0xFFDCFCE7) : Color(0xFFFEF9C3),
              onPressed: () => open(KycScreen()),
            ),
          ),
          if (user != null && !user.hasPin)
            Padding(
              padding: EdgeInsets.only(top: 12),
              child: ListTile(
                tileColor: Color(0xFFE3F3FF),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                leading: Icon(Icons.password, color: Color(0xFFA16207)),
                title: Text(tr('Créez votre code PIN')),
                subtitle: Text(tr('Obligatoire pour confirmer vos opérations')),
                onTap: () => open(SecurityScreen()),
              ),
            ),
          SizedBox(height: 24),
          Card(
            child: Column(
              children: [
                ListTile(leading: Icon(Icons.verified_user_outlined), title: Text(tr('Identité & plafonds')), trailing: Icon(Icons.chevron_right), onTap: () => open(KycScreen())),
                Divider(height: 1),
                ListTile(leading: Icon(Icons.link), title: Text(tr('Comptes liés')), trailing: Icon(Icons.chevron_right), onTap: () => open(LinkedAccountsScreen())),
                Divider(height: 1),
                ListTile(
                  leading: Icon(Icons.notifications_none),
                  title: Text(tr('Notifications')),
                  trailing: (user?.unreadNotifications ?? 0) > 0 ? Badge(label: Text('${user!.unreadNotifications}')) : Icon(Icons.chevron_right),
                  onTap: () => open(NotificationsScreen()),
                ),
                Divider(height: 1),
                ListTile(leading: Icon(Icons.lock_outline), title: Text(tr('Sécurité, PIN et appareils')), trailing: Icon(Icons.chevron_right), onTap: () => open(SecurityScreen())),
                Divider(height: 1),
                ListTile(leading: Icon(Icons.support_agent), title: Text(tr('Aide & réclamations')), trailing: Icon(Icons.chevron_right), onTap: () => open(SupportScreen())),
              ],
            ),
          ),
          SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: () async {
              await session.logout();
              if (context.mounted) {
                Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => ProfileSelectScreen()),
                  (route) => false,
                );
              }
            },
            icon: Icon(Icons.logout, color: FpColors.danger),
            label: Text(tr('Se déconnecter'), style: TextStyle(color: FpColors.danger)),
          ),
        ],
      ),
    );
  }
}
