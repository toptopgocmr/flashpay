import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/corridor.dart';
import '../../models/quote.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/checkout.dart';
import '../../models/payment_method.dart';
import '../../widgets/phone_input.dart';
import '../../services/linked_sources.dart';
import 'linked_accounts_screen.dart';
import '../../widgets/quote_summary.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

/// Envoyer de l'argent vers n'importe quel numéro de la sous-région
/// (MTN, Airtel, Orange, Moov… en CEMAC, UEMOA, RDC, Guinée) ou vers un
/// utilisateur FlashPay — depuis le wallet ou directement depuis son mobile money.
class SendMoneyScreen extends StatefulWidget {
  final String? initialPhone;
  /// Type choisi dans « Envoyer de l'argent » : source (wallet | mobile | card)
  /// et réception (auto | mobile | bank).
  final String initialSource;
  final String initialDeliverTo;
  const SendMoneyScreen({super.key, this.initialPhone, this.initialSource = 'wallet', this.initialDeliverTo = 'auto'});

  @override
  State<SendMoneyScreen> createState() => _SendMoneyScreenState();
}

class _SendMoneyScreenState extends State<SendMoneyScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();

  List<FpCountry>? _countries;
  FpPhoneValue? _dest;
  FpPhoneValue? _srcPhone;
  late String _source = widget.initialSource; // wallet | mobile | card
  late String _deliverTo = widget.initialDeliverTo; // auto (wallet FlashPay si inscrit) | mobile | bank
  Map<String, FpMethod> _sendMethods = {};
  String? _linkedPhone; // mobile money lié par défaut (compte débité)
  Map<String, dynamic>? _linkedCard; // carte liée par défaut
  bool _linkedLoaded = false;
  FpQuote? _quote;
  Map<String, dynamic>? _lookup;
  String? _error;
  bool _loadingQuote = false;
  bool _sending = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _service.countries().then((c) => setState(() => _countries = c)).catchError((e) {
      setState(() => _error = apiErrorMessage(e));
    });
    _loadLinked();
    // Sources / destinations ouvertes (carte, banque…) pour le pays du client
    _service.methods(operation: 'send').then((m) {
      if (mounted) setState(() => _sendMethods = {for (final x in m.methods) x.key: x});
    }).catchError((_) {});
  }

  Future<void> _loadLinked({bool refresh = false}) async {
    if (refresh) LinkedSources.clear();
    final phone = await LinkedSources.defaultMobilePhone();
    final card = await LinkedSources.defaultCard();
    if (mounted) setState(() { _linkedPhone = phone; _linkedCard = card; _linkedLoaded = true; });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  int? get _amount => int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));

  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(Duration(milliseconds: 450), _refresh);
  }

  int _quoteSeq = 0;

  Future<void> _refresh() async {
    // Valeurs figées au début : un champ peut changer pendant les appels réseau.
    final seq = ++_quoteSeq;
    final amount = _amount;
    final dest = _dest;
    final src = _srcPhone;
    final source = _source;
    final deliverTo = _deliverTo;
    if (dest == null || !dest.isValid) {
      setState(() { _quote = null; _lookup = null; _error = null; });
      return;
    }
    try {
      final l = await _service.lookup(dest.international);
      if (mounted && seq == _quoteSeq) setState(() => _lookup = l);
    } catch (_) {}
    if (seq != _quoteSeq) return;
    if (amount == null || amount < 10) {
      setState(() { _quote = null; _error = null; });
      return;
    }
    if (source == 'mobile' && (src == null || !src.isValid)) return;

    setState(() { _loadingQuote = true; _error = null; });
    try {
      final q = await _service.quote(
        operation: 'transfer',
        amount: amount,
        source: source,
        sourcePhone: source == 'mobile' ? src!.international : null,
        destinationPhone: dest.international,
        deliverTo: deliverTo,
      );
      if (mounted && seq == _quoteSeq) setState(() => _quote = q);
    } catch (e) {
      if (mounted && seq == _quoteSeq) setState(() { _quote = null; _error = apiErrorMessage(e); });
    } finally {
      if (mounted && seq == _quoteSeq) setState(() => _loadingQuote = false);
    }
  }

  Future<void> _send() async {
    final q = _quote;
    final dest = _dest;
    final src = _srcPhone;
    if (q == null || !q.available || dest == null) return;

    final ok = await showModalBottomSheet<bool>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) => _ConfirmSheet(quote: q),
    );
    if (ok != true || !mounted) return;

    setState(() { _sending = true; _error = null; });
    try {
      final s = await _service.transfer(
        source: _source,
        sourcePhone: _source == 'mobile' ? src?.international : null,
        destinationPhone: dest.international,
        amount: q.amount,
        beneficiaryName: _nameCtrl.text.trim(),
        note: _noteCtrl.text.trim(),
        deliverTo: _deliverTo,
      );
      if (s.awaitingCard) await openCheckout(s.checkoutUrl);
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: tr('Envoi'))));
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final countries = _countries;
    final isFlashPayUser = _lookup?['flashpay_user'] != null;

    return Scaffold(
      appBar: AppBar(title: Text(tr("Envoyer de l'argent"))),
      body: countries == null
          ? Center(child: _error != null ? Padding(padding: EdgeInsets.all(24), child: Text(_error!)) : CircularProgressIndicator())
          : ListView(
              padding: EdgeInsets.all(20),
              children: [
                FpPhoneInput(
                  countries: countries,
                  label: tr('Destinataire'),
                  initialPhone: _dest?.international ?? widget.initialPhone,
                  countryFilter: (c) => c.payout,
                  operatorsLabel: 'Le bénéficiaire reçoit sur',
                  onChanged: (v) { _dest = v; _schedule(); },
                ),
                if (isFlashPayUser)
                  Padding(
                    padding: EdgeInsets.only(top: 8),
                    child: Row(children: [
                      Icon(Icons.verified, color: FpColors.success, size: 18),
                      SizedBox(width: 6),
                      Text('Compte FlashPay : ${_lookup!['flashpay_user']['name']}', style: TextStyle(fontWeight: FontWeight.w600)),
                    ]),
                  )
                else if (_dest?.isValid == true) ...[
                  SizedBox(height: 12),
                  TextField(controller: _nameCtrl, decoration: InputDecoration(labelText: tr('Nom du bénéficiaire (facultatif)'), prefixIcon: Icon(Icons.person_outline))),
                ],
                SizedBox(height: 20),
                TextField(
                  controller: _amountCtrl,
                  keyboardType: TextInputType.number,
                  style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
                  decoration: InputDecoration(
                    labelText: tr('Montant'),
                    suffixText: user?.wallet?.currency ?? 'XAF',
                  ),
                  onChanged: (_) => _schedule(),
                ),
                SizedBox(height: 20),
                Text(tr('Payer avec'), style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                SizedBox(height: 8),
                _OptionGrid(
                  selected: _source,
                  onSelect: (v) { setState(() => _source = v); _schedule(); },
                  options: [
                    _Opt('wallet', Icons.account_balance_wallet_rounded, 'Wallet', fpMoney(user?.wallet?.balance ?? 0, user?.wallet?.currency ?? 'XAF')),
                    _Opt('mobile', Icons.phone_android_rounded, 'Mobile money', 'MTN, Airtel…'),
                    _Opt('card', Icons.credit_card_rounded, 'Carte', _linkedCard != null ? '•••• ${_linkedCard!['card_last4']}' : 'Visa / Mastercard',
                        enabled: _sendMethods['card']?.available ?? false, logos: true),
                  ],
                ),
                if (_source == 'card')
                  FpLinkedCardBanner(
                    card: _linkedCard,
                    onLink: () async {
                      await Navigator.push(context, MaterialPageRoute(builder: (_) => LinkedAccountsScreen()));
                      _loadLinked(refresh: true);
                    },
                  ),
                if (_source == 'mobile' && _linkedLoaded) ...[
                  SizedBox(height: 14),
                  FpPhoneInput(
                    key: ValueKey('src-${_linkedPhone ?? ''}'),
                    countries: countries,
                    label: _linkedPhone != null ? 'Compte mobile money lié à débiter (validation sur ce téléphone)' : 'Numéro à débiter (vous validerez sur ce téléphone)',
                    initialPhone: _linkedPhone ?? (user?.phone != null ? '+${user!.phone.replaceAll('+', '')}' : null),
                    countryFilter: (c) => c.collect,
                    operatorsLabel: 'Payer avec',
                    onChanged: (v) { _srcPhone = v; _schedule(); },
                  ),
                ],
                SizedBox(height: 20),
                Text(tr('Le bénéficiaire reçoit sur'), style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                SizedBox(height: 8),
                _OptionGrid(
                  selected: _deliverTo,
                  onSelect: (v) { setState(() => _deliverTo = v); _schedule(); },
                  options: [
                    _Opt('auto', Icons.bolt_rounded, 'FlashPay', 'Wallet si inscrit'),
                    _Opt('mobile', Icons.phone_android_rounded, 'Mobile money', 'Tout opérateur'),
                    _Opt('bank', Icons.account_balance_rounded, 'Banque', (_sendMethods['bank']?.available ?? false) ? 'Compte bancaire' : 'Bientôt', enabled: _sendMethods['bank']?.available ?? false),
                  ],
                ),
                SizedBox(height: 12),
                TextField(controller: _noteCtrl, decoration: InputDecoration(labelText: tr('Motif (facultatif)'), prefixIcon: Icon(Icons.notes))),
                SizedBox(height: 20),
                if (_loadingQuote) LinearProgressIndicator(minHeight: 2),
                if (_quote != null) FpQuoteSummary(quote: _quote!),
                if (_error != null) Padding(padding: EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
                SizedBox(height: 16),
                ElevatedButton(
                  onPressed: (_quote?.available ?? false) && !_sending ? _send : null,
                  child: _sending
                      ? SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(_quote == null ? 'Envoyer' : 'Envoyer ${fpMoney(_quote!.total, _quote!.currency)}'),
                ),
              ],
            ),
    );
  }
}

class _Opt {
  final String value;
  final IconData icon;
  final String title;
  final String subtitle;
  final bool enabled;
  final bool logos; // affiche les logos Visa / Mastercard
  _Opt(this.value, this.icon, this.title, this.subtitle, {this.enabled = true, this.logos = false});
}

/// Sélecteur en tuiles (source / destination), plus lisible qu'un segmented button.
class _OptionGrid extends StatelessWidget {
  final List<_Opt> options;
  final String selected;
  final ValueChanged<String> onSelect;
  const _OptionGrid({required this.options, required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        for (final o in options)
          Expanded(
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: 4),
              child: Opacity(
                opacity: o.enabled ? 1 : .45,
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: o.enabled ? () => onSelect(o.value) : null,
                  child: AnimatedContainer(
                    duration: Duration(milliseconds: 150),
                    padding: EdgeInsets.symmetric(vertical: 12, horizontal: 8),
                    decoration: BoxDecoration(
                      color: selected == o.value ? FpColors.navy : Colors.white,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: selected == o.value ? FpColors.navy : Colors.black12),
                    ),
                    child: Column(children: [
                      if (o.logos)
                        Container(
                          height: 24,
                          padding: EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(6)),
                          child: FpCardBrands(height: 16),
                        )
                      else
                        Icon(o.icon, color: selected == o.value ? FpColors.orange : FpColors.navy),
                      SizedBox(height: 6),
                      Text(tr(o.title), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: selected == o.value ? Colors.white : FpColors.navy)),
                      Text(tr(o.subtitle), maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: TextStyle(fontSize: 11, color: selected == o.value ? Colors.white70 : Colors.black45)),
                    ]),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _ConfirmSheet extends StatelessWidget {
  final FpQuote quote;
  const _ConfirmSheet({required this.quote});

  @override
  Widget build(BuildContext context) {
    final d = quote.destination;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(tr('Confirmer l\'envoi'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          SizedBox(height: 12),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Text(d.flag ?? '', style: TextStyle(fontSize: 30)),
            title: Text(d.name ?? d.phone ?? ''),
            subtitle: Text('${d.label}${d.countryName != null ? ' · ${d.countryName}' : ''}'),
          ),
          FpQuoteSummary(quote: quote),
          if (quote.source.type == 'card')
            Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(tr('Une page de paiement sécurisée (3-D Secure) va s\'ouvrir pour régler par carte.'),
                  style: TextStyle(fontSize: 12, color: Colors.black54), textAlign: TextAlign.center),
            ),
          if (quote.source.type == 'mobile')
            Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(tr('Vous recevrez une demande de validation sur votre téléphone : saisissez votre code secret mobile money.'),
                  style: TextStyle(fontSize: 12, color: Colors.black54), textAlign: TextAlign.center),
            ),
          SizedBox(height: 16),
          ElevatedButton(onPressed: () => Navigator.pop(context, true), child: Text(tr('Confirmer'))),
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
        ])),
      ),
    );
  }
}
