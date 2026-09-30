import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:nfc_manager/nfc_manager.dart';
import '../../config/theme.dart';
import '../../l10n/l10n.dart';

/// Programme un autocollant NFC (ou la puce NFC d'un TPE) avec le lien de
/// paiement du marchand : les clients paient en approchant leur téléphone.
class MerchantNfcTagScreen extends StatefulWidget {
  final String payload; // flashpay://pay?m=FPM-…
  MerchantNfcTagScreen({super.key, required this.payload});

  @override
  State<MerchantNfcTagScreen> createState() => _MerchantNfcTagScreenState();
}

class _MerchantNfcTagScreenState extends State<MerchantNfcTagScreen> {
  String _status = 'Préparation…';
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void dispose() {
    if (!kIsWeb) NfcManager.instance.stopSession().catchError((_) {});
    super.dispose();
  }

  Future<void> _start() async {
    if (kIsWeb) {
      setState(() => _status = 'La programmation NFC se fait depuis l\'application Android / iPhone.');
      return;
    }
    bool ok = false;
    try {
      ok = await NfcManager.instance.isAvailable();
    } catch (_) {}
    if (!ok) {
      setState(() => _status = 'NFC indisponible ou désactivé sur ce téléphone.');
      return;
    }
    setState(() => _status = 'Approchez l\'autocollant NFC vierge (NTAG213/215) du téléphone.');
    NfcManager.instance.startSession(onDiscovered: (NfcTag tag) async {
      final ndef = Ndef.from(tag);
      if (ndef == null || !ndef.isWritable) {
        await NfcManager.instance.stopSession(errorMessage: 'Tag non inscriptible');
        if (mounted) setState(() => _status = 'Ce tag n\'est pas inscriptible. Utilisez un autocollant NTAG vierge.');
        return;
      }
      try {
        await ndef.write(NdefMessage([NdefRecord.createUri(Uri.parse(widget.payload))]));
        await NfcManager.instance.stopSession(alertMessage: 'Tag FlashPay programmé');
        if (mounted) setState(() { _done = true; _status = 'Tag programmé ! Collez-le sur votre caisse ou votre TPE.'; });
      } catch (e) {
        await NfcManager.instance.stopSession(errorMessage: 'Échec');
        if (mounted) setState(() => _status = 'Échec de l\'écriture : $e');
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Programmer un tag NFC'))),
      body: Center(
        child: Padding(
          padding: EdgeInsets.all(28),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(_done ? Icons.check_circle_rounded : Icons.contactless_rounded, size: 110, color: _done ? FpColors.success : FpColors.navy),
            SizedBox(height: 24),
            Text(_status, textAlign: TextAlign.center, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            SizedBox(height: 12),
            SelectableText(widget.payload, style: TextStyle(color: Colors.black45, fontSize: 12)),
          ]),
        ),
      ),
    );
  }
}
