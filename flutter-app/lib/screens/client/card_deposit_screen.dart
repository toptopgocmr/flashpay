import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/quote.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/checkout.dart';
import '../../services/payment_service.dart';
import '../../widgets/quote_summary.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

/// Recharger le wallet avec une carte Visa / Mastercard (prépayée ou bancaire).
/// Le paiement se fait sur la page sécurisée de la passerelle (3-D Secure) :
/// l'application ne voit jamais le numéro de carte.
class CardDepositScreen extends StatefulWidget {
  const CardDepositScreen({super.key});

  @override
  State<CardDepositScreen> createState() => _CardDepositScreenState();
}

class _CardDepositScreenState extends State<CardDepositScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  FpQuote? _quote;
  String? _error;
  bool _busy = false;
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(Duration(milliseconds: 400), _refresh);
  }

  Future<void> _refresh() async {
    final amount = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    if (amount == null || amount < 100) {
      setState(() => _quote = null);
      return;
    }
    try {
      final q = await _service.quote(operation: 'deposit', amount: amount, source: 'card');
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
      final s = await _service.cardDeposit(amount: q.amount);
      await openCheckout(s.checkoutUrl);
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: tr('Recharge par carte'))));
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<SessionProvider>().user?.wallet?.currency ?? 'XAF';
    return Scaffold(
      appBar: AppBar(title: Text(tr('Recharger par carte'))),
      body: ListView(
        padding: EdgeInsets.all(20),
        children: [
          Container(
            padding: EdgeInsets.all(18),
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [FpColors.navy, Color(0xFF1BA8F0)]),
              borderRadius: BorderRadius.circular(18),
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Icon(Icons.credit_card_rounded, color: Colors.white, size: 30),
                Spacer(),
                Text(tr('VISA  ·  Mastercard'), style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, letterSpacing: 1)),
              ]),
              SizedBox(height: 18),
              Text(tr('Carte prépayée ou bancaire'), style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w700)),
              SizedBox(height: 4),
              Text(tr('Paiement sécurisé 3-D Secure. Vos données de carte ne transitent jamais par FlashPay.'),
                  style: TextStyle(color: Colors.white70, fontSize: 12.5)),
            ]),
          ),
          SizedBox(height: 24),
          TextField(
            controller: _amountCtrl,
            keyboardType: TextInputType.number,
            style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
            decoration: InputDecoration(labelText: tr('Montant à créditer'), suffixText: cur),
            onChanged: (_) => _schedule(),
          ),
          SizedBox(height: 20),
          if (_quote != null) FpQuoteSummary(quote: _quote!, receiveLabel: 'Crédité sur le wallet'),
          if (_error != null) Padding(padding: EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
          SizedBox(height: 16),
          ElevatedButton.icon(
            onPressed: (_quote?.available ?? false) && !_busy ? _pay : null,
            icon: Icon(Icons.lock_outline),
            label: _busy
                ? SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : Text(_quote == null ? 'Payer par carte' : 'Payer ${fpMoney(_quote!.total, _quote!.currency)}'),
          ),
        ],
      ),
    );
  }
}
