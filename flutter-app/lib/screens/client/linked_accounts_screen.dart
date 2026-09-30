import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../widgets/fp_country.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_ui.dart';
import '../../services/linked_sources.dart';
import '../../l10n/l10n.dart';

/// Comptes liés (§3.3.5) : ajout / suppression d'un compte mobile money ou
/// bancaire ; retrait vers un compte bancaire lié (virement différé, §3.3.3).
class LinkedAccountsScreen extends StatefulWidget {
  const LinkedAccountsScreen({super.key});

  @override
  State<LinkedAccountsScreen> createState() => _LinkedAccountsScreenState();
}

class _LinkedAccountsScreenState extends State<LinkedAccountsScreen> {
  final _service = FeaturesService();
  List<Map<String, dynamic>> _accounts = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final a = await _service.linkedAccounts();
      LinkedSources.clear(); // les écrans Envoyer / Payer / Recharger relisent les comptes par défaut
      if (mounted) setState(() { _accounts = a; _loading = false; });
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        fpSnack(context, apiErrorMessage(e), error: true);
      }
    }
  }

  Future<void> _add() async {
    String type = 'mobile_money';
    final phone = FpPhoneController();
    final label = TextEditingController();
    final bank = TextEditingController();
    final holder = TextEditingController();
    final number = TextEditingController();
    final cardHolder = TextEditingController();
    final cardNumber = TextEditingController();
    final cardExpiry = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Lier un compte'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            SizedBox(height: 12),
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'mobile_money', label: Text(tr('Mobile money')), icon: Icon(Icons.phone_android)),
                ButtonSegment(value: 'bank', label: Text(tr('Banque')), icon: Icon(Icons.account_balance)),
                ButtonSegment(value: 'card', label: Text(tr('Carte')), icon: Icon(Icons.credit_card)),
              ],
              selected: {type},
              onSelectionChanged: (s) => setSheet(() => type = s.first),
            ),
            SizedBox(height: 12),
            TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé (facultatif)'))),
            SizedBox(height: 10),
            if (type == 'mobile_money')
              FpPhoneField(label: tr('Numéro MTN, Airtel, Orange…'), controller: phone)
            else if (type == 'bank') ...[
              TextField(controller: bank, decoration: InputDecoration(labelText: tr('Banque'))),
              SizedBox(height: 10),
              TextField(controller: holder, decoration: InputDecoration(labelText: tr('Titulaire du compte'))),
              SizedBox(height: 10),
              TextField(controller: number, decoration: InputDecoration(labelText: tr('RIB / IBAN'))),
            ] else ...[
              TextField(controller: cardHolder, decoration: InputDecoration(labelText: tr('Titulaire de la carte'))),
              SizedBox(height: 10),
              TextField(
                controller: cardNumber,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: tr('Numéro de carte'), prefixIcon: Icon(Icons.credit_card), hintText: '4111 1111 1111 1111'),
              ),
              SizedBox(height: 6),
              Row(children: [FpCardBrands(height: 20), SizedBox(width: 8), Text(tr('Visa et Mastercard acceptées'), style: TextStyle(fontSize: 12, color: Colors.black54))]),
              SizedBox(height: 10),
              TextField(
                controller: cardExpiry,
                keyboardType: TextInputType.datetime,
                decoration: InputDecoration(labelText: tr('Expiration (MM/AA)'), hintText: '09/28'),
              ),
              SizedBox(height: 4),
              Text(tr('Le CVV n\'est jamais demandé ni conservé.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
            ],
            SizedBox(height: 16),
            ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Enregistrer'))),
          ]),
        ),
      ),
    );
    if (ok != true) return;
    try {
      await _service.addLinkedAccount({
        'type': type,
        if (label.text.trim().isNotEmpty) 'label': label.text.trim(),
        if (type == 'mobile_money') 'phone': phone.international,
        if (type == 'bank') ...{'bank_name': bank.text.trim(), 'account_holder': holder.text.trim(), 'account_number': number.text.trim()},
        if (type == 'card') ...{'card_holder': cardHolder.text.trim(), 'card_number': cardNumber.text.trim(), 'card_expiry': cardExpiry.text.trim()},
      });
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _withdraw(Map<String, dynamic> acc) async {
    final amount = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Virement vers ${acc['bank_name']}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          FpAmountField(controller: amount),
          SizedBox(height: 8),
          Text(tr('Traitement sous 24 à 72 h ouvrées.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Continuer'))),
        ],
      ),
    );
    if (ok != true) return;
    try {
      final r = await _service.withdrawToBank(accountId: fpInt(acc['id']), amount: int.tryParse(amount.text.replaceAll(' ', '')) ?? 0);
      if (mounted) fpSnack(context, '${r['message'] ?? 'Virement enregistré.'}');
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Comptes liés'))),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: FpColors.navy,
        foregroundColor: Colors.white,
        onPressed: _add,
        icon: Icon(Icons.add_link),
        label: Text(tr('Lier un compte')),
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: EdgeInsets.fromLTRB(16, 16, 16, 90), children: [
                if (_accounts.isEmpty) FpEmpty('Aucun compte lié. Liez votre mobile money ou votre compte bancaire pour recharger et retirer plus vite.', icon: Icons.link_off),
                ..._accounts.map((a) {
                  final bank = a['type'] == 'bank';
                  final card = a['type'] == 'card';
                  final icon = bank ? Icons.account_balance : (card ? Icons.credit_card : Icons.phone_android);
                  final title = card
                      ? (a['label'] ?? a['card_brand'] ?? 'Carte')
                      : (a['label'] ?? (bank ? a['bank_name'] : a['operator'] ?? 'Mobile money'));
                  final subtitle = bank
                      ? '${a['account_holder']} · ${a['account_number']}'
                      : card
                          ? '${a['account_holder']} · •••• ${a['card_last4']} · exp. ${a['card_expiry']}'
                          : '${a['phone']} · ${a['country']}';
                  final isDefault = a['is_default'] == true || a['is_default'] == 1;
                  final op = '${a['operator'] ?? ''}'.toLowerCase();
                  final opLogo = op.contains('mtn') ? 'mtn' : op.contains('airtel') ? 'airtel' : op.contains('orange') ? 'orange' : op.contains('africell') ? 'africell' : null;
                  final Widget leading = card
                      ? SizedBox(width: 44, child: FpCardBrandLogo('${a['card_brand'] ?? ''}', height: 22))
                      : (!bank && opLogo != null)
                          ? ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.asset('assets/images/operators/$opLogo.png', width: 40, height: 40, fit: BoxFit.contain))
                          : CircleAvatar(backgroundColor: Color(0xFFE0E7FF), child: Icon(icon, color: FpColors.navy));
                  return Card(child: ListTile(
                    leading: leading,
                    title: Text('$title${isDefault ? ' · débité par défaut' : ''}'),
                    subtitle: Text(tr(subtitle)),
                    trailing: PopupMenuButton<String>(
                      onSelected: (v) async {
                        if (v == 'withdraw') _withdraw(a);
                        if (v == 'default') {
                          try {
                            await _service.setDefaultLinkedAccount(fpInt(a['id']));
                            if (mounted) fpSnack(context, 'Ce compte sera débité par défaut pour vos recharges, envois et paiements.');
                          } catch (e) {
                            if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
                          }
                          _load();
                        }
                        if (v == 'delete') {
                          await _service.deleteLinkedAccount(fpInt(a['id']));
                          _load();
                        }
                      },
                      itemBuilder: (_) => [
                        if (!isDefault && !bank) PopupMenuItem(value: 'default', child: Text(tr('Débiter ce compte par défaut'))),
                        if (bank) PopupMenuItem(value: 'withdraw', child: Text(tr('Retirer vers ce compte'))),
                        PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'))),
                      ],
                    ),
                  ));
                }),
              ]),
            ),
    );
  }
}
