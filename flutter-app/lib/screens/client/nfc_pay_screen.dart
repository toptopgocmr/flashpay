import 'package:flutter/foundation.dart' show kIsWeb, defaultTargetPlatform, TargetPlatform;
import 'package:flutter/material.dart';
import 'package:nfc_manager/nfc_manager.dart';
import '../../config/theme.dart';
import '../../services/nfc_bridge.dart';
import 'qr_scan_screen.dart';
import '../../l10n/l10n.dart';

/// Paiement sans contact : le client approche son téléphone
///  - du TPE ou de l'autocollant NFC du marchand (tag NDEF),
///  - OU du téléphone d'un marchand, d'un agent ou d'un ami FlashPay qui
///    affiche son code (émulation de carte HCE, cf. FpNfc).
/// Le lien lu est traité exactement comme un QR (fpScreenForLink) ; le
/// paiement se confirme ensuite dans l'app avec le PIN.
class NfcPayScreen extends StatefulWidget {
  final bool request;
  const NfcPayScreen({super.key, this.request = false});

  @override
  State<NfcPayScreen> createState() => _NfcPayScreenState();
}

class _NfcPayScreenState extends State<NfcPayScreen> {
  String _state = 'init'; // init | ready | unavailable | error
  String? _message;
  bool _stopped = false;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void dispose() {
    _stop();
    super.dispose();
  }

  void _stop() {
    if (_state == 'ready' && !_stopped) {
      _stopped = true;
      NfcManager.instance.stopSession().catchError((_) {});
    }
  }

  Future<void> _start() async {
    if (kIsWeb) {
      setState(() { _state = 'unavailable'; _message = 'Le sans contact fonctionne sur l\'application Android / iPhone, pas dans le navigateur.'; });
      return;
    }
    // §4.4 — iOS réserve l'émission NFC à Apple Pay : bascule automatique sur le QR
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => QrScanScreen(request: widget.request)));
      });
      return;
    }
    if (!await FpNfc.canRead()) {
      setState(() { _state = 'unavailable'; _message = 'NFC indisponible ou désactivé sur ce téléphone. Activez-le dans les réglages.'; });
      return;
    }
    _stopped = false;
    setState(() { _state = 'ready'; _message = null; });
    NfcManager.instance.startSession(onDiscovered: (NfcTag tag) async {
      final raw = await FpNfc.rawFromTag(tag);
      if (!mounted) return;
      final next = raw == null ? null : fpScreenForLink(context, raw, request: widget.request, viaNfc: true);
      if (next == null) {
        setState(() => _message = 'Aucun code FlashPay lu. Vérifiez que l\'autre téléphone affiche son code, puis réessayez.');
        return;
      }
      _stop();
      Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => next));
    });
  }

  @override
  Widget build(BuildContext context) {
    final ready = _state == 'ready';
    return Scaffold(
      appBar: AppBar(title: Text(tr('Sans contact (NFC)'))),
      body: Center(
        child: Padding(
          padding: EdgeInsets.all(28),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 170,
              height: 170,
              decoration: BoxDecoration(shape: BoxShape.circle, color: ready ? FpColors.rose : Colors.black12),
              child: Icon(Icons.contactless_rounded, size: 96, color: ready ? FpColors.red : Colors.black38),
            ),
            SizedBox(height: 28),
            Text(
              ready ? 'Approchez votre téléphone' : (_state == 'init' ? 'Préparation…' : 'Sans contact indisponible'),
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
            ),
            SizedBox(height: 10),
            Text(
              _message ??
                  'Placez le dos de votre téléphone contre le TPE, l\'autocollant NFC ou le téléphone du marchand, de l\'agent ou de votre ami (il doit afficher son code FlashPay). Vous confirmerez ensuite avec votre PIN.',
              textAlign: TextAlign.center,
              style: TextStyle(color: FpColors.muted),
            ),
            if (_state == 'unavailable' || _state == 'error') ...[
              SizedBox(height: 20),
              ElevatedButton.icon(
                onPressed: () => Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => QrScanScreen(request: widget.request))),
                icon: Icon(Icons.qr_code_scanner),
                label: Text(tr('Utiliser le QR code')),
              ),
            ],
          ]),
        ),
      ),
    );
  }
}
