import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../models/corridor.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/settlement_service.dart';
import '../../widgets/phone_input.dart';
import '../../l10n/l10n.dart';

/// Ajout d'un compte de règlement : mobile money (tout opérateur / pays couvert),
/// compte bancaire, wallet FlashPay ou retrait cash chez un agent.
class SettlementAccountForm extends StatefulWidget {
  final String? country;
  const SettlementAccountForm({super.key, this.country});

  @override
  State<SettlementAccountForm> createState() => _SettlementAccountFormState();
}

class _SettlementAccountFormState extends State<SettlementAccountForm> {
  final _service = SettlementService();
  String _type = 'mobile_money';
  List<FpCountry>? _countries;
  FpPhoneValue? _phone;
  final _label = TextEditingController();
  final _bank = TextEditingController();
  final _holder = TextEditingController();
  final _number = TextEditingController();
  final _swift = TextEditingController();
  bool _default = false;
  bool _busy = false;
  String? _error;

  static const _types = [
    ('mobile_money', Icons.phone_android_rounded, 'Mobile money', 'MTN, Airtel, Orange…'),
    ('bank', Icons.account_balance_rounded, 'Banque', 'Virement'),
    ('wallet', Icons.account_balance_wallet_rounded, 'FlashPay', 'Instantané'),
    ('cash_pickup', Icons.payments_rounded, 'Cash', 'Chez un agent'),
  ];

  @override
  void initState() {
    super.initState();
    PaymentService().countries().then((c) => setState(() => _countries = c)).catchError((_) {});
  }

  Future<void> _save() async {
    setState(() { _busy = true; _error = null; });
    try {
      await _service.addAccount({
        'type': _type,
        if (_label.text.trim().isNotEmpty) 'label': _label.text.trim(),
        if ((_type == 'mobile_money' || _type == 'wallet') && _phone != null) ...{'phone': _phone!.international, 'country': _phone!.country.iso},
        if (_type == 'bank') ...{
          'bank_name': _bank.text.trim(),
          'account_holder': _holder.text.trim(),
          'account_number': _number.text.trim(),
          if (_swift.text.trim().isNotEmpty) 'swift': _swift.text.trim(),
          if (widget.country != null) 'country': widget.country,
        },
        if (_type == 'cash_pickup' && _holder.text.trim().isNotEmpty) 'account_holder': _holder.text.trim(),
        'is_default': _default,
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      setState(() { _error = apiErrorMessage(e); _busy = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final phoneType = _type == 'mobile_money' || _type == 'wallet';
    return Scaffold(
      appBar: AppBar(title: Text(tr('Nouveau compte de règlement'))),
      body: ListView(padding: EdgeInsets.all(20), children: [
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: NeverScrollableScrollPhysics(),
          mainAxisSpacing: 10,
          crossAxisSpacing: 10,
          childAspectRatio: 2.4,
          children: _types.map((t) {
            final on = _type == t.$1;
            return InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: () => setState(() => _type = t.$1),
              child: Container(
                padding: EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: on ? FpColors.navy : Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: on ? FpColors.navy : Colors.black12),
                ),
                child: Row(children: [
                  Icon(t.$2, color: on ? FpColors.orange : FpColors.navy),
                  SizedBox(width: 8),
                  Expanded(
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(t.$3, style: TextStyle(fontWeight: FontWeight.w800, color: on ? Colors.white : FpColors.navy)),
                      Text(t.$4, style: TextStyle(fontSize: 11, color: on ? Colors.white70 : Colors.black45), overflow: TextOverflow.ellipsis),
                    ]),
                  ),
                ]),
              ),
            );
          }).toList(),
        ),
        SizedBox(height: 20),
        TextField(controller: _label, decoration: InputDecoration(labelText: tr('Nom du compte (facultatif)'), hintText: tr('Ex. MTN caisse, BGFI principal'))),
        SizedBox(height: 14),
        if (phoneType && _countries != null)
          FpPhoneInput(
            countries: _countries!,
            initialIso: widget.country,
            label: _type == 'wallet' ? 'Numéro du wallet FlashPay' : 'Numéro mobile money',
            countryFilter: (c) => _type == 'wallet' || c.payout,
            onChanged: (v) => _phone = v,
          ),
        if (phoneType && _countries == null) LinearProgressIndicator(),
        if (_type == 'bank') ...[
          TextField(controller: _bank, decoration: InputDecoration(labelText: tr('Banque'), hintText: tr('Ex. BGFI Bank Congo'))),
          SizedBox(height: 12),
          TextField(controller: _holder, decoration: InputDecoration(labelText: tr('Titulaire du compte'))),
          SizedBox(height: 12),
          TextField(controller: _number, decoration: InputDecoration(labelText: tr('RIB / IBAN'))),
          SizedBox(height: 12),
          TextField(controller: _swift, decoration: InputDecoration(labelText: tr('SWIFT / BIC (facultatif)'))),
          SizedBox(height: 8),
          Text(tr('Les virements sont exécutés par FlashPay sous 24 h ouvrées.'), style: TextStyle(fontSize: 12, color: Colors.black45)),
        ],
        if (_type == 'cash_pickup') ...[
          TextField(controller: _holder, decoration: InputDecoration(labelText: tr('Personne qui retire (pièce d\'identité)'), hintText: tr('Par défaut : vous'))),
          SizedBox(height: 8),
          Text(tr('À chaque règlement, vous recevez un code à présenter chez un agent FlashPay.'), style: TextStyle(fontSize: 12, color: Colors.black45)),
        ],
        SizedBox(height: 8),
        SwitchListTile(contentPadding: EdgeInsets.zero, value: _default, onChanged: (v) => setState(() => _default = v), title: Text(tr('Compte par défaut (règlement automatique)'))),
        if (_error != null) Padding(padding: EdgeInsets.only(top: 8), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
        SizedBox(height: 16),
        ElevatedButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Enregistrement…' : 'Enregistrer le compte')),
      ]),
    );
  }
}
