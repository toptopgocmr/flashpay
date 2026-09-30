import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../config/theme.dart';
import '../../widgets/fp_country.dart';
import '../../models/payment_method.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../l10n/l10n.dart';

/// Retrait avec code : cash pickup chez un agent FlashPay ou retrait au GAB
/// d'une banque partenaire, sans carte. Le wallet est débité à la création
/// du bon ; s'il n'est pas utilisé avant expiration, il est remboursé.
class VoucherScreen extends StatefulWidget {
  final String channel; // cash_pickup | atm
  final String country;
  final String countryName;
  const VoucherScreen({super.key, required this.channel, required this.country, required this.countryName});

  @override
  State<VoucherScreen> createState() => _VoucherScreenState();
}

class _VoucherScreenState extends State<VoucherScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  final _phoneCtrl = FpPhoneController();
  bool _forSomeoneElse = false;
  bool _busy = false;
  String? _error;
  FpVoucher? _voucher;
  List<FpVoucher> _pending = [];

  bool get _atm => widget.channel == 'atm';

  @override
  void initState() {
    super.initState();
    _loadPending();
  }

  Future<void> _loadPending() async {
    try {
      final list = await _service.vouchers();
      if (mounted) {
        setState(() => _pending = list.where((v) => v.status == 'pending' && v.channel == widget.channel).toList());
      }
    } catch (_) {}
  }

  Future<void> _create() async {
    final amount = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    if (amount == null || amount < 500) {
      setState(() => _error = 'Montant minimum : 500');
      return;
    }
    setState(() { _busy = true; _error = null; });
    try {
      final v = await _service.createVoucher(
        channel: widget.channel,
        amount: amount,
        country: widget.country,
        beneficiaryName: _forSomeoneElse ? _nameCtrl.text.trim() : null,
        beneficiaryPhone: _forSomeoneElse ? _phoneCtrl.international : null,
      );
      if (!mounted) return;
      await context.read<SessionProvider>().refreshUser();
      setState(() => _voucher = v);
    } catch (e) {
      setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(FpVoucher v) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Annuler ce retrait ?')),
        content: Text('${fpMoney(v.amount + v.fee, v.currency)} seront remboursés sur votre wallet.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Non'))),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Oui, annuler'))),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await _service.cancelVoucher(v.id);
      if (!mounted) return;
      await context.read<SessionProvider>().refreshUser();
      setState(() { if (_voucher?.id == v.id) _voucher = null; });
      _loadPending();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(apiErrorMessage(e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final cur = user?.wallet?.currency ?? 'XAF';
    final title = _atm ? 'Retrait au GAB' : 'Retrait cash chez un agent';

    return Scaffold(
      appBar: AppBar(title: Text(tr(title))),
      body: ListView(
        padding: EdgeInsets.all(20),
        children: _voucher != null ? _result(_voucher!) : _form(cur, user?.wallet?.balance ?? 0),
      ),
    );
  }

  List<Widget> _form(String cur, int balance) => [
        Container(
          padding: EdgeInsets.all(14),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
          child: Row(children: [
            Icon(_atm ? Icons.local_atm_rounded : Icons.payments_rounded, color: FpColors.navy),
            SizedBox(width: 10),
            Expanded(
              child: Text(
                _atm
                    ? 'Vous recevez un code à 12 chiffres. Saisissez-le au GAB d\'une banque partenaire en ${widget.countryName} (option « Retrait sans carte »).'
                    : 'Vous recevez un code de retrait. Présentez-le avec une pièce d\'identité à un agent FlashPay en ${widget.countryName}.',
                style: TextStyle(fontSize: 13),
              ),
            ),
          ]),
        ),
        SizedBox(height: 20),
        TextField(
          controller: _amountCtrl,
          keyboardType: TextInputType.number,
          style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
          decoration: InputDecoration(labelText: tr('Montant à retirer'), suffixText: cur, helperText: 'Solde disponible : ${fpMoney(balance, cur)}'),
        ),
        if (!_atm) ...[
          SizedBox(height: 12),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _forSomeoneElse,
            onChanged: (v) => setState(() => _forSomeoneElse = v),
            title: Text(tr('Une autre personne retire l\'argent')),
          ),
          if (_forSomeoneElse) ...[
            TextField(controller: _nameCtrl, decoration: InputDecoration(labelText: tr('Nom du bénéficiaire (comme sur sa pièce d\'identité)'))),
            SizedBox(height: 8),
            FpPhoneField(label: tr('Téléphone du bénéficiaire'), controller: _phoneCtrl),
          ],
        ],
        if (_error != null) Padding(padding: EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
        SizedBox(height: 20),
        ElevatedButton(
          onPressed: _busy ? null : _create,
          child: _busy
              ? SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
              : Text(tr('Obtenir mon code de retrait')),
        ),
        if (_pending.isNotEmpty) ...[
          SizedBox(height: 28),
          Text(tr('Retraits en attente'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
          SizedBox(height: 8),
          ..._pending.map((v) => Card(
                child: ListTile(
                  leading: Icon(v.isAtm ? Icons.local_atm_rounded : Icons.payments_rounded),
                  title: Text(fpMoney(v.amount, v.currency)),
                  subtitle: Text('Code ${v.groupedCode} · expire le ${DateFormat('dd/MM HH:mm').format(v.expiresAt)}'),
                  trailing: TextButton(onPressed: () => _cancel(v), child: Text(tr('Annuler'))),
                  onTap: () => setState(() => _voucher = v),
                ),
              )),
        ],
      ];

  List<Widget> _result(FpVoucher v) => [
        Icon(Icons.check_circle, color: FpColors.success, size: 56),
        SizedBox(height: 8),
        Text(
          fpMoney(v.amount, v.currency),
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 30, fontWeight: FontWeight.w900),
        ),
        Text(
          'Frais : ${fpMoney(v.fee, v.currency)} · Bénéficiaire : ${v.beneficiaryName ?? '-'}',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.black54),
        ),
        SizedBox(height: 20),
        Container(
          padding: EdgeInsets.all(20),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
          child: Column(children: [
            Text(v.isAtm ? 'Code GAB' : 'Code de retrait', style: TextStyle(color: Colors.black54)),
            SizedBox(height: 6),
            SelectableText(v.groupedCode, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w900, letterSpacing: 2)),
            if (!v.isAtm && v.qr != null) ...[
              SizedBox(height: 14),
              FpNfcBeacon(payload: v.qr, child: QrImageView(data: v.qr!, size: 180, foregroundColor: FpColors.navy)),
            ],
            SizedBox(height: 10),
            Text('Valable jusqu\'au ${DateFormat('dd/MM/yyyy à HH:mm').format(v.expiresAt)}',
                style: TextStyle(fontSize: 12, color: Colors.black45)),
          ]),
        ),
        SizedBox(height: 16),
        Text(
          v.isAtm
              ? 'Au GAB : « Retrait sans carte » → saisissez ce code. Non utilisé avant expiration, le montant est remboursé automatiquement.'
              : 'Chez l\'agent : présentez ce code (ou le QR) et une pièce d\'identité. Non utilisé avant expiration, le montant est remboursé automatiquement.',
          style: TextStyle(fontSize: 13, height: 1.4),
        ),
        SizedBox(height: 20),
        OutlinedButton(onPressed: () => _cancel(v), child: Text(tr('Annuler ce retrait'))),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Terminé'))),
      ];
}
