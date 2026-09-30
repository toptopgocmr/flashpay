import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/transaction.dart';
import '../../providers/session_provider.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../services/transaction_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../../widgets/transaction_tile.dart';
import '../shared/kyc_screen.dart';
import '../shared/notifications_screen.dart';
import '../shared/jbem_assistant_screen.dart';
import '../shared/support_screen.dart';
import '../shared/chat_list_screen.dart';
import '../shared/translation_screen.dart';
import 'client_account_screen.dart';
import 'client_flows.dart';
import 'gifts_screen.dart';
import 'linked_accounts_screen.dart';
import 'money_request_screen.dart';
import 'nfc_pay_screen.dart';
import 'payment_request_screen.dart';
import 'qr_pay_screen.dart';
import 'qr_scan_screen.dart';
import 'services_screen.dart';
import 'split_bill_screen.dart';
import 'transaction_detail_screen.dart';
import 'transaction_history_screen.dart';
import '../../l10n/l10n.dart';

/// Une action de l'accueil client (utilisée aussi par la recherche).
class _HomeAction {
  final IconData icon;
  final String label;
  final Widget Function() screen;
  final String? cap; // habilitation requise (null = toujours visible)
  _HomeAction(this.icon, this.label, this.screen, [this.cap]);
}

/// Accueil Client (maquette v2) :
///  - en-tête bleu marine : FlashPay, solde, recherche, raccourcis
///    Scanner / Payer / NFC / Compte ;
///  - onglets Principal / Services en pastilles rondes ;
///  - notifications, invitations à associer carte bancaire et mobile money ;
///  - bouton flottant rouge de l'assistant JBEM.
class ClientHomeScreen extends StatefulWidget {
  const ClientHomeScreen({super.key});

  @override
  State<ClientHomeScreen> createState() => _ClientHomeScreenState();
}

class _ClientHomeScreenState extends State<ClientHomeScreen> with SingleTickerProviderStateMixin {
  final _txService = TransactionService();
  final _features = FeaturesService();
  late final TabController _tabs = TabController(length: 2, vsync: this);
  List<FpTransaction> _recent = [];
  List<Map<String, dynamic>> _intents = [];
  Map<String, dynamic>? _overview;
  List<String> _degraded = [];
  bool _loading = true;
  bool _hideBalance = false;

  static Widget _jbem() => JbemAssistantScreen();

  late final List<_HomeAction> _principal = [
    _HomeAction(Icons.account_balance_wallet_outlined, 'Recharge wallet', () => RechargeWalletScreen(), 'topup'),
    _HomeAction(Icons.arrow_downward_rounded, 'Retrait', () => WithdrawFlowScreen(), 'withdraw'),
    _HomeAction(Icons.send_outlined, 'Envoyer', () => SendFlowScreen(), 'send'),
    _HomeAction(Icons.qr_code_2_rounded, 'Code QR', () => QrPayScreen(), 'receive'),
    _HomeAction(Icons.chat_bubble_outline_rounded, 'Chat', () => ChatListScreen()),
    _HomeAction(Icons.translate_rounded, 'Traduction', () => TranslationScreen()),
  ];

  late final List<_HomeAction> _services = [
    _HomeAction(Icons.redeem_outlined, 'Cadeaux', () => GiftsScreen(), 'send'),
    _HomeAction(Icons.call_split_rounded, 'Partager', () => SplitBillScreen(), 'request'),
    _HomeAction(Icons.request_quote_outlined, 'Demander', () => RequestMoneyScreen(), 'request'),
    _HomeAction(Icons.inbox_outlined, 'Mes demandes', () => MoneyRequestsScreen(), 'request'),
    _HomeAction(Icons.link_rounded, 'Comptes liés', () => LinkedAccountsScreen()),
    _HomeAction(Icons.receipt_long_outlined, 'Historique', () => TransactionHistoryScreen()),
    _HomeAction(Icons.verified_user_outlined, 'Plafonds', () => KycScreen()),
    _HomeAction(Icons.smart_toy_outlined, 'JBEM', _jbem),
    _HomeAction(Icons.headset_mic_outlined, 'Support', () => SupportScreen()),
    _HomeAction(Icons.apps_rounded, 'Mini-apps', () => ServicesScreen()),
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final session = context.read<SessionProvider>();
      await session.refreshUser();
      final tx = await _txService.history();
      if (!mounted) return;
      setState(() {
        _recent = tx.take(5).toList();
        _loading = false;
      });
      // Paiements e-commerce à confirmer, cadeaux / parts en attente, mode dégradé
      final extra = await Future.wait([
        _features.pendingIntents().catchError((_) => <Map<String, dynamic>>[]),
        _features.overview().catchError((_) => <String, dynamic>{}),
        _features.status().catchError((_) => <String, dynamic>{}),
      ]);
      if (!mounted) return;
      setState(() {
        _intents = extra[0] as List<Map<String, dynamic>>;
        _overview = extra[1] as Map<String, dynamic>;
        final channels = ((extra[2] as Map<String, dynamic>)['channels'] ?? {}) as Map;
        _degraded = channels.values.where((c) => c is Map && c['enabled'] == false).map((c) => '${(c as Map)['message'] ?? c['label']}').toList();
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open(Widget screen) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => screen));
    _load();
  }

  /// Actions autorisées pour le profil connecté (habilitations console).
  List<_HomeAction> _visible(List<_HomeAction> list) {
    final session = context.read<SessionProvider>();
    return list.where((a) => session.can(a.cap)).toList();
  }

  void _more() {
    showModalBottomSheet(
      context: context,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: FpCircleGrid(
            children: [
              for (final (i, a) in _visible(_services).indexed)
                FpCircleAction(
                  icon: a.icon,
                  label: a.label,
                  tone: fpToneAt(i),
                  onTap: () {
                    Navigator.pop(ctx);
                    _open(a.screen());
                  },
                ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _search() async {
    final all = [
      ..._principal,
      ..._services,
      _HomeAction(Icons.center_focus_weak_rounded, 'Scanner', () => QrScanScreen(), 'pay'),
      _HomeAction(Icons.credit_card_rounded, 'Payer un marchand', () => PayFlowScreen(), 'pay'),
      _HomeAction(Icons.nfc_rounded, 'Payer sans contact (NFC)', () => NfcPayScreen(), 'pay'),
      _HomeAction(Icons.request_quote_outlined, 'Demander de l\'argent', () => RequestMoneyScreen(), 'request'),
      _HomeAction(Icons.account_circle_outlined, 'Mon compte', () => ClientAccountScreen()),
    ];
    final picked = await showSearch<_HomeAction?>(context: context, delegate: _ActionSearch(_visible(all)));
    if (picked != null && mounted) _open(picked.screen());
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final unread = user?.unreadNotifications ?? 0;
    final linked = ((_overview?['linked_accounts'] ?? []) as List).cast<Map>();
    final hasBank = linked.any((a) => a['type'] == 'bank' || a['type'] == 'card');
    final hasMobile = linked.any((a) => a['type'] == 'mobile_money');

    return Scaffold(
      floatingActionButton: FloatingActionButton.large(
        heroTag: 'jbem',
        tooltip: tr('Assistant JBEM'),
        shape: CircleBorder(),
        onPressed: () => _open(_jbem()),
        child: Icon(Icons.smart_toy_outlined, size: 38),
      ),
      body: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.light,
        child: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            _header(user?.wallet?.balance ?? 0, user?.wallet?.currency ?? 'XAF', unread),
            Padding(
              padding: EdgeInsets.fromLTRB(18, 8, 18, 110),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                TabBar(
                  controller: _tabs,
                  isScrollable: true,
                  tabAlignment: TabAlignment.start,
                  labelStyle: TextStyle(fontSize: 18, fontWeight: FontWeight.w500),
                  unselectedLabelStyle: TextStyle(fontSize: 18, fontWeight: FontWeight.w400),
                  indicatorWeight: 3,
                  dividerColor: Colors.transparent,
                  onTap: (_) => setState(() {}),
                  tabs: [Tab(text: tr('Principal')), Tab(text: tr('Services'))],
                ),
                SizedBox(height: 18),
                if (_tabs.index == 0)
                  FpCircleGrid(children: [
                    for (final (i, a) in _visible(_principal).indexed)
                      FpCircleAction(icon: a.icon, label: a.label, tone: fpToneAt(i), onTap: () => _open(a.screen())),
                    FpCircleAction(icon: Icons.more_horiz_rounded, label: tr('Plus'), tone: FpTone.red, onTap: _more),
                  ])
                else
                  FpCircleGrid(children: [
                    for (final (i, a) in _visible(_services).indexed)
                      FpCircleAction(icon: a.icon, label: a.label, tone: fpToneAt(i), onTap: () => _open(a.screen())),
                  ]),
                SizedBox(height: 22),
                // §14 — mode dégradé : information explicite avant toute opération
                ..._degraded.map((m) => FpBanner(m, icon: Icons.cloud_off, color: FpColors.danger)),
                if (unread > 0)
                  FpNotifBanner(unread == 1 ? '1 nouvelle notification' : '$unread nouvelles notifications', onTap: () => _open(NotificationsScreen())),
                // §4.7.4 — achats en ligne à confirmer dans l'app
                ..._intents.map((pi) => FpBanner(
                      'Paiement en attente : ${pi['merchant']?['name'] ?? ''} · ${fpMoney(fpInt(pi['amount']), '${pi['currency'] ?? 'XAF'}')}',
                      icon: Icons.shopping_bag_outlined,
                      color: FpColors.navy,
                      onTap: () => _open(PaymentRequestScreen(intentId: '${pi['id']}')),
                    )),
                if (((_overview?['pending_gifts'] ?? []) as List).isNotEmpty)
                  FpBanner('Vous avez ${(_overview!['pending_gifts'] as List).length} cadeau(x) à ouvrir', color: FpColors.red, icon: Icons.redeem,
                      onTap: () => _open(GiftsScreen())),
                // Demandes d'argent reçues, à payer ou refuser
                for (final mr in ((_overview?['pending_money_requests'] ?? []) as List).cast<Map>().take(3))
                  FpBanner(
                    '${mr['requester']?['full_name'] ?? 'Un ami'} vous demande ${fpMoney(fpInt(mr['amount']), '${mr['currency'] ?? 'XAF'}')}${(mr['note'] ?? '').toString().isNotEmpty ? ' · ${mr['note']}' : ''}',
                    icon: Icons.request_quote_outlined,
                    color: FpColors.red,
                    onTap: () => _open(MoneyRequestsScreen()),
                  ),
                if (((_overview?['pending_split_shares'] ?? []) as List).isNotEmpty)
                  FpBanner('${(_overview!['pending_split_shares'] as List).length} part(s) de note à régler', icon: Icons.call_split,
                      onTap: () => _open(SplitBillScreen())),
                if (user != null && user.kycTier < 1)
                  FpBanner('Vérifiez votre identité pour relever vos plafonds', icon: Icons.verified_user_outlined, color: FpColors.navy,
                      onTap: () => _open(KycScreen())),
                if (!hasBank)
                  FpPromptCard(
                    icon: Icons.credit_card_rounded,
                    tone: FpTone.blue,
                    title: tr('Associer une carte bancaire'),
                    subtitle: tr('Payez directement depuis votre carte'),
                    onTap: () => _open(LinkedAccountsScreen()),
                  ),
                if (!hasMobile)
                  FpPromptCard(
                    icon: Icons.smartphone_rounded,
                    tone: FpTone.red,
                    title: tr('Associer un compte mobile'),
                    subtitle: tr('Reliez votre numéro Mobile Money'),
                    onTap: () => _open(LinkedAccountsScreen()),
                  ),
                SizedBox(height: 10),
                Row(children: [
                  Expanded(child: Text(tr('Dernières transactions'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600))),
                  TextButton(onPressed: () => _open(TransactionHistoryScreen()), child: Text(tr('Tout voir'))),
                ]),
                if (_loading) Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
                if (!_loading && _recent.isEmpty) FpEmpty('Aucune transaction pour le moment.', icon: Icons.receipt_long_outlined),
                if (_recent.isNotEmpty)
                  Card(
                    child: Column(
                      children: _recent
                          .map((t) => TransactionTile(transaction: t, onTap: () => _open(TransactionDetailScreen(transactionId: t.id))))
                          .toList(),
                    ),
                  ),
              ]),
            ),
          ],
        ),
      ),
      ),
    );
  }

  Widget _header(int balance, String currency, int unread) {
    final canPay = context.read<SessionProvider>().can('pay');
    Widget shortcut(IconData icon, String label, Widget Function() screen) => Expanded(
          child: InkWell(
            onTap: () => _open(screen()),
            borderRadius: BorderRadius.circular(16),
            child: Column(children: [
              Container(
                width: 62,
                height: 62,
                decoration: BoxDecoration(color: Colors.white.withOpacity(.14), borderRadius: BorderRadius.circular(16)),
                child: Icon(icon, color: Colors.white, size: 28),
              ),
              SizedBox(height: 8),
              Text(tr(label), style: TextStyle(color: Colors.white, fontSize: 14.5)),
            ]),
          ),
        );

    return Container(
      padding: EdgeInsets.fromLTRB(20, MediaQuery.of(context).padding.top + 14, 12, 24),
      decoration: BoxDecoration(
        color: FpColors.navy,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(26)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Text(tr('FlashPay'), style: TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w600)),
          Spacer(),
          IconButton(
            tooltip: tr('Notifications'),
            onPressed: () => _open(NotificationsScreen()),
            icon: Badge(
              isLabelVisible: unread > 0,
              label: Text('$unread'),
              backgroundColor: FpColors.red,
              child: Icon(Icons.notifications_none_rounded, color: Colors.white, size: 26),
            ),
          ),
        ]),
        GestureDetector(
          onTap: () => setState(() => _hideBalance = !_hideBalance),
          child: Row(children: [
            Text(_hideBalance ? 'Solde  ••••••' : 'Solde  ${fpMoney(balance, currency)}',
                style: TextStyle(color: Color(0xE6FFFFFF), fontSize: 15, fontWeight: FontWeight.w500)),
            SizedBox(width: 6),
            Icon(_hideBalance ? Icons.visibility_off_outlined : Icons.visibility_outlined, color: Color(0x99FFFFFF), size: 17),
          ]),
        ),
        SizedBox(height: 14),
        Padding(
          padding: EdgeInsets.only(right: 8),
          child: Material(
            color: Colors.white.withOpacity(.16),
            shape: StadiumBorder(),
            child: InkWell(
              customBorder: StadiumBorder(),
              onTap: _search,
              child: Padding(
                padding: EdgeInsets.symmetric(horizontal: 18, vertical: 15),
                child: Row(children: [
                  Icon(Icons.search_rounded, color: Colors.white, size: 26),
                  SizedBox(width: 14),
                  Text(tr('Rechercher'), style: TextStyle(color: Color(0xE6FFFFFF), fontSize: 17)),
                ]),
              ),
            ),
          ),
        ),
        SizedBox(height: 22),
        Row(children: [
          shortcut(Icons.center_focus_weak_rounded, 'Scanner', () => QrScanScreen()),
          if (canPay) shortcut(Icons.credit_card_rounded, 'Payer', () => PayFlowScreen()),
          if (canPay) shortcut(Icons.nfc_rounded, 'NFC', () => NfcPayScreen()),
          shortcut(Icons.account_circle_outlined, 'Compte', () => ClientAccountScreen()),
        ]),
      ]),
    );
  }
}

/// Recherche parmi les actions de l'application.
class _ActionSearch extends SearchDelegate<_HomeAction?> {
  final List<_HomeAction> actions;
  _ActionSearch(this.actions) : super(searchFieldLabel: 'Rechercher un service');

  List<_HomeAction> get _matches {
    final q = query.trim().toLowerCase();
    return q.isEmpty ? actions : actions.where((a) => a.label.toLowerCase().contains(q)).toList();
  }

  @override
  List<Widget>? buildActions(BuildContext context) => [
        if (query.isNotEmpty) IconButton(icon: Icon(Icons.clear), onPressed: () => query = ''),
      ];

  @override
  Widget? buildLeading(BuildContext context) => IconButton(icon: Icon(Icons.arrow_back), onPressed: () => close(context, null));

  @override
  Widget buildResults(BuildContext context) => buildSuggestions(context);

  @override
  Widget buildSuggestions(BuildContext context) {
    final list = _matches;
    return ListView.builder(
      itemCount: list.length,
      itemBuilder: (_, i) => ListTile(
        leading: FpPastille(list[i].icon, tone: fpToneAt(i), size: 42),
        title: Text(list[i].label),
        onTap: () => close(context, list[i]),
      ),
    );
  }
}
