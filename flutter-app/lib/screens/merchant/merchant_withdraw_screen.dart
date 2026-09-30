import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/corridor.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../widgets/phone_input.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

/// Retrait des fonds collectés vers mobile money (MTN, Airtel, Orange, Moov…).
class MerchantWithdrawScreen extends StatefulWidget {
  const MerchantWithdrawScreen({super.key});

  @override
  State<MerchantWithdrawScreen> createState() => _MerchantWithdrawScreenState();
}

class _MerchantWithdrawScreenState extends State<MerchantWithdrawScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  List<FpCountry>? _countries;
  FpPhoneValue? _phone;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _service.countries().then((c) => setState(() => _countries = c)).catchError((e) => setState(() => _error = apiErrorMessage(e)));
  }

  Future<void> _submit() async {
    final amount = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    if (amount == null || amount < 100 || _phone == null || !_phone!.isValid) {
      setState(() => _error = 'Renseignez un numéro complet et un montant (100 minimum).');
      return;
    }
    setState(() { _loading = true; _error = null; });
    try {
      final s = await _service.merchantWithdraw(phone: _phone!.international, amount: amount);
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: tr('Retrait'))));
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final countries = _countries;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Retirer mes fonds'))),
      body: countries == null
          ? Center(child: _error != null ? Text(_error!) : CircularProgressIndicator())
          : ListView(
              padding: EdgeInsets.all(24),
              children: [
                FpPhoneInput(
                  countries: countries,
                  label: tr('Compte mobile money à créditer'),
                  initialPhone: user?.phone != null ? '+${user!.phone.replaceAll('+', '')}' : null,
                  countryFilter: (c) => c.payout,
                  operatorsLabel: 'Retirer vers',
                  onChanged: (v) => _phone = v,
                ),
                SizedBox(height: 16),
                TextField(
                  controller: _amountCtrl,
                  keyboardType: TextInputType.number,
                  style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
                  decoration: InputDecoration(
                    labelText: tr('Montant'),
                    suffixText: user?.wallet?.currency ?? 'XAF',
                    helperText: 'Solde : ${fpMoney(user?.wallet?.balance ?? 0, user?.wallet?.currency ?? 'XAF')} · retrait gratuit',
                  ),
                ),
                if (_error != null) ...[
                  SizedBox(height: 12),
                  Text(_error!, style: TextStyle(color: FpColors.danger)),
                ],
                SizedBox(height: 24),
                ElevatedButton(
                  onPressed: _loading ? null : _submit,
                  child: _loading
                      ? SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(tr('Retirer')),
                ),
              ],
            ),
    );
  }
}
