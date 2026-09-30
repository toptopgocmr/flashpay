import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/settlement_service.dart';
import 'settlement_account_form.dart';
import '../../l10n/l10n.dart';

/// Règlements du marchand : il choisit vers quel compte régler son solde
/// (mobile money de tout opérateur, compte bancaire, wallet FlashPay, retrait
/// cash chez un agent), gère ses comptes et programme un règlement automatique.
class MerchantSettlementScreen extends StatefulWidget {
  const MerchantSettlementScreen({super.key});

  @override
  State<MerchantSettlementScreen> createState() => _MerchantSettlementScreenState();
}

IconData settlementIcon(String? type) => switch (type) {
      'bank' => Icons.account_balance_rounded,
      'wallet' => Icons.account_balance_wallet_rounded,
      'cash_pickup' => Icons.payments_rounded,
      _ => Icons.phone_android_rounded,
    };

class _MerchantSettlementScreenState extends State<MerchantSettlementScreen> {
  final _service = SettlementService();
  Map<String, dynamic>? _d;
  String? _error;

  List<Map<String, dynamic>> get _accounts => ((_d?['accounts'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  List<Map<String, dynamic>> get _history => ((_d?['history'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  String get _cur => (_d?['currency'] ?? 'XAF') as String;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.overview();
      if (mounted) setState(() { _d = d; _error = null; });
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    }
  }

  Future<void> _addAccount() async {
    final ok = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => SettlementAccountForm(country: _d?['country'] as String?)));
    if (ok == true) _load();
  }

  Future<void> _settle([Map<String, dynamic>? preselect]) async {
    if (_accounts.isEmpty) return _addAccount();
    final r = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _SettleSheet(accounts: _accounts, balance: (_d?['balance'] ?? 0) as int, currency: _cur, preselect: preselect, service: _service),
    );
    if (r == null || !mounted) return;
    await _load();
    if (!mounted) return;
    await showDialog(context: context, builder: (ctx) => _ResultDialog(result: r));
  }

  Future<void> _accountMenu(Map<String, dynamic> a) async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: Icon(Icons.send_rounded), title: Text(tr('Régler vers ce compte')), onTap: () => Navigator.pop(ctx, 'settle')),
          if (a['is_default'] != true) ListTile(leading: Icon(Icons.star_outline), title: Text(tr('Définir par défaut')), onTap: () => Navigator.pop(ctx, 'default')),
          ListTile(leading: Icon(Icons.delete_outline, color: FpColors.danger), title: Text(tr('Supprimer'), style: TextStyle(color: FpColors.danger)), onTap: () => Navigator.pop(ctx, 'delete')),
        ]),
      ),
    );
    try {
      if (choice == 'settle') return _settle(a);
      if (choice == 'default') await _service.setDefault(a['id'] as int);
      if (choice == 'delete') await _service.delete(a['id'] as int);
      if (choice != null) _load();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    }
  }

  Future<void> _editAuto() async {
    final r = await showModalBottomSheet<(String, int)>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _AutoSheet(mode: (_d?['auto_settlement'] ?? 'none') as String, min: (_d?['auto_settlement_min'] ?? 0) as int, currency: _cur),
    );
    if (r == null) return;
    try {
      await _service.auto(mode: r.$1, min: r.$2);
      _load();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final auto = (d?['auto_settlement'] ?? 'none') as String;
    final def = _accounts.where((a) => a['is_default'] == true).firstOrNull;

    return Scaffold(
      appBar: AppBar(title: Text(tr('Règlements'))),
      body: d == null
          ? Center(child: _error != null ? Padding(padding: EdgeInsets.all(24), child: Text(_error!)) : CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: EdgeInsets.all(20), children: [
                Container(
                  padding: EdgeInsets.all(20),
                  decoration: BoxDecoration(gradient: LinearGradient(colors: [FpColors.navy, Color(0xFF1BA8F0)]), borderRadius: BorderRadius.circular(20)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('Solde disponible au règlement'), style: TextStyle(color: Colors.white70, fontSize: 13)),
                    SizedBox(height: 4),
                    Text(fpMoney((d['balance'] ?? 0) as int, _cur), style: TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w900)),
                    SizedBox(height: 14),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(backgroundColor: FpColors.navy, foregroundColor: Colors.white),
                        onPressed: () => _settle(),
                        icon: Icon(Icons.send_rounded),
                        label: Text(tr('Régler maintenant')),
                      ),
                    ),
                  ]),
                ),
                SizedBox(height: 24),
                Row(children: [
                  Expanded(child: Text(tr('Mes comptes de règlement'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
                  TextButton.icon(onPressed: _addAccount, icon: Icon(Icons.add), label: Text(tr('Ajouter'))),
                ]),
                ..._accounts.map((a) => Card(
                      margin: EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        onTap: () => _accountMenu(a),
                        leading: CircleAvatar(backgroundColor: FpColors.orange.withOpacity(.2), child: Icon(settlementIcon(a['type'] as String?), color: FpColors.navy)),
                        title: Text((a['label'] as String?)?.isNotEmpty == true ? a['label'] as String : a['type_label'] as String, style: TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(a['summary'] as String? ?? ''),
                        trailing: a['is_default'] == true
                            ? Chip(label: Text(tr('Par défaut'), style: TextStyle(fontSize: 11)), visualDensity: VisualDensity.compact)
                            : Icon(Icons.more_vert),
                      ),
                    )),
                if (_accounts.isEmpty) Padding(padding: EdgeInsets.all(12), child: Text(tr('Ajoutez un compte : mobile money, compte bancaire, wallet FlashPay ou retrait cash.'))),
                SizedBox(height: 16),
                Card(
                  child: ListTile(
                    onTap: _editAuto,
                    leading: Icon(Icons.schedule_rounded, color: FpColors.navy),
                    title: Text(tr('Règlement automatique'), style: TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(auto == 'none'
                        ? 'Désactivé — vous réglez vous-même'
                        : '${auto == 'daily' ? 'Chaque soir' : 'Chaque semaine'} vers ${def?['summary'] ?? 'le compte par défaut'} · garder ${fpMoney((d['auto_settlement_min'] ?? 0) as int, _cur)}'),
                    trailing: Icon(Icons.chevron_right),
                  ),
                ),
                SizedBox(height: 24),
                Text(tr('Historique des règlements'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                SizedBox(height: 8),
                if (_history.isEmpty) Padding(padding: EdgeInsets.all(12), child: Text(tr('Aucun règlement pour le moment.'))),
                ..._history.map((h) {
                  final st = h['status'] as String?;
                  final (String label, Color color) = switch (st) {
                    'successful' => ('Réglé', FpColors.success),
                    'failed' => ('Échoué', FpColors.danger),
                    'reversed' => ('Remboursé', Colors.orange),
                    _ => (h['stage'] == 'awaiting_bank' ? 'Virement en cours' : (h['stage'] == 'awaiting_pickup' ? 'Code à retirer' : 'En cours'), FpColors.navy),
                  };
                  final date = DateTime.tryParse('${h['created_at']}')?.toLocal();
                  return ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(settlementIcon(h['method'] as String?), color: FpColors.navy),
                    title: Text('${h['label']}${h['auto'] == true ? ' · auto' : ''}'),
                    subtitle: Text([if (h['to'] != null) '${h['to']}', if (date != null) '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}'].join(' · ')),
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text('−${fpMoney((h['amount'] ?? 0) as int, (h['currency'] ?? _cur) as String)}', style: TextStyle(fontWeight: FontWeight.w800)),
                      Text(tr(label), style: TextStyle(fontSize: 11.5, color: color, fontWeight: FontWeight.w600)),
                    ]),
                  );
                }),
              ]),
            ),
    );
  }
}

class _SettleSheet extends StatefulWidget {
  final List<Map<String, dynamic>> accounts;
  final int balance;
  final String currency;
  final Map<String, dynamic>? preselect;
  final SettlementService service;
  const _SettleSheet({required this.accounts, required this.balance, required this.currency, this.preselect, required this.service});

  @override
  State<_SettleSheet> createState() => _SettleSheetState();
}

class _SettleSheetState extends State<_SettleSheet> {
  late int _accountId = (widget.preselect ?? widget.accounts.firstWhere((a) => a['is_default'] == true, orElse: () => widget.accounts.first))['id'] as int;
  final _amountCtrl = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _go() async {
    final amount = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    if (amount == null || amount < 100) {
      setState(() => _error = 'Montant minimum : 100');
      return;
    }
    setState(() { _busy = true; _error = null; });
    try {
      final r = await widget.service.settle(accountId: _accountId, amount: amount);
      if (mounted) Navigator.pop(context, r);
    } catch (e) {
      setState(() { _error = apiErrorMessage(e); _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Régler vers…'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            SizedBox(height: 8),
            ...widget.accounts.map((a) => RadioListTile<int>(
                  contentPadding: EdgeInsets.zero,
                  value: a['id'] as int,
                  groupValue: _accountId,
                  onChanged: (v) => setState(() => _accountId = v!),
                  secondary: Icon(settlementIcon(a['type'] as String?)),
                  title: Text(a['type_label'] as String? ?? ''),
                  subtitle: Text(a['summary'] as String? ?? ''),
                )),
            SizedBox(height: 8),
            TextField(
              controller: _amountCtrl,
              keyboardType: TextInputType.number,
              style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
              decoration: InputDecoration(
                labelText: tr('Montant'),
                suffixText: widget.currency,
                helperText: 'Disponible : ${fpMoney(widget.balance, widget.currency)}',
                suffixIcon: TextButton(onPressed: () => _amountCtrl.text = '${widget.balance}', child: Text(tr('Tout'))),
              ),
            ),
            if (_error != null) Padding(padding: EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
            SizedBox(height: 16),
            ElevatedButton(onPressed: _busy ? null : _go, child: Text(_busy ? 'Traitement…' : 'Confirmer le règlement')),
            SizedBox(height: 6),
            Text(tr('Les frais éventuels (grille tarifaire) s\'ajoutent au montant.'), textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: Colors.black45)),
          ]),
        ),
      );
}

class _AutoSheet extends StatefulWidget {
  final String mode;
  final int min;
  final String currency;
  const _AutoSheet({required this.mode, required this.min, required this.currency});

  @override
  State<_AutoSheet> createState() => _AutoSheetState();
}

class _AutoSheetState extends State<_AutoSheet> {
  late String _mode = widget.mode;
  late final _minCtrl = TextEditingController(text: '${widget.min}');

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Text(tr('Règlement automatique'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          SizedBox(height: 4),
          Text(tr('Chaque soir à 20 h, le solde au-delà du montant conservé est réglé vers votre compte par défaut.'), style: TextStyle(color: Colors.black54, fontSize: 13)),
          SizedBox(height: 12),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'none', label: Text(tr('Désactivé'))),
              ButtonSegment(value: 'daily', label: Text(tr('Quotidien'))),
              ButtonSegment(value: 'weekly', label: Text(tr('Hebdo'))),
            ],
            selected: {_mode},
            onSelectionChanged: (s) => setState(() => _mode = s.first),
          ),
          SizedBox(height: 14),
          TextField(
            controller: _minCtrl,
            enabled: _mode != 'none',
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            decoration: InputDecoration(labelText: tr('Montant à garder sur le wallet'), suffixText: widget.currency, helperText: tr('Pour rendre la monnaie / payer vos fournisseurs')),
          ),
          SizedBox(height: 16),
          ElevatedButton(onPressed: () => Navigator.pop(context, (_mode, int.tryParse(_minCtrl.text) ?? 0)), child: Text(tr('Enregistrer'))),
        ]),
      );
}

class _ResultDialog extends StatelessWidget {
  final Map<String, dynamic> result;
  const _ResultDialog({required this.result});

  @override
  Widget build(BuildContext context) {
    final status = result['status'] as String?;
    final code = result['code'] as String?;
    final ok = status == 'successful' || status == 'processing';
    final type = (result['account'] as Map?)?['type'];
    final msg = switch (type) {
      'bank' => 'Virement enregistré : l\'équipe FlashPay l\'exécute sous 24 h ouvrées. En cas de rejet, le montant est remboursé.',
      'cash_pickup' => 'Présentez ce code à un agent FlashPay avec votre pièce d\'identité.',
      _ => (result['message'] ?? '') as String,
    };
    return AlertDialog(
      icon: Icon(ok ? Icons.check_circle_rounded : Icons.cancel_rounded, color: ok ? FpColors.success : FpColors.danger, size: 48),
      title: Text(ok ? (status == 'successful' ? 'Règlement effectué' : 'Règlement en cours') : 'Règlement refusé'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text(fpMoney((result['amount'] ?? 0) as int, (result['currency'] ?? 'XAF') as String), style: TextStyle(fontSize: 24, fontWeight: FontWeight.w900)),
        SizedBox(height: 8),
        if (code != null) ...[
          Text(tr('Code de retrait'), style: TextStyle(color: Colors.black54)),
          SelectableText(code, style: TextStyle(fontSize: 26, fontWeight: FontWeight.w900, letterSpacing: 2)),
          SizedBox(height: 8),
        ],
        Text(msg, textAlign: TextAlign.center, style: TextStyle(fontSize: 13)),
        SizedBox(height: 6),
        Text('Réf. ${result['reference'] ?? ''}', style: TextStyle(fontSize: 11, color: Colors.black45)),
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('OK')))],
    );
  }
}
