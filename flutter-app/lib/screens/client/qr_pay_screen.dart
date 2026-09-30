import 'package:flutter/material.dart';
import '../../services/nfc_bridge.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../l10n/l10n.dart';

/// "Mon QR" côté Client : permet de recevoir un paiement P2P en affichant
/// un QR encodant le numéro de téléphone (scanné par un autre client).
class QrPayScreen extends StatelessWidget {
  const QrPayScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final payload = 'flashpay://pay?phone=${user?.phone ?? ''}';

    return Scaffold(
      appBar: AppBar(title: Text(tr('Mon QR Code'))),
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
                child: FpNfcBeacon(payload: payload, child: QrImageView(data: payload, size: 220, foregroundColor: FpColors.navy)),
              ),
              SizedBox(height: 20),
              Text(user?.fullName ?? '', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
              Text(user?.phone ?? '', style: TextStyle(color: Colors.grey)),
              SizedBox(height: 8),
              Text(
                tr('Faites scanner ce QR pour recevoir un paiement.'),
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey, fontSize: 13),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
