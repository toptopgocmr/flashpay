import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../auth/profile_select_screen.dart';
import '../shared/notifications_screen.dart';
import '../shared/support_screen.dart';
import 'dynamic_qr_screen.dart';
import '../../l10n/l10n.dart';

/// Espace caissier (§3.2.3, maquette v2) : mêmes codes visuels que l'espace
/// marchand, mais habilitations limitées à l'ENCAISSEMENT (QR dynamique,
/// lien de paiement) et au journal de SES encaissements — pas de solde
/// boutique, pas de retrait, pas de gestion d'équipe.
class CashierHomeScreen extends StatefulWidget {
  const CashierHomeScreen({super.key});

  @override
  State<CashierHomeScreen> createState() => _CashierHomeScreenState();
}

class _CashierHomeScreenState extends State<CashierHomeScreen> {
  final _service = MerchantToolsService();
  Map<String, dynamic>? _d;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // Rafraîchit aussi le profil : habilitations modifiées depuis la console
    context.read<SessionProvider>().refreshUser().catchError((_) {});
    try {
      final d = await _service.cashierCollections();
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _open(Widget w) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => w));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionProvider>();
    final rows = ((_d?['data'] ?? []) as List).cast<Map>();
    final subtitle = [
      if (_d?['merchant'] != null) '${_d!['merchant']}',
      if (_d?['outlet'] != null) '${_d!['outlet']}',
    ].join(' · ');

    final tiles = <(IconData, String, VoidCallback)>[
      (Icons.qr_code_2_rounded, 'Encaisser', () => _open(DynamicQrScreen())),
      (Icons.link_rounded, 'Lien de paiement', () => _open(DynamicQrScreen(paymentLink: true))),
      (Icons.receipt_long_outlined, 'Mes encaissements', () => _open(_CashierCollectionsScreen(rows: rows))),
      (Icons.notifications_none_rounded, 'Notifications', () => _open(NotificationsScreen())),
    ];

    // Habilitations (console « Rôles & habilitations ») : tuiles retirées masquées
    final capOf = <String, bool>{
      'Encaisser': session.can('collect'),
      'Lien de paiement': session.can('collect'),
      'Mes encaissements': session.can('reports'),
    };
    final shown = tiles.where((t) => capOf[t.$2] ?? true).toList();

    return Scaffold(
      body: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.light,
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              FpRoleHeader(
                title: tr('Espace caissier'),
                subtitle: subtitle,
                balanceLabel: 'Encaissé aujourd\'hui',
                balance: fpMoney(fpInt(_d?['today'])),
                stats: [FpHeaderStat('Ventes du jour', '${fpInt(_d?['today_count'])}')],
                notifications: session.user?.unreadNotifications ?? 0,
                onBell: () => _open(NotificationsScreen()),
                actions: [
                  IconButton(
                    tooltip: tr('Se déconnecter'),
                    icon: Icon(Icons.logout_rounded, color: Colors.white),
                    onPressed: () async {
                      await session.logout();
                      if (context.mounted) {
                        Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => ProfileSelectScreen()), (r) => false);
                      }
                    },
                  ),
                ],
              ),
              Padding(
                padding: EdgeInsets.fromLTRB(18, 20, 18, 28),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  FpTileGrid(children: [
                    for (var i = 0; i < shown.length; i++)
                      FpTile(icon: shown[i].$1, label: shown[i].$2, onTap: shown[i].$3, tone: fpToneAt(i)),
                  ]),
                  SizedBox(height: 16),
                  FpSupportRow(label: tr('Support caissier'), onTap: () => _open(SupportScreen())),
                  SizedBox(height: 22),
                  Text(tr('Derniers encaissements'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
                  SizedBox(height: 8),
                  if (_d != null && rows.isEmpty) FpEmpty('Aucun encaissement aujourd\'hui.'),
                  ...rows.take(5).map((t) => _CollectionTile(t: t)),
                ]),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CollectionTile extends StatelessWidget {
  final Map t;
  const _CollectionTile({required this.t});

  @override
  Widget build(BuildContext context) {
    final ok = t['status'] == 'successful';
    return Card(
      child: ListTile(
        leading: FpPastille(Icons.arrow_downward_rounded, tone: FpTone.red, size: 42),
        title: Text(fpMoney(fpInt(t['amount']), '${t['currency'] ?? 'XAF'}'), style: TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text('${t['meta']?['payer_name'] ?? ''} · ${fpDate(t['created_at'])}'),
        trailing: FpStatusChip(ok ? 'Reçu' : '${t['status']}', tone: ok ? 'ok' : 'warn'),
      ),
    );
  }
}

class _CashierCollectionsScreen extends StatelessWidget {
  final List<Map> rows;
  const _CashierCollectionsScreen({required this.rows});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Mes encaissements'))),
        body: rows.isEmpty
            ? FpEmpty('Aucun encaissement.')
            : ListView(padding: EdgeInsets.all(16), children: rows.map((t) => _CollectionTile(t: t)).toList()),
      );
}
