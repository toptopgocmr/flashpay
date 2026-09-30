import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import 'merchant_nfc_tag_screen.dart';
import '../../l10n/l10n.dart';

/// Affiche le QR marchand (qr_code_token) à faire scanner par les clients
/// pour la collecte (§1 "Paiement par QR Code").
class MerchantQrScreen extends StatelessWidget {
  const MerchantQrScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final merchant = context.watch<SessionProvider>().user?.merchant;

    return Scaffold(
      appBar: AppBar(title: Text(tr('Mon QR Code marchand'))),
      body: Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                padding: EdgeInsets.all(20),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), boxShadow: [
                  BoxShadow(color: Colors.black.withOpacity(0.06), blurRadius: 12, offset: Offset(0, 4)),
                ]),
                child: FpNfcBeacon(payload: 'flashpay://pay?m=${merchant?.qrCodeToken ?? ''}', child: QrImageView(data: 'flashpay://pay?m=${merchant?.qrCodeToken ?? ''}', size: 220, foregroundColor: FpColors.navy)),
              ),
              SizedBox(height: 20),
              Text(merchant?.businessName ?? '', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
              Text(merchant?.qrCodeToken ?? '', style: TextStyle(color: Colors.grey, fontSize: 12)),
              SizedBox(height: 16),
              Text(
                tr('Affichez ce QR à votre caisse : vos clients paient depuis FlashPay ou depuis leur mobile money (MTN, Airtel, Orange…), où qu\'ils soient dans la sous-région.'),
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey, fontSize: 13),
              ),
              SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: () => Navigator.push(context, MaterialPageRoute(
                    builder: (_) => MerchantNfcTagScreen(payload: 'flashpay://pay?m=${merchant?.qrCodeToken ?? ''}'))),
                icon: Icon(Icons.contactless_rounded),
                label: Text(tr('Activer le sans contact (tag NFC / TPE)')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
