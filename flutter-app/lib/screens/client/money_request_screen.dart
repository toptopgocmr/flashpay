import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../services/nfc_bridge.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../../widgets/fp_country.dart';
import 'qr_scan_screen.dart';
import 'mobile_money_screen.dart';
import 'package:share_plus/share_plus.dart';
import '../../l10n/l10n.dart';

enum _Who { number, scanner, nfc }

/// « Demander de l'argent » : désigner l'ami (numéro, scan de son QR ou NFC),
/// saisir le montant et un motif. L'ami reçoit une notification (ou un SMS
/// s'il n'a pas encore FlashPay) et paie en un geste après son PIN.
class RequestMoneyScreen extends StatefulWidget {
  final String? initialPhone;
  const RequestMoneyScreen({super.key, this.initialPhone});

  @override
  State<RequestMoneyScreen> createState() => _RequestMoneyScreenState();
}

class _RequestMoneyScreenState extends State<RequestMoneyScreen> {
  final _phone = FpPhoneController();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  _Who _who = _Who.number;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final p = widget.initialPhone;
    if (p != null) {
      _phone.setInternational(p);
    }
  }

  void _setFromRaw(String? raw) {
    if (raw == null) return;
    final p = FpNfc.phoneFromLink(raw);
    if (p == null) {
      setState(() => _error = 'Ce code n\'est pas le QR « Mon code » d\'un utilisateur FlashPay.');
      return;
    }
    setState(() {
      _phone.setInternational(p);
      _error = null;
      _who = _Who.number;
    });
  }

  Future<void> _designate(_Who w) async {
    setState(() => _who = w);
    if (w == _Who.scanner) {
      final raw = await Navigator.push<String>(context, MaterialPageRoute(builder: (_) => QrScanScreen(pickOnly: true, request: true, title: tr('Scanner un ami'))));
      _setFromRaw(raw);
    } else if (w == _Who.nfc) {
      final raw = await FpNfc.readSheet(context, hint: 'Demandez à votre ami d\'ouvrir « Code QR » dans son application, puis placez les deux téléphones dos à dos.');
      _setFromRaw(raw);
    }
  }

  Future<void> _submit() async {
    final amount = int.tryParse(_amount.text.replaceAll(RegExp(r'\D'), ''));
    if (_phone.text.replaceAll(RegExp(r'\D'), '').length < 8) {
      setState(() => _error = 'Indiquez le numéro de votre ami (ou scannez son QR).');
      return;
    }
    if (amount == null || amount < 100) {
      setState(() => _error = 'Montant minimum : 100.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await FeaturesService().createMoneyRequest(phone: _phone.international, amount: amount, note: _note.text.trim());
      if (!mounted) return;
      final payer = r['payer'] is Map ? '${r['payer']['full_name']}' : null;
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: FpPastille(Icons.check_rounded, tone: FpTone.blue, size: 64),
          title: Text(tr('Demande envoyée')),
          content: Text(payer != null
              ? '$payer a reçu votre demande de ${fpMoney(amount, '${r['currency'] ?? 'XAF'}')}. Vous serez notifié dès qu\'il paie.'
              : 'Votre ami n\'a pas encore FlashPay : il a reçu un SMS pour s\'inscrire et payer.'),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('OK')))],
        ),
      );
      if (mounted) Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => MoneyRequestsScreen(initialTab: 1)));
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<SessionProvider>().user?.wallet?.currency ?? 'XAF';
    return FpFlowScaffold(
      title: tr('Demander de l\'argent'),
      bottom: FpButton('Envoyer la demande', red: true, loading: _busy, onPressed: _submit),
      children: [
        FpFlowLabel('Désigner l\'ami'),
        FpMethodRow<_Who>(
          methods: [
            FpPickMethod(_Who.number, Icons.dialpad_rounded, 'Numéro', tone: FpTone.blue),
            FpPickMethod(_Who.scanner, Icons.center_focus_weak_rounded, 'Scanner son QR'),
            FpPickMethod(_Who.nfc, Icons.nfc_rounded, 'NFC', tone: FpTone.blue),
          ],
          selected: _who,
          onSelect: _designate,
        ),
        SizedBox(height: 22),
        FpPhoneLineField(label: tr('Numéro de votre ami'), controller: _phone),
        Text(tr('Montant'), style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
        TextField(
          controller: _amount,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700),
          decoration: InputDecoration(
            suffixText: cur,
            filled: false,
            border: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line)),
            enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line)),
            focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.navy, width: 1.8)),
          ),
        ),
        SizedBox(height: 8),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final v in [500, 1000, 2000, 5000, 10000])
            ActionChip(label: Text(fpMoney(v, cur)), onPressed: () => setState(() => _amount.text = '$v')),
        ]),
        SizedBox(height: 22),
        FpLineField(label: tr('Motif (facultatif)'), controller: _note, hint: 'Ex. part du taxi, remboursement', capitalization: TextCapitalization.sentences),
        if (_error != null) FpErrorBox(_error!),
        SizedBox(height: 8),
        Text(tr('Votre ami paie depuis son wallet FlashPay après confirmation par son code PIN. La demande expire après 7 jours.'),
            style: TextStyle(fontSize: 13, color: FpColors.muted)),
      ],
    );
  }
}

/// Demandes reçues (à payer / refuser) et envoyées (relancer / annuler).
class MoneyRequestsScreen extends StatefulWidget {
  final int initialTab;
  const MoneyRequestsScreen({super.key, this.initialTab = 0});

  @override
  State<MoneyRequestsScreen> createState() => _MoneyRequestsScreenState();
}

class _MoneyRequestsScreenState extends State<MoneyRequestsScreen> {
  final _service = FeaturesService();
  Map<String, dynamic>? _d;
  int? _busy;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.moneyRequests();
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _act(Map r, Future<void> Function(int id) call, String ok) async {
    final id = fpInt(r['id']);
    setState(() => _busy = id);
    try {
      await call(id);
      if (!mounted) return;
      fpSnack(context, ok);
      await _load();
      if (mounted) context.read<SessionProvider>().refreshUser().catchError((_) {});
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _pay(Map r) async {
    final amount = fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}');
    final balance = context.read<SessionProvider>().user?.wallet?.balance ?? 0;
    if (balance < fpInt(r['amount'])) {
      final recharge = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr('Solde insuffisant')),
          content: Text('Il faut $amount sur votre wallet (solde actuel : ${fpMoney(balance, '${r['currency'] ?? 'XAF'}')}).\n'
              'Rechargez depuis votre mobile money, puis revenez payer la demande.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Plus tard'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Recharger'))),
          ],
        ),
      );
      if (recharge == true && mounted) {
        await Navigator.push(context, MaterialPageRoute(builder: (_) => MobileMoneyScreen(mode: MobileMoneyMode.deposit)));
        if (mounted) context.read<SessionProvider>().refreshUser().catchError((_) {});
      }
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Payer $amount ?'),
        content: Text('À ${r['requester']?['full_name'] ?? ''}${(r['note'] ?? '').toString().isNotEmpty ? ' — « ${r['note']} »' : ''}.\nLe montant est débité de votre wallet.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Payer'))),
        ],
      ),
    );
    if (ok == true) await _act(r, _service.payMoneyRequest, 'Demande payée.');
  }

  static const _labels = {'pending': 'En attente', 'paid': 'Payée', 'declined': 'Refusée', 'cancelled': 'Annulée', 'expired': 'Expirée'};
  static String _tone(String s) => switch (s) { 'paid' => 'ok', 'pending' => 'info', 'declined' || 'expired' => 'err', _ => 'warn' };

  Widget _tile(Map r, {required bool incoming}) {
    final status = '${r['status']}';
    final requester = r['requester'];
    final payer = r['payer'];
    final who = incoming
        ? (requester is Map ? requester['full_name'] : null)
        : (payer is Map ? (payer['full_name'] ?? '+${r['payer_phone']}') : '+${r['payer_phone']}');
    final pending = status == 'pending';
    final busy = _busy == fpInt(r['id']);
    return Card(
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 14, 16, 12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            FpPastille(incoming ? Icons.call_received_rounded : Icons.call_made_rounded, tone: incoming ? FpTone.red : FpTone.blue, size: 42),
            SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(incoming ? '$who vous demande' : 'Demandé à $who', style: TextStyle(fontWeight: FontWeight.w600)),
                Text([if ((r['note'] ?? '').toString().isNotEmpty) '« ${r['note']} »', fpDate(r['created_at'])].join(' · '),
                    style: TextStyle(fontSize: 12.5, color: FpColors.muted)),
              ]),
            ),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}'), style: TextStyle(fontWeight: FontWeight.w800)),
              SizedBox(height: 4),
              FpStatusChip(_labels[status] ?? status, tone: _tone(status)),
            ]),
          ]),
          if (pending) ...[
            SizedBox(height: 10),
            Row(mainAxisAlignment: MainAxisAlignment.end, children: incoming
                ? [
                    TextButton(onPressed: busy ? null : () => _act(r, _service.declineMoneyRequest, 'Demande refusée.'), child: Text(tr('Refuser'))),
                    SizedBox(width: 8),
                    FilledButton(
                      style: FilledButton.styleFrom(backgroundColor: FpColors.redSoft),
                      onPressed: busy ? null : () => _pay(r),
                      child: Text(busy ? '…' : 'Payer'),
                    ),
                  ]
                : [
                    TextButton(onPressed: busy ? null : () => _act(r, _service.cancelMoneyRequest, 'Demande annulée.'), child: Text(tr('Annuler'))),
                    SizedBox(width: 8),
                    OutlinedButton(onPressed: busy ? null : () => _act(r, _service.remindMoneyRequest, 'Relance envoyée.'), child: Text(tr('Relancer'))),
                    IconButton(
                      tooltip: tr('Partager la demande'),
                      icon: Icon(Icons.share_rounded, size: 20),
                      onPressed: () => Share.share('Bonjour, je vous ai envoyé une demande de ${fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}')} sur FlashPay'
                          '${(r['note'] ?? '').toString().isNotEmpty ? ' (« ${r['note']} »)' : ''}. Ouvrez l\'application FlashPay pour la régler en un instant.'),
                    ),
                  ]),
          ],
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final incoming = ((_d?['incoming'] ?? []) as List).cast<Map>();
    final outgoing = ((_d?['outgoing'] ?? []) as List).cast<Map>();
    final toPay = incoming.where((r) => r['status'] == 'pending').length;
    return DefaultTabController(
      length: 2,
      initialIndex: widget.initialTab.clamp(0, 1),
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('Demandes d\'argent')),
          bottom: TabBar(tabs: [Tab(text: toPay > 0 ? 'Reçues ($toPay)' : 'Reçues'), Tab(text: tr('Envoyées'))]),
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () async {
            await Navigator.push(context, MaterialPageRoute(builder: (_) => RequestMoneyScreen()));
            _load();
          },
          icon: Icon(Icons.add),
          label: Text(tr('Demander')),
        ),
        body: _d == null
            ? Center(child: CircularProgressIndicator())
            : TabBarView(children: [
                RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: EdgeInsets.fromLTRB(16, 12, 16, 90), children: [
                    if (incoming.isEmpty) FpEmpty('Aucune demande reçue.', icon: Icons.call_received_rounded),
                    ...incoming.map((r) => _tile(r, incoming: true)),
                  ]),
                ),
                RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: EdgeInsets.fromLTRB(16, 12, 16, 90), children: [
                    if (outgoing.isEmpty) FpEmpty('Vous n\'avez encore rien demandé.', icon: Icons.call_made_rounded),
                    ...outgoing.map((r) => _tile(r, incoming: false)),
                  ]),
                ),
              ]),
      ),
    );
  }
}
