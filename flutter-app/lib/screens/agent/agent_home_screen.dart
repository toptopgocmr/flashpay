import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/agent_service.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../auth/profile_select_screen.dart';
import '../shared/kyc_screen.dart';
import '../shared/notifications_screen.dart';
import '../shared/security_screen.dart';
import '../shared/support_screen.dart';
import '../shared/received_disputes_screen.dart';
import 'agent_cash_in_screen.dart';
import 'agent_cash_out_screen.dart';
import 'agent_cashbook_screen.dart';
import 'agent_float_screen.dart';
import 'agent_history_screen.dart';
import 'agent_qr_screen.dart';
import '../../services/nfc_bridge.dart';
import '../../l10n/l10n.dart';

/// Espace agent / sous-agent / super-agent (maquette v2) :
/// en-tête « Solde flottant » + commissions du jour, puis grille d'actions
/// limitée aux habilitations agent (dépôt, retrait, scan, QR, NFC,
/// réapprovisionnement, historique, caisse) et support.
class AgentHomeScreen extends StatefulWidget {
  const AgentHomeScreen({super.key});

  @override
  State<AgentHomeScreen> createState() => _AgentHomeScreenState();
}

class _AgentHomeScreenState extends State<AgentHomeScreen> {
  final _service = AgentService();
  Map<String, dynamic>? _d;
  List<Map<String, dynamic>> _recent = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // Rafraîchit aussi le profil : habilitations modifiées depuis la console
    context.read<SessionProvider>().refreshUser().catchError((_) {});
    try {
      final r = await Future.wait([_service.dashboard(), _service.history()]);
      if (!mounted) return;
      setState(() {
        _d = r[0] as Map<String, dynamic>;
        _recent = (r[1] as List<Map<String, dynamic>>).take(5).toList();
        _loading = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open(Widget screen) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => screen));
    _load();
  }

  void _scanChoice() {
    showModalBottomSheet(
      context: context,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Scanner le client pour…'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
            SizedBox(height: 14),
            FpPromptCard(
              icon: Icons.arrow_downward_rounded,
              tone: FpTone.red,
              title: tr('Un dépôt'),
              subtitle: tr('QR « Espèces chez un agent » du client'),
              onTap: () {
                Navigator.pop(ctx);
                _open(AgentCashInScreen());
              },
            ),
            FpPromptCard(
              icon: Icons.arrow_upward_rounded,
              tone: FpTone.blue,
              title: tr('Un retrait'),
              subtitle: tr('Code de retrait présenté par le client'),
              onTap: () {
                Navigator.pop(ctx);
                _open(AgentCashOutScreen());
              },
            ),
          ]),
        ),
      ),
    );
  }

  /// NFC client : le client approche son téléphone (code de dépôt ou de retrait).
  Future<void> _nfcClient() async {
    final raw = await FpNfc.readSheet(context,
        hint: 'Le client ouvre son code de dépôt (Recharger → Espèces chez un agent) ou son code de retrait, puis approche son téléphone.');
    if (raw == null || !mounted) return;
    final uri = Uri.tryParse(raw.trim());
    final host = uri != null && uri.scheme == 'flashpay' ? uri.host : '';
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    if (host == 'cashout' || (host.isEmpty && digits.length == 10)) {
      _open(AgentCashOutScreen(initialRaw: raw));
    } else if (host == 'code' || (host.isEmpty && digits.length == 18)) {
      _open(AgentCashInScreen(initialRaw: raw));
    } else {
      fpSnack(context, 'Ce téléphone n\'affiche pas de code de dépôt ou de retrait FlashPay.', error: true);
    }
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
    final cur = '${_d?['currency'] ?? session.user?.wallet?.currency ?? 'XAF'}';
    final agent = (_d?['agent'] ?? session.user?.agent ?? {}) as Map;
    final today = (_d?['today'] ?? {}) as Map;
    final pending = agent['validation_status'] == 'pending';
    final title = agent['is_super_agent'] == true
        ? 'Espace super-agent'
        : (agent['parent_agent_id'] != null ? 'Espace sous-agent' : 'Espace agent');
    final opsToday = fpInt(today['cash_in_count']) + fpInt(today['cash_out_count']);
    final pendingFloat = fpInt(_d?['pending_float_requests']);
    final pendingOps = fpInt(_d?['pending_operations']);

    final tiles = <(IconData, String, VoidCallback, String?)>[
      (Icons.arrow_downward_rounded, 'Dépôt client', () => _open(AgentCashInScreen(byPhone: true)), null),
      (Icons.arrow_upward_rounded, 'Retrait client', () => _open(AgentCashOutScreen()), null),
      (Icons.center_focus_weak_rounded, 'Scanner client', _scanChoice, null),
      (Icons.qr_code_2_rounded, 'Mon code QR', () => _open(AgentQrScreen(agentCode: agent['agent_code']?.toString(), posCode: agent['pos_code']?.toString())), null),
      (Icons.nfc_rounded, 'NFC client', _nfcClient, null),
      (Icons.account_balance_wallet_outlined, 'Réapprovisionner', () => _open(AgentFloatScreen()), pendingFloat > 0 ? '$pendingFloat' : null),
      (Icons.history_rounded, 'Historique', () => _open(AgentHistoryScreen()), null),
      (Icons.menu_book_outlined, 'Ma caisse', () => _open(AgentCashbookScreen()), pendingOps > 0 ? '$pendingOps' : null),
    ];

    // Habilitations (console « Rôles & habilitations ») : tuiles retirées masquées
    final canCash = session.can('cash_in') || session.can('cash_out');
    final capOf = <String, bool>{
      'Dépôt client': session.can('cash_in'),
      'Retrait client': session.can('cash_out'),
      'Scanner client': canCash,
      'NFC client': canCash,
      'Mon code QR': session.can('receive') || canCash,
      'Réapprovisionner': session.can('float'),
      'Ma caisse': session.can('reports'),
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
                title: title,
                subtitle: [
                  if (agent['agent_code'] != null) 'Agent ${agent['agent_code']}',
                  if (agent['pos_code'] != null) 'PV ${agent['pos_code']}',
                ].join(' · '),
                balanceLabel: 'Solde flottant (agent)',
                balance: fpMoney(fpInt(_d?['float']), cur),
                stats: [
                  FpHeaderStat('Commissions du jour', fpMoney(fpInt(_d?['commission_today']), cur)),
                  FpHeaderStat('Opérations du jour', '$opsToday'),
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
                      PopupMenuItem(value: 'kyc', child: Text(tr('Documents & plafonds'))),
                      PopupMenuItem(value: 'security', child: Text(tr('Sécurité & PIN'))),
                      PopupMenuItem(value: 'logout', child: Text(tr('Se déconnecter'))),
                    ],
                  ),
                ],
              ),
              Padding(
                padding: EdgeInsets.fromLTRB(18, 20, 18, 28),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  if (pending) FpBanner('Votre compte agent est en attente de validation par FlashPay.', icon: Icons.hourglass_top_rounded),
                  if (_d?['low_float'] == true)
                    FpBanner('Float bas : faites une demande d\'approvisionnement.', icon: Icons.warning_amber_rounded, color: FpColors.danger,
                        onTap: () => _open(AgentFloatScreen())),
                  FpTileGrid(children: [
                    for (var i = 0; i < shown.length; i++)
                      FpTile(icon: shown[i].$1, label: shown[i].$2, onTap: shown[i].$3, badge: shown[i].$4, tone: fpToneAt(i)),
                  ]),
                  SizedBox(height: 16),
                  FpSupportRow(label: tr('Support agent'), onTap: () => _open(SupportScreen())),
                  FpSupportRow(label: tr('Contestations reçues'), onTap: () => _open(const ReceivedDisputesScreen())),
                  SizedBox(height: 22),
                  Row(children: [
                    Expanded(child: Text(tr('Dernières opérations'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600))),
                    TextButton(onPressed: () => _open(AgentHistoryScreen()), child: Text(tr('Tout voir'))),
                  ]),
                  if (_loading) Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
                  if (!_loading && _recent.isEmpty) FpEmpty('Aucune opération pour le moment.'),
                  if (_recent.isNotEmpty) Card(child: Column(children: _recent.map((t) => AgentOpTile(op: t)).toList())),
                ]),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Ligne d'historique agent (dépôt / retrait).
class AgentOpTile extends StatelessWidget {
  final Map<String, dynamic> op;
  const AgentOpTile({super.key, required this.op});

  @override
  Widget build(BuildContext context) {
    final deposit = op['kind'] == 'deposit';
    final ok = op['status'] == 'successful';
    final date = DateTime.tryParse('${op['created_at']}')?.toLocal();
    return ListTile(
      leading: FpPastille(deposit ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded, tone: deposit ? FpTone.red : FpTone.blue, size: 42),
      title: Text('${op['label']}${op['client'] != null ? ' · ${op['client']}' : ''}', maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text([
        if (date != null) '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')} ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}',
        if (!ok) '${op['status']}',
        if ((op['commission'] ?? 0) > 0) 'commission ${fpMoney(op['commission'] as num, op['currency'] ?? 'XAF')}',
      ].join(' · ')),
      trailing: Text(
        '${deposit ? '−' : '+'}${fpMoney((op['amount'] ?? 0) as num, (op['currency'] ?? 'XAF') as String)}',
        style: TextStyle(fontWeight: FontWeight.w700, color: deposit ? FpColors.navy : FpColors.success),
      ),
    );
  }
}
