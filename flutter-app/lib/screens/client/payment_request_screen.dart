import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Paiement d'un QR dynamique / lien de paiement / session NFC (§6.2, §6.3)
/// ou d'un achat e-commerce à confirmer dans l'app (§4.7.4). Le montant est
/// figé par le marchand ; la confirmation se fait par PIN (bottom-sheet).
class PaymentRequestScreen extends StatefulWidget {
  final String? token;
  final String? signature;
  final String? intentId;
  const PaymentRequestScreen({super.key, this.token, this.signature, this.intentId});

  @override
  State<PaymentRequestScreen> createState() => _PaymentRequestScreenState();
}

class _PaymentRequestScreenState extends State<PaymentRequestScreen> {
  final _service = FeaturesService();
  Map<String, dynamic>? _d;
  String? _error;
  bool _paying = false;
  Map<String, dynamic>? _done;

  bool get _isIntent => widget.intentId != null;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = _isIntent ? await _service.intent(widget.intentId!) : await _service.paymentRequest(widget.token!, sig: widget.signature);
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    }
  }

  Future<void> _pay() async {
    setState(() { _paying = true; _error = null; });
    try {
      final r = _isIntent ? await _service.payIntent(widget.intentId!) : await _service.payRequest(widget.token!, sig: widget.signature);
      if (mounted) setState(() => _done = r);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _paying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final merchant = _isIntent ? '${d?['merchant']?['name'] ?? ''}' : '${d?['merchant']?['name'] ?? ''}';
    final payable = d != null && (_isIntent ? d['status'] == 'pending' : d['status'] == 'pending');
    final ok = _done != null && (_done!['status'] == 'successful' || _done!['status'] == 'confirmed');
    return Scaffold(
      appBar: AppBar(title: Text(_isIntent ? 'Paiement en ligne' : 'Paiement marchand')),
      body: d == null && _error == null
          ? Center(child: CircularProgressIndicator())
          : ListView(padding: EdgeInsets.all(24), children: [
              if (d != null) ...[
                Icon(Icons.storefront, size: 56, color: FpColors.navy),
                SizedBox(height: 8),
                Text(merchant, textAlign: TextAlign.center, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                if (d['merchant']?['outlet'] != null) Text('${d['merchant']['outlet']}', textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
                SizedBox(height: 16),
                Text(fpMoney(fpInt(d['amount']), '${d['currency'] ?? 'XAF'}'), textAlign: TextAlign.center, style: TextStyle(fontSize: 34, fontWeight: FontWeight.w900)),
                if ((d['description'] ?? '').toString().isNotEmpty) Text('${d['description']}', textAlign: TextAlign.center),
                if (d['order_reference'] != null) Text('Commande ${d['order_reference']}', textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
                if (d['livemode'] == false) Padding(padding: EdgeInsets.only(top: 8), child: FpBanner('Mode test : aucun débit réel.', icon: Icons.science_outlined)),
              ],
              if (_error != null) Padding(padding: EdgeInsets.only(top: 16), child: Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: FpColors.danger))),
              SizedBox(height: 24),
              if (_done != null)
                Column(children: [
                  Icon(ok ? Icons.check_circle : Icons.hourglass_top, size: 64, color: ok ? FpColors.success : Color(0xFFA16207)),
                  SizedBox(height: 8),
                  Text(ok ? 'Paiement effectué' : 'Paiement en cours de validation', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                  SizedBox(height: 16),
                  ElevatedButton(onPressed: () => Navigator.pop(context), child: Text(tr('Terminer'))),
                ])
              else if (payable)
                ElevatedButton.icon(
                  onPressed: _paying ? null : _pay,
                  icon: Icon(Icons.lock),
                  label: Text(_paying ? 'Paiement…' : 'Payer ${fpMoney(fpInt(d['amount']), '${d['currency'] ?? 'XAF'}')}'),
                )
              else if (d != null)
                FpBanner(switch ('${d['status']}') { 'paid' || 'confirmed' => 'Déjà payé.', 'expired' => 'Ce QR a expiré : demandez-en un nouveau au marchand.', _ => 'Ce paiement n\'est plus disponible.' }, icon: Icons.info_outline),
              if (!_isIntent && payable && d['seconds_left'] != null)
                Padding(padding: EdgeInsets.only(top: 12), child: Text('Expire dans ${d['seconds_left']} s', textAlign: TextAlign.center, style: TextStyle(color: Colors.black54))),
            ]),
    );
  }
}
