import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../shared/transaction_status_screen.dart';
import '../../l10n/l10n.dart';

/// Encaisser en scannant le code de paiement du client (style Alipay) :
/// 1. le marchand saisit le montant ; 2. il scanne le QR affiché par le client
/// (ou saisit ses 18 chiffres) ; 3. le wallet du client est débité.
class MerchantScanCodeScreen extends StatefulWidget {
  const MerchantScanCodeScreen({super.key});

  @override
  State<MerchantScanCodeScreen> createState() => _MerchantScanCodeScreenState();
}

class _MerchantScanCodeScreenState extends State<MerchantScanCodeScreen> {
  final _service = PaymentService();
  final _amountCtrl = TextEditingController();
  MobileScannerController? _scanner;
  bool _busy = false;
  String? _error;

  int? get _amount {
    final a = int.tryParse(_amountCtrl.text.replaceAll(RegExp(r'\D'), ''));
    return a != null && a >= 10 ? a : null;
  }

  @override
  void dispose() {
    _scanner?.dispose();
    super.dispose();
  }

  /// flashpay://code?c=88…  ou 18 chiffres saisis
  String? _extract(String raw) {
    final uri = Uri.tryParse(raw.trim());
    if (uri != null && uri.scheme == 'flashpay' && uri.host == 'code') return uri.queryParameters['c'];
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    return digits.length == 18 ? digits : null;
  }

  Future<void> _charge(String raw) async {
    if (_busy) return;
    final code = _extract(raw);
    final amount = _amount;
    if (code == null) {
      setState(() => _error = 'Ce n\'est pas un code de paiement FlashPay.');
      return;
    }
    if (amount == null) return;
    setState(() { _busy = true; _error = null; });
    await _scanner?.stop();
    try {
      final s = await _service.merchantChargeCode(code: code, amount: amount);
      if (!mounted) return;
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => TransactionStatusScreen(initial: s, title: tr('Encaissement'))));
    } catch (e) {
      if (!mounted) return;
      setState(() { _error = apiErrorMessage(e); _busy = false; });
      await _scanner?.start();
    }
  }

  Future<void> _manual() async {
    final ctrl = TextEditingController();
    final code = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Code du client')),
        content: TextField(controller: ctrl, autofocus: true, keyboardType: TextInputType.number,
            decoration: InputDecoration(hintText: tr('18 chiffres'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text), child: Text(tr('Encaisser'))),
        ],
      ),
    );
    if (code != null && code.isNotEmpty) _charge(code);
  }

  @override
  Widget build(BuildContext context) {
    final ready = _amount != null;
    if (ready && _scanner == null) _scanner = MobileScannerController();

    return Scaffold(
      appBar: AppBar(title: Text(tr('Scanner le code client'))),
      body: Column(children: [
        Padding(
          padding: EdgeInsets.all(20),
          child: TextField(
            controller: _amountCtrl,
            keyboardType: TextInputType.number,
            autofocus: true,
            style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
            decoration: InputDecoration(labelText: tr('Montant à encaisser'), suffixText: 'XAF'),
            onChanged: (_) => setState(() {}),
          ),
        ),
        if (_error != null)
          Padding(padding: EdgeInsets.symmetric(horizontal: 20), child: Text(_error!, style: TextStyle(color: FpColors.danger))),
        Expanded(
          child: !ready
              ? Center(child: Text(tr('Saisissez le montant, puis scannez le code du client.'), textAlign: TextAlign.center))
              : Stack(children: [
                  MobileScanner(
                    controller: _scanner,
                    onDetect: (capture) {
                      final v = capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue;
                      if (v != null) _charge(v);
                    },
                  ),
                  Center(
                    child: Container(
                      width: 240,
                      height: 240,
                      decoration: BoxDecoration(border: Border.all(color: FpColors.orange, width: 4), borderRadius: BorderRadius.circular(24)),
                    ),
                  ),
                  if (_busy) Center(child: CircularProgressIndicator()),
                ]),
        ),
        Padding(
          padding: EdgeInsets.all(16),
          child: Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: ready && !_busy ? _manual : null,
                icon: Icon(Icons.keyboard),
                label: Text(tr('Saisir le code')),
              ),
            ),
            SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: ready && !_busy
                    ? () async {
                        await _scanner?.stop();
                        if (!context.mounted) return;
                        final raw = await FpNfc.readSheet(context, hint: 'Le client affiche son code de paiement et approche son téléphone.');
                        if (raw != null) {
                          _charge(raw);
                        } else {
                          await _scanner?.start();
                        }
                      }
                    : null,
                icon: Icon(Icons.nfc_rounded),
                label: Text(tr('NFC')),
              ),
            ),
          ]),
        ),
      ]),
    );
  }
}
