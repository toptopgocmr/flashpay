import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import '../../models/quote.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../../config/theme.dart';
import '../../widgets/fp_country.dart';
import '../../services/agent_service.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import 'agent_result_screen.dart';
import '../../l10n/l10n.dart';

/// Dépôt d'espèces : l'agent saisit le montant reçu, puis scanne le QR
/// FlashPay du client (ou saisit son numéro). Le float de l'agent est débité,
/// le wallet du client crédité immédiatement.
class AgentCashInScreen extends StatefulWidget {
  /// true : saisie du numéro du client ; false : scan de son QR.
  final bool byPhone;
  /// Code de dépôt du client déjà lu (NFC depuis l'accueil agent).
  final String? initialRaw;
  const AgentCashInScreen({super.key, this.byPhone = false, this.initialRaw});

  @override
  State<AgentCashInScreen> createState() => _AgentCashInScreenState();
}

class _AgentCashInScreenState extends State<AgentCashInScreen> {
  final _service = AgentService();
  final _amountCtrl = TextEditingController();
  final _phoneCtrl = FpPhoneController();
  MobileScannerController? _scanner;
  late bool _byPhone = widget.byPhone;
  late String? _pendingRaw = widget.initialRaw;
  bool _busy = false;
  String? _error;

  int? get _amount {
    final a = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    return a != null && a >= 500 ? a : null;
  }

  @override
  void dispose() {
    _scanner?.dispose();
    super.dispose();
  }

  Future<String?> _askClientCode(ClientConfirmationRequired c) {
    final ctrl = TextEditingController(text: c.debugCode ?? '');
    return showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        title: Text('Confirmation ${c.clientName ?? 'du client'}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(c.message),
          if (c.debugCode != null) ...[
            SizedBox(height: 12),
            FpOtpCodeCard(code: c.debugCode!, title: tr('Code du client')),
          ],
          SizedBox(height: 12),
          TextField(controller: ctrl, autofocus: c.debugCode == null, keyboardType: TextInputType.number, maxLength: 6,
              decoration: InputDecoration(labelText: tr('Code reçu par le client'), counterText: '')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: Text(tr('Valider le dépôt'))),
        ],
      ),
    );
  }

  Future<void> _submit({String? code, String? phone}) async {
    final amount = _amount;
    if (amount == null || _busy) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Confirmer le dépôt')),
        content: Text('Vous avez bien reçu ${fpMoney(amount)} en espèces ?\nLe wallet du client sera crédité immédiatement.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Confirmer'))),
        ],
      ),
    );
    if (ok != true) return;
    setState(() { _busy = true; _error = null; });
    await _scanner?.stop();
    try {
      FpPaymentStatus s;
      try {
        s = await _service.cashIn(clientCode: code, clientPhone: phone, amount: amount);
      } on ClientConfirmationRequired catch (c) {
        final otp = await _askClientCode(c);
        if (otp == null || otp.isEmpty) {
          setState(() => _busy = false);
          await _scanner?.start();
          return;
        }
        s = await _service.cashIn(clientCode: code, clientPhone: phone, amount: amount, otp: otp);
      }
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(
        builder: (_) => AgentResultScreen(
          success: s.isSuccess,
          title: s.isSuccess ? 'Dépôt effectué' : 'Dépôt refusé',
          amount: fpMoney(s.amount, s.currency),
          lines: [s.message, 'Réf. ${s.reference}'],
        ),
      ));
    } catch (e) {
      if (!mounted) return;
      setState(() { _error = apiErrorMessage(e); _busy = false; });
      await _scanner?.start();
    }
  }

  /// Lecture NFC : le client approche son téléphone qui affiche son code de dépôt.
  Future<void> _nfc() async {
    await _scanner?.stop();
    if (!mounted) return;
    final raw = await FpNfc.readSheet(context, hint: 'Le client ouvre « Recharger » → « Espèces chez un agent » et approche son téléphone.');
    if (raw != null) {
      _onScan(raw);
    } else {
      await _scanner?.start();
    }
  }

  void _onScan(String raw) {
    final code = fpExtractCode(raw, host: 'code', length: 18);
    if (code == null) {
      setState(() => _error = 'Ce QR n\'est pas un code client FlashPay. Demandez au client d\'ouvrir « Recharger » → « Espèces chez un agent ».');
      return;
    }
    _submit(code: code);
  }

  @override
  Widget build(BuildContext context) {
    final ready = _amount != null;
    if (ready && !_byPhone && _scanner == null) _scanner = MobileScannerController();

    return Scaffold(
      appBar: AppBar(title: Text(tr('Dépôt client'))),
      body: Column(children: [
        Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 8),
          child: TextField(
            controller: _amountCtrl,
            keyboardType: TextInputType.number,
            autofocus: true,
            style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
            decoration: InputDecoration(labelText: tr('Montant reçu en espèces'), suffixText: 'XAF', helperText: tr('Minimum 500')),
            onChanged: (_) => setState(() {}),
          ),
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 20),
          child: SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: false, icon: Icon(Icons.qr_code_scanner), label: Text(tr('QR du client'))),
              ButtonSegment(value: true, icon: Icon(Icons.dialpad), label: Text(tr('Numéro'))),
            ],
            selected: {_byPhone},
            onSelectionChanged: (s) => setState(() => _byPhone = s.first),
          ),
        ),
        if (_pendingRaw != null && !_byPhone)
          Padding(
            padding: EdgeInsets.fromLTRB(20, 10, 20, 0),
            child: ElevatedButton.icon(
              onPressed: ready && !_busy ? () => _onScan(_pendingRaw!) : null,
              icon: Icon(Icons.nfc_rounded),
              label: Text(ready ? 'Créditer le client (code reçu par NFC)' : 'Code client reçu par NFC — saisissez le montant'),
            ),
          ),
        if (ready && !_byPhone && _pendingRaw == null)
          Padding(
            padding: EdgeInsets.fromLTRB(20, 10, 20, 0),
            child: OutlinedButton.icon(onPressed: _busy ? null : _nfc, icon: Icon(Icons.nfc_rounded), label: Text(tr('Lire le téléphone du client (NFC)'))),
          ),
        if (_error != null) Padding(padding: EdgeInsets.fromLTRB(20, 10, 20, 0), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
        SizedBox(height: 12),
        Expanded(
          child: !ready
              ? Center(child: Padding(padding: EdgeInsets.all(24), child: Text(tr('Saisissez d\'abord le montant reçu.'), textAlign: TextAlign.center)))
              : _byPhone
                  ? ListView(padding: EdgeInsets.all(20), children: [
                      FpPhoneField(label: tr('Numéro FlashPay du client'), controller: _phoneCtrl, onChanged: (_) => setState(() {})),
                      SizedBox(height: 16),
                      ElevatedButton(
                        onPressed: _busy || _phoneCtrl.digitCount < 6 ? null : () => _submit(phone: _phoneCtrl.international),
                        child: Text(_busy ? 'Traitement…' : 'Créditer ${fpMoney(_amount!)}'),
                      ),
                      SizedBox(height: 8),
                      Text(tr('Le scan du QR client est plus sûr : il prouve que le client est présent.'), style: TextStyle(fontSize: 12, color: Colors.black45)),
                    ])
                  : Stack(children: [
                      MobileScanner(
                        controller: _scanner,
                        onDetect: (c) {
                          final v = c.barcodes.isEmpty ? null : c.barcodes.first.rawValue;
                          if (v != null && !_busy) _onScan(v);
                        },
                      ),
                      Center(child: Container(width: 240, height: 240, decoration: BoxDecoration(border: Border.all(color: FpColors.orange, width: 4), borderRadius: BorderRadius.circular(24)))),
                      if (_busy) Center(child: CircularProgressIndicator()),
                    ]),
        ),
      ]),
    );
  }
}
