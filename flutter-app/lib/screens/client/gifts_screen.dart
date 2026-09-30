import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_ui.dart';
import '../../widgets/fp_phone_chips.dart';
import '../../models/corridor.dart';
import '../../models/quote.dart';
import '../../services/checkout.dart';
import '../../services/linked_sources.dart';
import '../shared/transaction_status_screen.dart';
import 'package:provider/provider.dart';
import '../../providers/session_provider.dart';
import '../../l10n/l10n.dart';

/// Enveloppes rouges / cadeaux d'argent (§3.5.1) : montant fixe à des
/// contacts désignés, ou cagnotte partagée au hasard entre les premiers
/// qui l'ouvrent ; message et occasion ; non réclamé = recrédité.
class GiftsScreen extends StatefulWidget {
  final String? claimCode;
  const GiftsScreen({super.key, this.claimCode});

  @override
  State<GiftsScreen> createState() => _GiftsScreenState();
}

class _GiftsScreenState extends State<GiftsScreen> {
  final _service = FeaturesService();
  Map<String, dynamic>? _d;

  static const _occasions = {'anniversaire': '🎂 Anniversaire', 'fete': '🎉 Fête', 'felicitations': '👏 Félicitations', 'mariage': '💍 Mariage', 'naissance': '👶 Naissance', 'autre': '🎁 Autre'};

  @override
  void initState() {
    super.initState();
    _load();
    if (widget.claimCode != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _claim(widget.claimCode!));
    }
  }

  Future<void> _load() async {
    try {
      final d = await _service.gifts();
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _claim([String? preset]) async {
    String? code = preset;
    if (code == null) {
      final c = TextEditingController();
      code = await showDialog<String>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr('Ouvrir un cadeau')),
          content: TextField(controller: c, autofocus: true, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: tr('Code du cadeau'))),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('Ouvrir'))),
          ],
        ),
      );
    }
    if (code == null || code.isEmpty) return;
    try {
      final r = await _service.claimGift(code);
      if (!mounted) return;
      await showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr('🧧 Cadeau reçu !')),
          content: Text('${r['sender']} vous offre ${fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}')}${r['message'] != null ? '\n\n« ${r['message']} »' : ''}'),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Merci !')))],
        ),
      );
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _send() async {
    String mode = 'fixed';
    String source = 'wallet'; // wallet | mobile | card
    final srcPhone = TextEditingController(text: await LinkedSources.defaultMobilePhone() ?? '');
    FpCountry? srcCountry;
    if (!mounted) return;
    String occasion = 'anniversaire';
    final amount = TextEditingController();
    List<String> recipients = [];
    final shares = TextEditingController(text: '5');
    final message = TextEditingController();
    final wallet = context.read<SessionProvider>().user?.wallet;
    final balance = wallet?.balance ?? 0;
    final currency = wallet?.currency ?? 'XAF';
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(tr('Offrir un cadeau 🧧'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              SizedBox(height: 4),
              SizedBox(height: 10),
              Text(tr('Payer avec'), style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              SizedBox(height: 6),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'wallet', label: Text(tr('Wallet')), icon: Icon(Icons.account_balance_wallet_rounded)),
                  ButtonSegment(value: 'mobile', label: Text(tr('Mobile money')), icon: Icon(Icons.phone_android_rounded)),
                  ButtonSegment(value: 'card', label: Text(tr('Carte')), icon: Icon(Icons.credit_card_rounded)),
                ],
                selected: {source},
                showSelectedIcon: false,
                onSelectionChanged: (s) => setSheet(() => source = s.first),
              ),
              SizedBox(height: 6),
              if (source == 'wallet')
                Text('${tr('Solde disponible')} : ${fpMoney(balance, currency)}', style: TextStyle(fontSize: 12.5, color: Colors.black54))
              else if (source == 'mobile') ...[
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  FpDialPicker(value: srcCountry, onChanged: (c) => setSheet(() => srcCountry = c)),
                  SizedBox(width: 8),
                  Expanded(child: TextField(controller: srcPhone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Numéro mobile money à débiter'), hintText: '06 123 45 67'))),
                ]),
                SizedBox(height: 4),
                Text(tr('Vous validerez le paiement sur ce téléphone ; le cadeau part dès la confirmation.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
              ] else
                Text(tr('Une page de paiement sécurisée (3-D Secure) va s\'ouvrir pour régler par carte.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
              SizedBox(height: 12),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'fixed', label: Text(tr('À des contacts')), icon: Icon(Icons.people)),
                  ButtonSegment(value: 'random', label: Text(tr('Cagnotte surprise')), icon: Icon(Icons.casino)),
                ],
                selected: {mode},
                onSelectionChanged: (s) => setSheet(() => mode = s.first),
              ),
              SizedBox(height: 6),
              Text(
                mode == 'fixed'
                    ? 'Chaque contact reçoit le même montant et est notifié.'
                    : 'Le montant est partagé au hasard entre les premiers qui ouvrent le lien.',
                style: TextStyle(fontSize: 12, color: Colors.black54),
              ),
              SizedBox(height: 12),
              Text(tr('Occasion'), style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
              SizedBox(height: 6),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final e in _occasions.entries)
                  ChoiceChip(label: Text(tr(e.value)), selected: occasion == e.key, onSelected: (_) => setSheet(() => occasion = e.key)),
              ]),
              SizedBox(height: 12),
              FpAmountField(controller: amount, label: mode == 'fixed' ? 'Montant par personne' : 'Montant total de la cagnotte'),
              SizedBox(height: 6),
              Wrap(spacing: 6, children: [
                for (final v in [500, 1000, 2000, 5000])
                  ActionChip(label: Text(fpMoney(v, currency)), onPressed: () => amount.text = '$v'),
              ]),
              SizedBox(height: 12),
              if (mode == 'fixed')
                FpPhoneChips(label: tr('Numéro du destinataire'), onChanged: (l) => setSheet(() => recipients = l))
              else
                TextField(controller: shares, keyboardType: TextInputType.number, onChanged: (_) => setSheet(() {}), decoration: InputDecoration(labelText: tr('Nombre de parts'), prefixIcon: Icon(Icons.pie_chart_outline))),
              SizedBox(height: 10),
              TextField(controller: message, maxLength: 120, decoration: InputDecoration(labelText: tr('Petit mot (facultatif)'), prefixIcon: Icon(Icons.edit_note))),
              ValueListenableBuilder<TextEditingValue>(
                valueListenable: amount,
                builder: (_, v, __) {
                  final a = int.tryParse(v.text.replaceAll(RegExp(r'\D'), '')) ?? 0;
                  final cost = mode == 'fixed' ? a * recipients.length : a;
                  final short = source == 'wallet' && cost > balance;
                  return Container(
                    margin: EdgeInsets.only(top: 4),
                    padding: EdgeInsets.all(12),
                    decoration: BoxDecoration(color: short ? Color(0xFFFEF2F2) : FpColors.soft, borderRadius: BorderRadius.circular(12)),
                    child: Row(children: [
                      Expanded(
                        child: Text(
                          short
                              ? 'Total ${fpMoney(cost, currency)} : solde insuffisant. Rechargez votre wallet.'
                              : 'Total débité : ${fpMoney(cost, currency)}${mode == 'fixed' && recipients.length > 1 ? ' (${recipients.length} × ${fpMoney(a, currency)})' : ''}',
                          style: TextStyle(fontWeight: FontWeight.w600, color: short ? FpColors.danger : FpColors.navy),
                        ),
                      ),
                    ]),
                  );
                },
              ),
              SizedBox(height: 6),
              Text(tr('Non récupéré sous 24 h, le montant vous est recrédité.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
              SizedBox(height: 14),
              ElevatedButton(
                onPressed: () {
                  if (mode == 'fixed' && recipients.isEmpty) {
                    fpSnack(ctx, tr('Ajoutez au moins un destinataire.'), error: true);
                    return;
                  }
                  if (source == 'mobile' && fpInternational(srcCountry, srcPhone.text) == null) {
                    fpSnack(ctx, tr('Numéro mobile money invalide.'), error: true);
                    return;
                  }
                  Navigator.pop(ctx, true);
                },
                child: Text(tr('Envoyer le cadeau 🧧')),
              ),
            ]),
          ),
        ),
      ),
    );
    if (ok != true) return;
    try {
      final r = await _service.sendGift({
        'mode': mode,
        'amount': int.tryParse(amount.text.replaceAll(' ', '')) ?? 0,
        if (mode == 'fixed') 'recipients': recipients,
        if (mode == 'random') 'shares': int.tryParse(shares.text) ?? 1,
        'occasion': occasion,
        if (message.text.trim().isNotEmpty) 'message': message.text.trim(),
        'source': source,
        if (source == 'mobile') 'source_phone': fpInternational(srcCountry, srcPhone.text),
      });
      if (!mounted) return;
      // Payé par mobile money / carte : suivi du paiement, le cadeau part à la confirmation.
      if (r['funding'] == true) {
        if (r['checkout_url'] != null) await openCheckout('${r['checkout_url']}');
        if (!mounted) return;
        await Navigator.push(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: FpPaymentStatus.fromJson(r), title: tr('Cadeau'))));
        _load();
        return;
      }
      if (mode == 'random') {
        await Share.share('🧧 Je vous offre une enveloppe FlashPay ! Code : ${r['share_code']}\n${r['share_link']}');
      } else {
        await showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text(tr('🧧 Cadeau envoyé !')),
            content: Text('${recipients.length} destinataire(s) notifié(s). Ils ont 24 h pour l\'ouvrir ; sinon le montant vous est recrédité.'
                '${r['code'] != null ? '\n\nCode du cadeau : ${r['code']}' : ''}'),
            actions: [
              if (r['share_link'] != null || r['code'] != null)
                TextButton(
                  onPressed: () => Share.share('🧧 Je vous ai envoyé un cadeau FlashPay ! ${r['share_link'] ?? 'Code : ${r['code']}'}'),
                  child: Text(tr('Partager')),
                ),
              FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('OK'))),
            ],
          ),
        );
      }
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sent = ((_d?['sent'] ?? []) as List).cast<Map>();
    final received = ((_d?['received'] ?? []) as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Cadeaux d\'argent')), actions: [IconButton(tooltip: tr('Ouvrir avec un code'), icon: Icon(Icons.qr_code), onPressed: () => _claim())]),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: Color(0xFFE11D48),
        foregroundColor: Colors.white,
        onPressed: _send,
        icon: Icon(Icons.redeem),
        label: Text(tr('Offrir')),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: EdgeInsets.fromLTRB(16, 8, 16, 90), children: [
          FpSectionTitle('Reçus'),
          if (received.isEmpty) FpEmpty('Aucun cadeau reçu.', icon: Icons.redeem),
          ...received.map((c) {
            final env = (c['envelope'] ?? {}) as Map;
            final claimed = c['claimed_at'] != null;
            return Card(child: ListTile(
              leading: CircleAvatar(backgroundColor: Color(0xFFFFE4E6), child: Text('🧧')),
              title: Text('De ${env['sender']?['full_name'] ?? ''}'),
              subtitle: Text('${env['message'] ?? _occasions[env['occasion']] ?? ''}\n${claimed ? 'Récupéré le ${fpDate(c['claimed_at'])}' : 'Expire le ${fpDate(env['expires_at'])}'}'),
              isThreeLine: true,
              trailing: claimed
                  ? Text(fpMoney(fpInt(c['amount']), '${env['currency'] ?? 'XAF'}'), style: TextStyle(fontWeight: FontWeight.w800, color: FpColors.success))
                  : (env['status'] == 'active' ? FilledButton(onPressed: () => _claim('${env['code']}'), child: Text(tr('Ouvrir'))) : FpStatusChip('Expiré', tone: 'err')),
            ));
          }),
          FpSectionTitle('Envoyés'),
          if (sent.isEmpty) FpEmpty('Aucun cadeau envoyé.'),
          ...sent.map((g) => Card(child: ListTile(
                leading: Text(g['mode'] == 'random' ? '🎲' : '🎁', style: TextStyle(fontSize: 26)),
                title: Text(fpMoney(fpInt(g['total_amount']), '${g['currency'] ?? 'XAF'}')),
                subtitle: Text('${g['claimed_count'] ?? 0}/${g['shares']} récupéré(s) · code ${g['code']}'),
                trailing: FpStatusChip(switch ('${g['status']}') { 'completed' => 'Terminé', 'refunded' => 'Recrédité', 'expired' => 'Expiré', _ => 'En cours' },
                    tone: g['status'] == 'active' ? 'warn' : 'ok'),
                onTap: g['status'] == 'active' ? () => Share.share('🧧 Enveloppe FlashPay : code ${g['code']}') : null,
              ))),
        ]),
      ),
    );
  }
}
