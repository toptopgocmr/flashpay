import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../models/corridor.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../widgets/phone_input.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

/// Encaissement USSD : pour un client SANS application. Le marchand saisit le
/// numéro mobile money du client (MTN, Airtel, Orange… de n'importe quel pays
/// couvert) ; le client reçoit une demande de validation sur son téléphone.
class MerchantCollectScreen extends StatefulWidget {
  const MerchantCollectScreen({super.key});

  @override
  State<MerchantCollectScreen> createState() => _MerchantCollectScreenState();
}

class _MerchantCollectScreenState extends State<MerchantCollectScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  List<FpCountry>? _countries;
  FpPhoneValue? _phone;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _service.countries().then((c) => setState(() => _countries = c)).catchError((e) => setState(() => _error = apiErrorMessage(e)));
  }

  Future<void> _submit() async {
    final amount = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    if (_phone == null || !_phone!.isValid || amount == null || amount < 10) {
      setState(() => _error = 'Saisissez un numéro complet et un montant.');
      return;
    }
    setState(() { _busy = true; _error = null; });
    try {
      final s = await _service.merchantCollectUssd(customerPhone: _phone!.international, amount: amount, customerName: _nameCtrl.text.trim());
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: tr('Encaissement'))));
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final countries = _countries;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Encaisser un client'))),
      body: countries == null
          ? Center(child: _error != null ? Text(_error!) : CircularProgressIndicator())
          : ListView(
              padding: EdgeInsets.all(20),
              children: [
                Container(
                  padding: EdgeInsets.all(14),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                  child: Row(children: [
                    Icon(Icons.phonelink_ring_rounded, color: FpColors.navy),
                    SizedBox(width: 10),
                    Expanded(child: Text(
                      tr('Le client n\'a pas besoin de l\'application : il reçoit une demande de paiement sur son téléphone et la valide avec son code mobile money.'),
                      style: TextStyle(fontSize: 13),
                    )),
                  ]),
                ),
                SizedBox(height: 20),
                FpPhoneInput(
                  countries: countries,
                  label: tr('Numéro mobile money du client'),
                  countryFilter: (c) => c.collect,
                  operatorsLabel: 'Le client paie avec',
                  onChanged: (v) => _phone = v,
                ),
                SizedBox(height: 16),
                TextField(controller: _nameCtrl, decoration: InputDecoration(labelText: tr('Nom du client (facultatif)'), prefixIcon: Icon(Icons.person_outline))),
                SizedBox(height: 16),
                TextField(
                  controller: _amountCtrl,
                  keyboardType: TextInputType.number,
                  style: TextStyle(fontSize: 28, fontWeight: FontWeight.w900),
                  decoration: InputDecoration(labelText: tr('Montant')),
                ),
                if (_error != null) Padding(padding: EdgeInsets.only(top: 10), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
                SizedBox(height: 24),
                ElevatedButton.icon(
                  onPressed: _busy ? null : _submit,
                  icon: Icon(Icons.send_to_mobile),
                  label: _busy ? Text(tr('Envoi…')) : Text(tr('Envoyer la demande de paiement')),
                ),
              ],
            ),
    );
  }
}
