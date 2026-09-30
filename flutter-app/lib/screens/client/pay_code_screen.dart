import 'dart:async';
import '../../services/nfc_bridge.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../config/theme.dart';
import '../../models/payment_method.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';

enum PayCodePurpose { pay, deposit }

/// Code de paiement FlashPay (style Alipay / WeChat Pay) : QR + code à
/// 18 chiffres, usage unique, renouvelé automatiquement toutes les 2 min.
///  - pay     : le marchand scanne le code, le montant est débité du wallet
///  - deposit : l'agent scanne le code et crédite les espèces reçues
class PayCodeScreen extends StatefulWidget {
  final PayCodePurpose purpose;
  const PayCodeScreen({super.key, this.purpose = PayCodePurpose.pay});

  @override
  State<PayCodeScreen> createState() => _PayCodeScreenState();
}

class _PayCodeScreenState extends State<PayCodeScreen> {
  final _service = PaymentService();
  FpPayCode? _code;
  String? _error;
  Timer? _tick;
  int _left = 0;
  int _startBalance = -1;
  bool _showDigits = false;

  @override
  void initState() {
    super.initState();
    _startBalance = context.read<SessionProvider>().user?.wallet?.balance ?? -1;
    _refresh();
    _tick = Timer.periodic(Duration(seconds: 1), (_) => _onTick());
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _refresh() async {
    try {
      final c = await _service.payCode();
      if (mounted) setState(() { _code = c; _error = null; _left = c.expiresAt.difference(DateTime.now()).inSeconds; });
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    }
  }

  Future<void> _onTick() async {
    if (_code == null) return;
    final left = _code!.expiresAt.difference(DateTime.now()).inSeconds;
    setState(() => _left = left);
    if (left <= 0) {
      _refresh();
    }
    // Toutes les 5 s : le solde a-t-il bougé ? (code utilisé par le marchand / l'agent)
    if (left % 5 == 0) {
      final session = context.read<SessionProvider>();
      try {
        await session.refreshUser();
      } catch (_) {}
      final b = session.user?.wallet?.balance ?? -1;
      if (mounted && _startBalance >= 0 && b != _startBalance) {
        final diff = b - _startBalance;
        _startBalance = b;
        final cur = session.user?.wallet?.currency ?? 'XAF';
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          backgroundColor: FpColors.success,
          content: Text(diff > 0 ? 'Compte crédité de ${fpMoney(diff, cur)}' : 'Paiement de ${fpMoney(-diff, cur)} effectué'),
        ));
        _refresh(); // code consommé : on en génère un nouveau
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final deposit = widget.purpose == PayCodePurpose.deposit;
    final user = context.watch<SessionProvider>().user;
    final c = _code;

    return Scaffold(
      backgroundColor: FpColors.navy,
      appBar: AppBar(
        backgroundColor: FpColors.navy,
        foregroundColor: Colors.white,
        title: Text(deposit ? 'Déposer chez un agent' : 'Mon code de paiement'),
      ),
      body: ListView(
        padding: EdgeInsets.all(24),
        children: [
          Container(
            padding: EdgeInsets.all(20),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(22)),
            child: Column(children: [
              Text(user?.fullName ?? '', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
              SizedBox(height: 4),
              Text(
                deposit ? 'Montrez ce code à l\'agent FlashPay' : 'Montrez ce code au marchand',
                style: TextStyle(color: Colors.black54, fontSize: 13),
              ),
              SizedBox(height: 16),
              if (c == null && _error == null) SizedBox(height: 220, child: Center(child: CircularProgressIndicator())),
              if (_error != null) Padding(padding: EdgeInsets.all(24), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
              if (c != null) ...[
                FpNfcBeacon(payload: c.qr, child: QrImageView(data: c.qr, size: 230, foregroundColor: FpColors.navy)),
                SizedBox(height: 12),
                GestureDetector(
                  onTap: () => setState(() => _showDigits = !_showDigits),
                  child: Text(
                    _showDigits ? c.grouped : '${c.code.substring(0, 4)} •••• •••• ••••  Afficher',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, letterSpacing: 1.2),
                  ),
                ),
                SizedBox(height: 10),
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.timer_outlined, size: 16, color: Colors.black45),
                  SizedBox(width: 4),
                  Text('Nouveau code dans ${_left.clamp(0, 999)} s', style: TextStyle(fontSize: 12, color: Colors.black45)),
                  SizedBox(width: 8),
                  InkWell(onTap: _refresh, child: Icon(Icons.refresh, size: 18, color: FpColors.teal)),
                ]),
              ],
            ]),
          ),
          SizedBox(height: 20),
          Text(
            deposit
                ? '1. Remettez les espèces à l\'agent.\n2. L\'agent scanne ce code et saisit le montant.\n3. Votre wallet est crédité immédiatement.'
                : 'Le code est à usage unique et change toutes les 2 minutes. Ne le partagez jamais par message ou par téléphone.',
            style: TextStyle(color: Colors.white70, height: 1.5),
          ),
        ],
      ),
    );
  }
}
