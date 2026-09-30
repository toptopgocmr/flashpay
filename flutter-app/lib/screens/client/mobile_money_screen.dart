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
import '../../widgets/quote_summary.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

enum MobileMoneyMode { deposit, withdraw }

/// Recharger le wallet depuis son mobile money (dépôt) ou retirer vers son
/// mobile money (retrait) — MTN, Airtel, Orange, Moov… Gratuit (style Wave).
class MobileMoneyScreen extends StatefulWidget {
  final MobileMoneyMode mode;
  final String? initialIso; // pays choisi dans « Recharger / Retirer »
  MobileMoneyScreen({super.key, required this.mode, this.initialIso});

  @override
  State<MobileMoneyScreen> createState() => _MobileMoneyScreenState();
}

class _MobileMoneyScreenState extends State<MobileMoneyScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  List<FpCountry>? _countries;
  FpPhoneValue? _phone;
  FpQuote? _quote;
  String? _error;
  bool _busy = false;
  Timer? _debounce;

  bool get _deposit => widget.mode == MobileMoneyMode.deposit;

  @override
  void initState() {
    super.initState();
    _service.countries().then((c) => setState(() => _countries = c)).catchError((e) => setState(() => _error = apiErrorMessage(e)));
    // Compte mobile money lié par défaut = compte débité (recharge) / crédité (retrait)
    LinkedSources.defaultMobilePhone().then((p) {
      if (mounted) setState(() { _linkedPhone = p; _linkedLoaded = true; });
    });
  }

  String? _linkedPhone;
  bool _linkedLoaded = false;

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
    if (_phone == null || !_phone!.isValid || amount == null || amount < 10) {
      setState(() => _quote = null);
      return;
    }
    try {
      final q = await _service.quote(
        operation: _deposit ? 'deposit' : 'withdraw',
        amount: amount,
        sourcePhone: _deposit ? _phone!.international : null,
        destinationPhone: _deposit ? null : _phone!.international,
      );
      if (mounted) setState(() { _quote = q; _error = null; });
    } catch (e) {
      if (mounted) setState(() { _quote = null; _error = apiErrorMessage(e); });
    }
  }

  Future<void> _submit() async {
    final q = _quote;
    if (q == null || !q.available) return;
    setState(() { _busy = true; _error = null; });
    try {
      final s = _deposit
          ? await _service.deposit(phone: _phone!.international, amount: q.amount)
          : await _service.withdraw(phone: _phone!.international, amount: q.amount);
      if (!mounted) return;
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: _deposit ? 'Recharge' : 'Retrait')),
      );
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final countries = _countries;
    final cur = user?.wallet?.currency ?? 'XAF';

    return Scaffold(
      appBar: AppBar(title: Text(_deposit ? 'Recharger mon compte' : 'Retirer vers mobile money')),
      body: countries == null || !_linkedLoaded
          ? Center(child: _error != null ? Text(_error!) : CircularProgressIndicator())
          : ListView(
              padding: EdgeInsets.all(20),
              children: [
                Container(
                  padding: EdgeInsets.all(14),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                  child: Row(children: [
                    Icon(_deposit ? Icons.south_west_rounded : Icons.north_east_rounded, color: FpColors.navy),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        _deposit
                            ? 'Transférez de l\'argent de votre compte mobile money vers votre wallet FlashPay. Vous validerez avec votre code secret.'
                            : 'Envoyez l\'argent de votre wallet vers votre compte mobile money (MTN, Airtel, Orange…).',
                        style: TextStyle(fontSize: 13),
                      ),
                    ),
                  ]),
                ),
                SizedBox(height: 20),
                FpPhoneInput(
                  countries: countries,
                  label: _deposit
                      ? (_linkedPhone != null ? 'Compte mobile money lié à débiter' : 'Numéro mobile money à débiter')
                      : 'Numéro mobile money à créditer',
                  initialIso: widget.initialIso,
                  // Compte mobile money lié par défaut, sinon numéro du client (s'il est du pays choisi)
                  initialPhone: _linkedPhone != null && (widget.initialIso == null || _linkedPhone!.startsWith(countries.where((c) => c.iso == widget.initialIso).map((c) => c.dial).firstOrNull ?? '+'))
                      ? _linkedPhone
                      : user?.phone != null && (widget.initialIso == null || widget.initialIso == user!.wallet?.country)
                      ? '+${user!.phone.replaceAll('+', '')}'
                      : null,
                  countryFilter: (c) => _deposit ? c.collect : c.payout,
                  operatorsLabel: _deposit ? 'Recharger depuis' : 'Retirer vers',
                  onChanged: (v) { _phone = v; _schedule(); },
                ),
                SizedBox(height: 20),
                TextField(
                  controller: _amountCtrl,
                  keyboardType: TextInputType.number,
                  style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
                  decoration: InputDecoration(labelText: tr('Montant'), suffixText: cur,
                      helperText: _deposit ? null : 'Solde disponible : ${fpMoney(user?.wallet?.balance ?? 0, cur)}'),
                  onChanged: (_) => _schedule(),
                ),
                SizedBox(height: 20),
                if (_quote != null) FpQuoteSummary(quote: _quote!, receiveLabel: _deposit ? 'Crédité sur le wallet' : 'Reçu sur le mobile'),
                if (_error != null) Padding(padding: EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
                SizedBox(height: 16),
                ElevatedButton(
                  onPressed: (_quote?.available ?? false) && !_busy ? _submit : null,
                  child: _busy
                      ? SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(_deposit ? 'Recharger' : 'Retirer'),
                ),
              ],
            ),
    );
  }
}
