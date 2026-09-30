import 'package:flutter/material.dart';
import '../../widgets/fp_avatar.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_design.dart';
import '../auth/profile_select_screen.dart';
import '../shared/security_screen.dart';
import '../shared/jbem_assistant_screen.dart';
import '../shared/support_screen.dart';
import '../shared/chat_list_screen.dart';
import '../shared/received_disputes_screen.dart';
import '../../l10n/language_picker.dart';
import '../shared/translation_screen.dart';
import 'client_flows.dart';
import 'linked_accounts_screen.dart';
import 'money_request_screen.dart';
import 'profile_screen.dart';
import 'qr_pay_screen.dart';
import 'transaction_history_screen.dart';
import '../../l10n/l10n.dart';

/// Menu « Compte » du client (maquette v2) : carte bleue avec initiales,
/// nom, numéro masqué et solde disponible ; grilles Principal et Services ;
/// déconnexion.
class ClientAccountScreen extends StatelessWidget {
  const ClientAccountScreen({super.key});

  static String initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return 'FP';
    return (parts.first[0] + (parts.length > 1 ? parts.last[0] : '')).toUpperCase();
  }

  static String shortName(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '';
    return parts.length == 1 ? parts.first : '${parts.first} ${parts.last[0]}.';
  }

  /// +242 06 xxx xx xx : on ne montre que l'indicatif et le préfixe opérateur.
  static String maskedPhone(String phone) {
    final d = phone.replaceAll(RegExp(r'\D'), '');
    if (d.startsWith('242') && d.length >= 5) return '+242 ${d.substring(3, 5)} xxx xx xx';
    if (d.length > 4) return '+${d.substring(0, d.length - 7 > 0 ? d.length - 7 : 1)} xxx xx xx';
    return phone;
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionProvider>();
    final user = session.user;
    void open(Widget w) => Navigator.push(context, MaterialPageRoute(builder: (_) => w));

    final principal = <(IconData, String, VoidCallback)>[
      (Icons.home_outlined, 'Accueil', () => Navigator.pop(context)),
      (Icons.north_east_rounded, 'Envoyer', () => open(SendFlowScreen())),
      (Icons.arrow_downward_rounded, 'Retrait', () => open(WithdrawFlowScreen())),
      (Icons.history_rounded, 'Historique', () => open(TransactionHistoryScreen())),
      (Icons.credit_card_rounded, 'Cartes', () => open(LinkedAccountsScreen())),
      (Icons.smartphone_rounded, 'Mobile money', () => open(LinkedAccountsScreen())),
      (Icons.qr_code_2_rounded, 'Code QR', () => open(QrPayScreen())),
      (Icons.request_quote_outlined, 'Demandes', () => open(MoneyRequestsScreen())),
    ];
    final services = <(IconData, String, VoidCallback)>[
      (Icons.chat_bubble_outline_rounded, 'Chat', () => open(ChatListScreen())),
      (Icons.smart_toy_outlined, 'JBEM', () => open(JbemAssistantScreen())),
      (Icons.translate_rounded, 'Traduction', () => open(TranslationScreen())),
      (Icons.shield_outlined, 'Sécurité', () => open(SecurityScreen())),
      (Icons.settings_outlined, 'Paramètres', () => open(ProfileScreen())),
      (Icons.language_rounded, 'Langue', () => fpShowLanguagePicker(context)),
      (Icons.gavel_rounded, 'Contestations reçues', () => open(const ReceivedDisputesScreen())),
      (Icons.headset_mic_outlined, 'Support', () => open(SupportScreen())),
    ];

    // Habilitations (console « Rôles & habilitations ») : entrées retirées masquées
    final capOf = <String, bool>{
      'Envoyer': session.can('send'),
      'Retrait': session.can('withdraw'),
      'Code QR': session.can('receive'),
      'Demandes': session.can('request'),
    };
    Widget grid(List<(IconData, String, VoidCallback)> all, {bool startBlue = false}) {
      final items = all.where((t) => capOf[t.$2] ?? true).toList();
      return FpCircleGrid(children: [
          for (var i = 0; i < items.length; i++)
            FpCircleAction(icon: items[i].$1, label: items[i].$2, tone: fpToneAt(startBlue ? i + 1 : i), onTap: items[i].$3),
        ]);
    }

    return Scaffold(
      appBar: AppBar(title: Text(tr('Mon compte'))),
      body: ListView(
        padding: EdgeInsets.fromLTRB(18, 4, 18, 28),
        children: [
          Container(
            padding: EdgeInsets.all(22),
            decoration: BoxDecoration(color: Color(0xFF2D4FD1), borderRadius: BorderRadius.circular(26)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                FpUserAvatar(radius: 32, initials: initials(user?.fullName ?? '')),
                SizedBox(width: 16),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(shortName(user?.fullName ?? ''), style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w600)),
                    SizedBox(height: 2),
                    Text(maskedPhone(user?.phone ?? ''), style: TextStyle(color: Color(0xE6FFFFFF), fontSize: 14.5)),
                  ]),
                ),
              ]),
              Padding(padding: EdgeInsets.symmetric(vertical: 18), child: Divider(color: Color(0x33FFFFFF), height: 1)),
              Text(tr('Solde disponible'), style: TextStyle(color: Color(0xE6FFFFFF), fontSize: 14.5)),
              SizedBox(height: 4),
              Text(fpMoney(user?.wallet?.balance ?? 0, user?.wallet?.currency ?? 'XAF'),
                  style: TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w600)),
            ]),
          ),
          SizedBox(height: 22),
          Text(tr('Principal'), style: TextStyle(fontSize: 17, color: FpColors.red, fontWeight: FontWeight.w500)),
          SizedBox(height: 14),
          grid(principal),
          SizedBox(height: 22),
          Text(tr('Services'), style: TextStyle(fontSize: 15, color: FpColors.muted)),
          SizedBox(height: 14),
          grid(services, startBlue: true),
          SizedBox(height: 24),
          FpSupportRow(
            label: tr('Déconnexion'),
            icon: Icons.logout_rounded,
            onTap: () async {
              await session.logout();
              if (context.mounted) {
                Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => ProfileSelectScreen()), (r) => false);
              }
            },
          ),
        ],
      ),
    );
  }
}
