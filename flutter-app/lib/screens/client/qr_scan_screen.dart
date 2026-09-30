import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/nfc_bridge.dart';
import 'gifts_screen.dart';
import 'money_request_screen.dart';
import 'pay_merchant_screen.dart';
import 'payment_request_screen.dart';
import 'send_money_screen.dart';
import 'voucher_screen.dart';
import '../../l10n/l10n.dart';

const _countryNames = {
  'CG': 'République du Congo', 'CD': 'RD Congo', 'CM': 'Cameroun', 'GA': 'Gabon', 'TD': 'Tchad',
  'CF': 'Centrafrique', 'GQ': 'Guinée équatoriale', 'SN': 'Sénégal', 'CI': 'Côte d\'Ivoire',
};

/// Écran à ouvrir pour un lien FlashPay lu par QR code OU par NFC :
///  - flashpay://pay?m=FPM-…         paiement marchand
///  - flashpay://pay?phone=242…      envoi à un ami (ou demande, si [request])
///  - flashpay://pay?r=…&s=…         QR dynamique / lien de paiement / session NFC
///  - flashpay://intent?id=…         achat en ligne à confirmer
///  - flashpay://gift?c=…            cadeau
///  - flashpay://agent?a=AG…         retrait d'espèces chez cet agent
///  - https://…/p|checkout|g/…       liens web équivalents
///  - FPM-… / FPO-…                  code marchand saisi
Widget? fpScreenForLink(BuildContext context, String raw, {bool request = false, bool viaNfc = false}) {
  final code = raw.trim();
  final uri = Uri.tryParse(code);
  if (uri != null && uri.scheme == 'flashpay') {
    final m = uri.queryParameters['m'];
    final phone = uri.queryParameters['phone'];
    final r = uri.queryParameters['r'];
    if (r != null && r.isNotEmpty) return PaymentRequestScreen(token: r, signature: uri.queryParameters['s']);
    if (m != null && m.isNotEmpty) return PayMerchantScreen(merchantCode: m, method: viaNfc ? 'nfc' : 'qr');
    if (phone != null && phone.isNotEmpty) {
      final p = '+${phone.replaceAll('+', '')}';
      return request ? RequestMoneyScreen(initialPhone: p) : SendMoneyScreen(initialPhone: p);
    }
    if (uri.host == 'intent' && uri.queryParameters['id'] != null) return PaymentRequestScreen(intentId: uri.queryParameters['id']);
    if (uri.host == 'gift' && uri.queryParameters['c'] != null) return GiftsScreen(claimCode: uri.queryParameters['c']);
    if (uri.host == 'agent') {
      final iso = context.read<SessionProvider>().user?.wallet?.country ?? 'CG';
      return VoucherScreen(channel: 'cash_pickup', country: iso, countryName: _countryNames[iso] ?? iso);
    }
    return null;
  }
  if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http') && uri.pathSegments.length >= 2) {
    final seg = uri.pathSegments;
    final kind = seg[seg.length - 2];
    if (kind == 'p') return PaymentRequestScreen(token: seg.last);
    if (kind == 'checkout') return PaymentRequestScreen(intentId: seg.last);
    if (kind == 'g') return GiftsScreen(claimCode: seg.last);
    return null;
  }
  if (code.startsWith('FPM-') || code.startsWith('FPO-')) return PayMerchantScreen(merchantCode: code, method: viaNfc ? 'nfc' : 'qr');
  return null;
}

/// Scanner FlashPay : QR marchand, QR d'un ami, QR agent, QR dynamique…
///  - [pickOnly] : renvoie simplement le texte lu (Navigator.pop) ;
///  - [request]  : un QR d'ami ouvre « Demander de l'argent » au lieu d'« Envoyer ».
/// Un bouton « NFC » lit le même code en approchant l'autre téléphone.
class QrScanScreen extends StatefulWidget {
  final bool pickOnly;
  final bool request;
  final String? title;
  const QrScanScreen({super.key, this.pickOnly = false, this.request = false, this.title});

  @override
  State<QrScanScreen> createState() => _QrScanScreenState();
}

class _QrScanScreenState extends State<QrScanScreen> {
  final _controller = MobileScannerController();
  bool _handled = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _open(String raw, {bool viaNfc = false}) {
    if (_handled) return;
    if (widget.pickOnly) {
      _handled = true;
      Navigator.pop(context, raw.trim());
      return;
    }
    final next = fpScreenForLink(context, raw, request: widget.request, viaNfc: viaNfc);
    if (next == null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(tr("Ce code n'est pas un code FlashPay."))));
      return;
    }
    _handled = true;
    Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => next));
  }

  Future<void> _nfc() async {
    await _controller.stop();
    if (!mounted) return;
    final raw = await FpNfc.readSheet(context);
    if (!mounted) return;
    if (raw != null) {
      _open(raw, viaNfc: true);
    } else {
      await _controller.start();
    }
  }

  Future<void> _manual() async {
    final ctrl = TextEditingController();
    final code = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Code marchand')),
        content: TextField(controller: ctrl, autofocus: true, textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(hintText: tr('FPM-XXXXXXXXXXXX'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim().toUpperCase()), child: Text(tr('Continuer'))),
        ],
      ),
    );
    if (code != null && code.isNotEmpty) _open(code);
  }

  @override
  Widget build(BuildContext context) {
    final hint = widget.request
        ? 'Visez le QR « Mon code » de votre ami pour lui demander de l\'argent'
        : 'Visez le QR code du marchand, d\'un agent ou d\'un ami FlashPay';
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(widget.title ?? 'Scanner', style: TextStyle(color: Colors.white)),
        actions: [
          IconButton(icon: Icon(Icons.flash_on), onPressed: () => _controller.toggleTorch()),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: (capture) {
              final v = capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue;
              if (v != null) _open(v);
            },
          ),
          Center(
            child: Container(
              width: 250,
              height: 250,
              decoration: BoxDecoration(border: Border.all(color: FpColors.orange, width: 4), borderRadius: BorderRadius.circular(24)),
            ),
          ),
          Positioned(
            left: 24,
            right: 24,
            bottom: 40,
            child: Column(children: [
              Text(tr(hint), textAlign: TextAlign.center, style: TextStyle(color: Colors.white, fontSize: 14)),
              SizedBox(height: 12),
              Wrap(alignment: WrapAlignment.center, spacing: 10, runSpacing: 10, children: [
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: BorderSide(color: Colors.white54)),
                  onPressed: _nfc,
                  icon: Icon(Icons.nfc_rounded),
                  label: Text(tr('NFC')),
                ),
                if (!widget.request)
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: BorderSide(color: Colors.white54)),
                    onPressed: _manual,
                    icon: Icon(Icons.keyboard),
                    label: Text(tr('Code marchand')),
                  ),
              ]),
            ]),
          ),
        ],
      ),
    );
  }
}
