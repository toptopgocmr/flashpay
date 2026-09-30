import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/merchant_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../auth/profile_select_screen.dart';
import '../client/voucher_screen.dart';
import '../shared/kyc_screen.dart';
import '../shared/notifications_screen.dart';
import '../shared/security_screen.dart';
import '../shared/support_screen.dart';
import '../shared/received_disputes_screen.dart';
import 'cashiers_screen.dart';
import 'dynamic_qr_screen.dart';
import 'merchant_collect_screen.dart';
import 'merchant_collections_screen.dart';
import 'merchant_outlets_screen.dart';
import 'merchant_qr_screen.dart';
import 'merchant_reports_screen.dart';
import 'merchant_scan_code_screen.dart';
import 'merchant_settlement_screen.dart';
import 'online_payments_screen.dart';
import '../../l10n/l10n.dart';

/// Espace marchand (maquette v2) : en-tête « Solde boutique » + ventes et
/// encaissé du jour, grille des habilitations marchand (QR, NFC, scan,
/// retraits, historique, caissiers, rapports), outils complémentaires et
/// support.
class MerchantHomeScreen extends StatefulWidget {
  const MerchantHomeScreen({super.key});

  @override
  State<MerchantHomeScreen> createState() => _MerchantHomeScreenState();
}

class _MerchantHomeScreenState extends State<MerchantHomeScreen> {
  final _merchantService = MerchantService();
  Map<String, dynamic>? _dashboard;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // Rafraîchit aussi le profil : habilitations modifiées depuis la console
    context.read<SessionProvider>().refreshUser().catchError((_) {});
    try {
      final data = await _merchantService.dashboard();
      if (mounted) setState(() => _dashboard = data);
    } catch (e) {
      if (mounted) fpSnack(context, 'Tableau de bord indisponible pour le moment.', error: true);
    }
  }

  Future<void> _open(Widget w) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => w));
    _load();
  }

  void _withdrawToAgent() {
    final iso = context.read<SessionProvider>().user?.wallet?.country ?? 'CG';
    const names = {'CG': 'République du Congo', 'CD': 'RD Congo', 'CM': 'Cameroun', 'GA': 'Gabon', 'TD': 'Tchad', 'CF': 'Centrafrique'};
    _open(VoucherScreen(channel: 'cash_pickup', country: iso, countryName: names[iso] ?? iso));
  }

  Future<void> _logout(SessionProvider session) async {
    await session.logout();
    if (mounted) {
      Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => ProfileSelectScreen()), (r) => false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionProvider>();
    final merchant = (_dashboard?['merchant'] ?? {}) as Map;
    final wallet = (_dashboard?['wallet'] ?? {}) as Map;
    final cur = '${wallet['currency'] ?? session.user?.wallet?.currency ?? 'XAF'}';
    final approved = (merchant['validation_status'] ?? session.user?.merchant?.validationStatus) == 'approved';

    final tiles = <(IconData, String, VoidCallback)>[
      (Icons.qr_code_2_rounded, 'Mon code QR', () => _open(MerchantQrScreen())),
      // Montant saisi → le client approche son téléphone (HCE) ; l'autocollant NFC
      // se programme depuis « Mon code QR ».
      (Icons.nfc_rounded, 'Encaisser NFC', () => _open(DynamicQrScreen())),
      (Icons.center_focus_weak_rounded, 'Scanner client', () => _open(MerchantScanCodeScreen())),
      (Icons.arrow_upward_rounded, 'Retrait banque/mobile', () => _open(MerchantSettlementScreen())),
      (Icons.storefront_outlined, 'Retrait vers agent FlashPay', _withdrawToAgent),
      (Icons.receipt_long_outlined, 'Historique des ventes', () => _open(MerchantCollectionsScreen())),
      (Icons.group_outlined, 'Équipe caissiers', () => _open(CashiersScreen())),
      (Icons.bar_chart_rounded, 'Rapports', () => _open(MerchantReportsScreen())),
    ];

    final tools = <(IconData, String, String, VoidCallback)>[
      (Icons.qr_code_scanner_rounded, 'Encaisser un montant', 'QR dynamique à usage unique', () => _open(DynamicQrScreen())),
      (Icons.send_to_mobile_outlined, 'Demande de paiement', 'Client sans application (USSD)', () => _open(MerchantCollectScreen())),
      (Icons.link_rounded, 'Lien de paiement', 'À partager par SMS ou WhatsApp', () => _open(DynamicQrScreen(paymentLink: true))),
      (Icons.store_mall_directory_outlined, 'Points de vente', 'Un QR par point de vente', () => _open(MerchantOutletsScreen())),
      (Icons.shopping_cart_checkout_rounded, 'Paiement en ligne', 'API et plugin e-commerce', () => _open(OnlinePaymentsScreen())),
    ];

    // Habilitations (console « Rôles & habilitations ») : tuiles retirées masquées
    final capOf = <String, bool>{
      'Mon code QR': session.can('collect') || session.can('receive'),
      'Encaisser NFC': session.can('collect'),
      'Scanner client': session.can('scan_client'),
      'Retrait banque/mobile': session.can('settlement'),
      'Retrait vers agent FlashPay': session.can('settlement'),
      'Équipe caissiers': session.can('cashiers'),
      'Rapports': session.can('reports'),
      'Encaisser un montant': session.can('collect'),
      'Demande de paiement': session.can('collect'),
      'Lien de paiement': session.can('collect'),
      'Paiement en ligne': session.can('collect'),
    };
    final shown = tiles.where((t) => capOf[t.$2] ?? true).toList();
    final shownTools = tools.where((t) => capOf[t.$2] ?? true).toList();

    return Scaffold(
      body: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.light,
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              FpRoleHeader(
                title: tr('Espace marchand'),
                subtitle: '${merchant['business_name'] ?? session.user?.merchant?.businessName ?? ''}',
                balanceLabel: 'Solde boutique',
                balance: fpMoney(fpInt(wallet['balance']), cur),
                stats: [
                  FpHeaderStat('Ventes du jour', '${fpInt(_dashboard?['today_count'])}'),
                  FpHeaderStat('Encaissé', fpMoney(fpInt(_dashboard?['today_collected']), cur)),
                ],
                notifications: session.user?.unreadNotifications ?? 0,
                onBell: () => _open(NotificationsScreen()),
                actions: [
                  PopupMenuButton<String>(
                    icon: Icon(Icons.more_vert_rounded, color: Colors.white),
                    onSelected: (v) {
                      switch (v) {
                        case 'kyc':
                          _open(KycScreen());
                        case 'security':
                          _open(SecurityScreen());
                        case 'logout':
                          _logout(session);
                      }
                    },
                    itemBuilder: (_) => [
                      PopupMenuItem(value: 'kyc', child: Text(tr('Documents KYC'))),
                      PopupMenuItem(value: 'security', child: Text(tr('Sécurité & PIN'))),
                      PopupMenuItem(value: 'logout', child: Text(tr('Se déconnecter'))),
                    ],
                  ),
                ],
              ),
              Padding(
                padding: EdgeInsets.fromLTRB(18, 20, 18, 28),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  if (_dashboard != null && !approved)
                    FpBanner('Votre boutique est en attente de validation. Certaines opérations peuvent être limitées.',
                        icon: Icons.hourglass_top_rounded, onTap: () => _open(KycScreen())),
                  FpTileGrid(children: [
                    for (var i = 0; i < shown.length; i++)
                      FpTile(icon: shown[i].$1, label: shown[i].$2, onTap: shown[i].$3, tone: fpToneAt(i)),
                  ]),
                  SizedBox(height: 16),
                  FpSupportRow(label: tr('Support marchand'), onTap: () => _open(SupportScreen())),
                  FpSupportRow(label: tr('Contestations reçues'), onTap: () => _open(const ReceivedDisputesScreen())),
                  SizedBox(height: 22),
                  Text(tr('Autres outils'), style: TextStyle(fontSize: 15, color: FpColors.muted)),
                  SizedBox(height: 10),
                  Container(
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), border: Border.all(color: FpColors.line)),
                    child: Column(children: [
                      for (var i = 0; i < shownTools.length; i++) ...[
                        if (i > 0) Divider(height: 1, indent: 70),
                        ListTile(
                          leading: FpPastille(shownTools[i].$1, tone: fpToneAt(i + 1), size: 42),
                          title: Text(shownTools[i].$2, style: TextStyle(fontWeight: FontWeight.w500)),
                          subtitle: Text(shownTools[i].$3),
                          trailing: Icon(Icons.chevron_right_rounded, color: FpColors.muted),
                          onTap: shownTools[i].$4,
                        ),
                      ],
                    ]),
                  ),
                ]),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
