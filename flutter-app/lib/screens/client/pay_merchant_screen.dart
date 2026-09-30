import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/corridor.dart';
import '../../models/quote.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../widgets/phone_input.dart';
import '../../services/linked_sources.dart';
import '../../services/checkout.dart';
import 'linked_accounts_screen.dart';
import '../../widgets/quote_summary.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

/// Paiement d'un marchand FlashPay (scanné par QR ou saisi) — depuis le
/// wallet ou depuis n'importe quel compte mobile money de la sous-région.
/// Gratuit pour le client ; le marchand peut être dans un autre pays.
class PayMerchantScreen extends StatefulWidget {
  final String merchantCode;
  final String method; // qr | nfc
  PayMerchantScreen({super.key, required this.merchantCode, this.method = 'qr'});

  @override
  State<PayMerchantScreen> createState() => _PayMerchantScreenState();
}

class _PayMerchantScreenState extends State<PayMerchantScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  Map<String, dynamic>? _merchant;
  List<FpCountry>? _countries;
  String _source = LinkedSources.takePaySource(); // wallet | mobile | card
  FpPhoneValue? _srcPhone;
  String? _linkedPhone;
  Map<String, dynamic>? _linkedCard;
  bool _linkedLoaded = false;
  FpQuote? _quote;
  String? _error;
  bool _busy = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _load();
    _loadLinked();
  }

  Future<void> _loadLinked({bool refresh = false}) async {
    if (refresh) LinkedSources.clear();
    final phone = await LinkedSources.defaultMobilePhone();
    final card = await LinkedSources.defaultCard();
    if (mounted) setState(() { _linkedPhone = phone; _linkedCard = card; _linkedLoaded = true; });
  }

  Future<void> _load() async {
    try {
      final r = await Future.wait([_service.merchantInfo(widget.merchantCode), _service.countries()]);
      setState(() {
        _merchant = r[0] as Map<String, dynamic>;
        _countries = r[1] as List<FpCountry>;
      });
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(Duration(milliseconds: 450), _refresh);
  }

  Future<void> _refresh() async {
    final amount = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    if (amount == null || amount < 10 || (_source == 'mobile' && !(_srcPhone?.isValid ?? false))) {
      setState(() => _quote = null);
      return;
    }
    try {
      final q = await _service.quote(
        operation: 'merchant',
        amount: amount,
        source: _source,
        sourcePhone: _source == 'mobile' ? _srcPhone!.international : null,
        merchantCode: widget.merchantCode,
      );
      if (mounted) setState(() { _quote = q; _error = null; });
    } catch (e) {
      if (mounted) setState(() { _quote = null; _error = apiErrorMessage(e); });
    }
  }

  Future<void> _pay() async {
    final q = _quote;
    if (q == null || !q.available) return;
    setState(() { _busy = true; _error = null; });
    try {
      final s = await _service.payMerchant(
        merchantCode: widget.merchantCode,
        source: _source,
        sourcePhone: _source == 'mobile' ? _srcPhone!.international : null,
        amount: q.amount,
        method: widget.method,
      );
      if (s.awaitingCard) await openCheckout(s.checkoutUrl);
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: tr('Paiement'))));
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final m = _merchant;
    final countries = _countries;

    return Scaffold(
      appBar: AppBar(title: Text(tr('Payer un marchand'))),
      body: m == null || countries == null
          ? Center(child: _error != null ? Padding(padding: EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)) : CircularProgressIndicator())
          : ListView(
              padding: EdgeInsets.all(20),
              children: [
                Card(
                  child: ListTile(
                    leading: CircleAvatar(backgroundColor: FpColors.navy, child: Icon(Icons.storefront, color: Colors.white)),
                    title: Text(m['name'] ?? '', style: TextStyle(fontWeight: FontWeight.w800)),
                    subtitle: Text([m['category'], m['country']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
                    trailing: m['validated'] == true ? Icon(Icons.verified, color: FpColors.success) : null,
                  ),
                ),
                SizedBox(height: 20),
                TextField(
                  controller: _amountCtrl,
                  autofocus: true,
                  keyboardType: TextInputType.number,
                  style: TextStyle(fontSize: 30, fontWeight: FontWeight.w900),
                  decoration: InputDecoration(labelText: tr('Montant à payer'), suffixText: user?.wallet?.currency ?? 'XAF'),
                  onChanged: (_) => _schedule(),
                ),
                SizedBox(height: 20),
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(value: 'wallet', icon: Icon(Icons.account_balance_wallet_outlined), label: Text('Wallet · ${fpMoney(user?.wallet?.balance ?? 0, user?.wallet?.currency ?? 'XAF')}')),
                    ButtonSegment(value: 'mobile', icon: Icon(Icons.phone_android), label: Text(tr('Mobile money'))),
                    ButtonSegment(value: 'card', label: FpCardBrands(height: 14)),
                  ],
                  selected: {_source},
                  onSelectionChanged: (s) { setState(() => _source = s.first); _schedule(); },
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
                    label: _linkedPhone != null ? 'Payer depuis votre mobile money lié' : 'Payer depuis le numéro',
                    initialPhone: _linkedPhone ?? (user?.phone != null ? '+${user!.phone.replaceAll('+', '')}' : null),
                    countryFilter: (c) => c.collect,
                    operatorsLabel: 'Payer avec',
                    onChanged: (v) { _srcPhone = v; _schedule(); },
                  ),
                ],
                SizedBox(height: 20),
                if (_quote != null) FpQuoteSummary(quote: _quote!, receiveLabel: 'Le marchand reçoit'),
                if (_error != null) Padding(padding: EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
                SizedBox(height: 16),
                ElevatedButton(
                  onPressed: (_quote?.available ?? false) && !_busy ? _pay : null,
                  child: _busy
                      ? SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(_quote == null ? 'Payer' : 'Payer ${fpMoney(_quote!.total, _quote!.currency)}'),
                ),
              ],
            ),
    );
  }
}
